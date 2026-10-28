<?php

namespace Tests\Feature\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Authorization\GuardedToolExecutor;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanChangeKind;
use App\Services\Ai\Authorization\PlanLoadGuard;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\PlanReplacer;
use App\Services\Ai\Authorization\ProposalMatcher;
use App\Services\Ai\Authorization\ToolAuthorization;
use App\Services\Ai\Authorization\ToolCapability;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\PlanReplacementNotAuthorized;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\AiTool;
use App\Services\Tools\CreateWorkoutPlanTool;
use App\Services\Tools\ReplacementGrant;
use App\Services\Tools\ToolRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * One policy for every way a plan can be replaced, whoever asks and however it is asked:
 *
 *   1. a day with nothing planned is simply written;
 *   2. a day with an untouched plan is replaced only on the user's exact authorization: their confirmation of the exact
 *      replacement, a replacement they asked for outright, or their own form or Weekly Plan page; a request for a
 *      session, a report of an activity or "update my plan" is not that;
 *   3. a targeted adjustment of the same exercises is covered by a request to change the plan;
 *   4. a started, partly done, completed or performed day is never replaced.
 *
 * It is enforced where the write happens (WorkoutPlanService refuses to replace without a grant) and where a chat
 * tool call is authorized (ToolTurn), not by what the model is told.
 */
class PlanReplacementPolicyTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function day(int $index): string
    {
        return Carbon::now('America/Bogota')->startOfWeek(Carbon::MONDAY)->addDays($index)->toDateString();
    }

    private function plan(string $date, string $title = 'Old plan', array $exercises = [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]], string $type = 'strength', ?User $user = null): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($user ?? $this->user, [
            'planned_date' => $date, 'activity_type' => $type, 'title' => $title, 'exercises' => $exercises,
        ], replaceExisting: true);
    }

    private function planOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    private function createCall(string $title, ?string $date = null, ?array $exercises = null, string $type = 'strength', array $extra = []): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => $type, 'title' => $title, 'decision_summary' => 'As asked.', 'reasoning_factors' => ['Because'],
            'planned_date' => $date, 'exercises' => $exercises ?? [['exercise_name' => 'Lunges', 'planned_sets' => 3, 'planned_reps' => 12]],
        ] + $extra))]);
    }

    private function weeklyCall(array $changes): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'Adapted the week.', 'reasoning_factors' => ['Soccer yesterday'], 'changes' => $changes,
        ])]);
    }

    /** @return array{answer: string, calls: int, message: Message, conversation: int} */
    private function say(string $message, ?int $conversation, AiResponse ...$responses): array
    {
        if ($conversation === null) {
            Cache::flush();
        }

        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'], 'calls' => count($this->fake->calls) - $before, 'conversation' => $result['conversation'],
            'message' => Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function pending(Message $message): ?array
    {
        return $message->meta[PendingProposalStore::REPLACEMENT_META_KEY] ?? null;
    }

    private function revision(string $date): string
    {
        return $this->planOn($date)->revision();
    }

    // ---- the write itself ----------------------------------------------------------------------------------------------

    public function test_the_write_refuses_to_replace_a_plan_unless_the_caller_says_the_user_authorized_it(): void
    {
        $service = app(WorkoutPlanService::class);
        $service->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'First']);

        try {
            $service->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Second']);
            $this->fail('Replacing without authorization must be refused.');
        } catch (PlanReplacementNotAuthorized $e) {
            $this->assertSame($this->today, $e->date);
        }

        $this->assertSame('First', $this->planOn($this->today)->title);

        $service->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Second'], replaceExisting: true);
        $this->assertSame('Second', $this->planOn($this->today)->title);
        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->count());
    }

    public function test_a_day_with_nothing_planned_needs_no_authorization(): void
    {
        $plan = app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'New']);

        $this->assertSame('New', $plan->title);
    }

    public function test_a_started_partial_completed_or_performed_day_is_never_replaced_even_when_authorized(): void
    {
        foreach ([WorkoutPlan::STATUS_IN_PROGRESS, WorkoutPlan::STATUS_PARTIAL, WorkoutPlan::STATUS_COMPLETED] as $status) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $this->plan($this->today)->update(['status' => $status]);
            $before = $this->revision($this->today);

            try {
                app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Intruder'], replaceExisting: true);
                $this->fail("A {$status} plan must not be replaced.");
            } catch (ValidationException) {
            }

            $this->assertSame($before, $this->revision($this->today), $status);
        }

        WorkoutPlan::where('user_id', $this->user->id)->delete();
        $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $this->expectException(ValidationException::class);
        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Intruder'], replaceExisting: true);
    }

    public function test_the_tool_cannot_replace_a_plan_without_the_grant_the_chat_boundary_sets(): void
    {
        $this->plan($this->today);
        $before = $this->revision($this->today);

        $result = app(CreateWorkoutPlanTool::class)->execute([
            'planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Sneaky', 'decision_summary' => 'x', 'reasoning_factors' => ['y'],
        ], $this->user);

        $this->assertStringContainsString('has not authorized replacing it', $result['error']);
        $this->assertSame($before, $this->revision($this->today), 'A plan that appears after the check is still not overwritten.');
    }

    public function test_the_service_only_replaces_a_users_own_plan(): void
    {
        $other = $this->newUser();
        $theirs = $this->plan($this->today, 'Theirs', user: $other);

        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Mine']);

        $this->assertSame('Theirs', $theirs->fresh()->title);
        $this->assertSame(2, WorkoutPlan::whereDate('planned_date', $this->today)->count());
    }

    // ---- what kind of change it is ----------------------------------------------------------------------------------------

    public function test_a_change_is_none_targeted_or_a_replacement(): void
    {
        $plan = $this->plan($this->today, 'Upper', [
            ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40],
            ['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10],
        ])->load('exercises');
        $same = fn (array $over = []) => $over + [
            'activity_type' => 'strength', 'title' => 'Upper', 'duration_minutes' => null,
            'exercises' => [
                ['exercise_name' => 'bench press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40],
                ['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10],
            ],
        ];

        $this->assertSame(PlanChangeKind::NONE, PlanChangeKind::of($plan, $same()));
        $this->assertSame(PlanChangeKind::TARGETED, PlanChangeKind::of($plan, $same(['title' => 'Upper (lighter)'])));

        $heavier = $same();
        $heavier['exercises'][0]['planned_weight_kg'] = 45;
        $this->assertSame(PlanChangeKind::TARGETED, PlanChangeKind::of($plan, $heavier));

        $this->assertSame(PlanChangeKind::REPLACEMENT, PlanChangeKind::of($plan, $same(['activity_type' => 'recovery'])));
        $this->assertSame(PlanChangeKind::REPLACEMENT, PlanChangeKind::of($plan, $same(['exercises' => []])));
        $this->assertSame(PlanChangeKind::REPLACEMENT, PlanChangeKind::of($plan, $same(['exercises' => [['exercise_name' => 'Squats']] + $same()['exercises']])));

        $swapped = $same();
        $swapped['exercises'][1]['exercise_name'] = 'Pull-ups';
        $this->assertSame(PlanChangeKind::REPLACEMENT, PlanChangeKind::of($plan, $swapped));

        $reordered = $same();
        $reordered['exercises'] = array_reverse($reordered['exercises']);
        $this->assertSame(PlanChangeKind::REPLACEMENT, PlanChangeKind::of($plan, $reordered));
    }

    // ---- ordinary chat: create_workout_plan -------------------------------------------------------------------------------------

    public function test_a_request_for_a_session_on_an_empty_day_is_written(): void
    {
        $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: 'Here is your legs session.'));

        $this->assertSame('Legs', $this->planOn($this->today)->title);
    }

    public function test_a_request_for_a_session_does_not_replace_the_plan_that_is_already_there(): void
    {
        $this->plan($this->today);
        $before = $this->revision($this->today);

        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: "Here's a legs session: lunges 3x12. I've put it on your plan."));

        $this->assertSame($before, $this->revision($this->today), 'Nothing was written.');
        $this->assertStringContainsString("Here's a legs session", $turn['answer'], 'The workout the user asked for is still shown.');
        $this->assertStringContainsString("I haven't changed your plan. {$this->today} already has “Old plan” (Squats 3×10)", $turn['answer'], 'And the application says what has not happened, whatever the model claimed.');
        $this->assertStringContainsString('replace it with “Legs” (Lunges 3×12)', $turn['answer']);
        $this->assertNotNull($this->pending($turn['message']));
    }

    public function test_yes_then_makes_exactly_that_replacement_without_the_model(): void
    {
        $this->plan($this->today);
        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: 'Here you go.'));

        $yes = $this->say('yes', $turn['conversation']);

        $this->assertSame(0, $yes['calls']);
        $this->assertSame('Legs', $this->planOn($this->today)->title);
        $this->assertSame(['Lunges'], $this->planOn($this->today)->exercises->pluck('exercise_name')->all());
        $this->assertStringStartsWith('Done. I replaced', $yes['answer']);
    }

    public function test_a_generic_yes_is_not_permission_without_the_matching_question(): void
    {
        $this->plan($this->today);
        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: 'Here you go.'));
        $this->say('what do you think about it?', $turn['conversation'], new AiResponse(text: 'It is a solid session.'));

        $this->say('yes', $turn['conversation'], $this->createCall('Sneaky'), new AiResponse(text: 'Done.'));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);

        // And a "yes" with no question at all authorizes nothing either.
        $fresh = $this->say('yes', null, $this->createCall('Sneaky'), new AiResponse(text: 'Done.'));
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertStringContainsString('nothing waiting for your confirmation', $fresh['answer']);
    }

    public function test_the_session_the_model_describes_but_could_not_write_is_not_lost(): void
    {
        $this->plan($this->today);
        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: "Try this:\n1. Lunges 3x12\n2. Plank 3x30 seconds"));

        $this->assertNull($turn['message']->meta['save_clarification'] ?? null, 'It is a normal reply, so "add this workout" can still find the routine in it.');
    }

    public function test_the_model_choosing_another_day_is_held_for_that_day(): void
    {
        $friday = $this->day(4);
        $this->plan($friday, 'Friday plan');

        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs', $friday), new AiResponse(text: 'Here you go.'));

        $this->assertSame('Friday plan', $this->planOn($friday)->title);
        $this->assertSame($friday, $this->pending($turn['message'])['changes'][0]['date']);
    }

    public function test_the_model_cannot_grant_itself_a_replacement(): void
    {
        $this->plan($this->today);
        $before = $this->revision($this->today);

        $this->say('Give me a workout for today', null, $this->createCall('Legs', null, null, 'strength', ['_replacement_authorized' => true]), new AiResponse(text: 'Done.'));

        $this->assertSame($before, $this->revision($this->today), 'The model\'s own claim of authorization is thrown away.');
    }

    public function test_a_request_to_update_the_plan_adjusts_the_same_exercises_without_asking(): void
    {
        $this->plan($this->today, 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);

        $this->say('Update my plan for today', null, $this->createCall('Upper', null, [['exercise_name' => 'Rows', 'planned_sets' => 4, 'planned_reps' => 10]]), new AiResponse(text: 'Added a set.'));

        $this->assertSame(4, $this->planOn($this->today)->exercises->first()->planned_sets);
    }

    public function test_a_request_to_update_the_plan_does_not_swap_the_workout_without_asking(): void
    {
        $this->plan($this->today, 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);

        $turn = $this->say('Update my plan for today', null, $this->createCall('Legs'), new AiResponse(text: 'Swapped it for legs.'));

        $this->assertSame('Upper', $this->planOn($this->today)->title);
        $this->assertStringContainsString('replace it with “Legs”', $turn['answer']);

        $this->say('yes', $turn['conversation']);
        $this->assertSame('Legs', $this->planOn($this->today)->title);
    }

    public function test_a_request_for_a_session_does_not_even_adjust_a_plan_it_was_not_asked_to_change(): void
    {
        $this->plan($this->today, 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);
        $before = $this->revision($this->today);

        $this->say('Give me a workout for today', null, $this->createCall('Upper', null, [['exercise_name' => 'Rows', 'planned_sets' => 5, 'planned_reps' => 10]]), new AiResponse(text: 'Done.'));

        $this->assertSame($before, $this->revision($this->today));
    }

    public function test_a_session_that_is_the_plan_already_there_changes_nothing_and_asks_nothing(): void
    {
        $this->plan($this->today, 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);

        $turn = $this->say('Give me a workout for today', null, $this->createCall('Upper', null, [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]), new AiResponse(text: 'You already have it planned.'));

        $this->assertNull($this->pending($turn['message']));
        $this->assertSame('You already have it planned.', $turn['answer']);
    }

    public function test_a_started_or_performed_day_is_refused_and_nothing_is_asked(): void
    {
        $this->plan($this->today)->update(['status' => WorkoutPlan::STATUS_IN_PROGRESS]);
        $before = $this->revision($this->today);

        $turn = $this->say('Update my plan for today', null, $this->createCall('Legs'), new AiResponse(text: 'Replaced it.'));

        $this->assertSame($before, $this->revision($this->today));
        $this->assertNull($this->pending($turn['message']));
        $this->assertStringContainsString('already been started or finished', json_encode($turn['message']->meta['tool_calls'][0]['result']));
    }

    public function test_a_held_replacement_is_held_to_the_load_rules(): void
    {
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->plan($this->today);

        // 60 kg on bench is over the limit, and 24 kg goblet squats have no baseline at all.
        $over = $this->say('Update my plan for today', null, $this->createCall('Heavy', null, [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 60]]), new AiResponse(text: 'Done.'));
        $this->assertNull($this->pending($over['message']), 'Nothing is offered for confirmation that the rules would refuse.');
        $this->assertSame('Old plan', $this->planOn($this->today)->title);

        $unbased = $this->say('Update my plan for today', null, $this->createCall('Goblet', null, [['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24]]), new AiResponse(text: 'Done.'));
        $this->assertNull($this->pending($unbased['message'])['changes'][0]['replacement']['exercises'][0]['planned_weight_kg']);
        $this->say('yes', $unbased['conversation']);
        $this->assertNull($this->planOn($this->today)->exercises->first()->planned_weight_kg);
    }

    // ---- weekly adaptation through chat ---------------------------------------------------------------------------------------------

    public function test_a_weekly_adaptation_still_writes_days_with_nothing_planned(): void
    {
        $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'Soccer'],
        ]), new AiResponse(text: 'Added mobility on Thursday.'));

        $this->assertSame('Mobility', $this->planOn($this->day(3))->title);
    }

    public function test_an_activity_report_does_not_replace_a_planned_day_but_the_rest_of_the_batch_goes_ahead(): void
    {
        $this->plan($this->day(4), 'Legs');
        $legs = $this->revision($this->day(4));

        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'Soccer'],
            ['date' => $this->day(4), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'Soccer'],
        ]), new AiResponse(text: 'Eased Thursday and Friday.'));

        $this->assertSame('Mobility', $this->planOn($this->day(3))->title, 'The empty day was written.');
        $this->assertSame($legs, $this->revision($this->day(4)), 'The planned day was not.');
        $this->assertStringContainsString($this->day(4).' already has “Legs”', $turn['answer']);
        $this->assertSame([$this->day(4)], array_column($this->pending($turn['message'])['changes'], 'date'));
        $this->assertSame([$this->day(4)], $turn['message']->meta['tool_calls'][0]['result']['held_for_confirmation']);
    }

    public function test_several_days_are_one_question_and_one_all_or_nothing_confirmation(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $this->plan($this->day(4), 'Friday plan');

        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'Soccer'],
            ['date' => $this->day(4), 'action' => 'clear', 'reason' => 'Rest after soccer'],
        ]), new AiResponse(text: 'Done.'));

        $this->assertStringContainsString('Do you want me to make all of these changes?', $turn['answer']);
        $this->assertStringContainsString($this->day(3).': “Thursday plan” (Squats 3×10) would be replaced with “Mobility”', $turn['answer']);
        $this->assertStringContainsString($this->day(4).': “Friday plan” (Squats 3×10) would become a rest day', $turn['answer']);
        $this->assertSame('Thursday plan', $this->planOn($this->day(3))->title);
        $this->assertSame('Friday plan', $this->planOn($this->day(4))->title);

        $this->say('yes', $turn['conversation']);

        $this->assertSame('Mobility', $this->planOn($this->day(3))->title);
        $this->assertSame('Rest', $this->planOn($this->day(4))->title);
        $this->assertSame('skipped', $this->planOn($this->day(4))->status);
        $this->assertTrue($this->planOn($this->day(4))->isRestDay());
    }

    public function test_if_any_one_day_changed_after_the_question_none_are_changed(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $this->plan($this->day(4), 'Friday plan');
        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'Soccer'],
            ['date' => $this->day(4), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'Soccer'],
        ]), new AiResponse(text: 'Done.'));

        $this->planOn($this->day(4))->exercises->first()->update(['planned_reps' => 12]);

        $yes = $this->say('yes', $turn['conversation']);

        $this->assertSame('Thursday plan', $this->planOn($this->day(3))->title, 'All or nothing.');
        $this->assertSame('Friday plan', $this->planOn($this->day(4))->title);
        $this->assertStringContainsString('changed after I asked', $yes['answer']);
    }

    public function test_a_targeted_adjustment_the_user_asked_for_is_applied_with_the_rest_of_a_weekly_change(): void
    {
        $this->plan($this->day(3), 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);

        $this->say('Update my plan: make Thursday a bit easier', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Upper', 'exercises' => [['exercise_name' => 'Rows', 'planned_sets' => 2, 'planned_reps' => 10]], 'reason' => 'Easier'],
        ]), new AiResponse(text: 'Eased Thursday.'));

        $this->assertSame(2, $this->planOn($this->day(3))->exercises->first()->planned_sets);
    }

    public function test_the_same_adjustment_from_an_activity_report_is_asked_about(): void
    {
        $this->plan($this->day(3), 'Upper', [['exercise_name' => 'Rows', 'planned_sets' => 3, 'planned_reps' => 10]]);

        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Upper', 'exercises' => [['exercise_name' => 'Rows', 'planned_sets' => 2, 'planned_reps' => 10]], 'reason' => 'Easier'],
        ]), new AiResponse(text: 'Eased Thursday.'));

        $this->assertSame(3, $this->planOn($this->day(3))->exercises->first()->planned_sets);
        $this->assertNotNull($this->pending($turn['message']));
    }

    public function test_started_and_completed_days_are_skipped_not_asked_about(): void
    {
        $this->plan($this->day(3), 'Done already')->update(['status' => WorkoutPlan::STATUS_COMPLETED]);
        $this->plan($this->day(4), 'Started')->update(['status' => WorkoutPlan::STATUS_IN_PROGRESS]);
        $completed = $this->revision($this->day(3));

        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'Soccer'],
            ['date' => $this->day(4), 'action' => 'clear', 'reason' => 'Soccer'],
        ]), new AiResponse(text: 'Done.'));

        $result = $turn['message']->meta['tool_calls'][0]['result'];
        $this->assertSame([['date' => $this->day(3), 'reason' => 'already_completed'], ['date' => $this->day(4), 'reason' => 'already_started']], $result['skipped']);
        $this->assertSame($completed, $this->revision($this->day(3)));
        $this->assertSame('Started', $this->planOn($this->day(4))->title);
        $this->assertNull($this->pending($turn['message']));
    }

    public function test_an_invalid_batch_holds_nothing_and_changes_nothing(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $before = $this->revision($this->day(3));

        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'Soccer'],
            ['date' => '2000-01-01', 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Outside the week', 'reason' => 'x'],
        ]), new AiResponse(text: 'Done.'));

        $this->assertSame($before, $this->revision($this->day(3)));
        $this->assertNull($this->pending($turn['message']));
        $this->assertArrayHasKey('error', $turn['message']->meta['tool_calls'][0]['result']);
    }

    public function test_the_weekly_plan_page_keeps_adapting_the_week_on_the_users_own_request(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $this->fake->queue($this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'I have soccer Thursday'],
        ]), new AiResponse(text: 'Moved Thursday to soccer.'));

        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer Thursday'])->assertOk();

        $this->assertSame('Soccer', $this->planOn($this->day(3))->title, 'A person using the Weekly Plan adaptation box is asking for exactly this.');
    }

    // ---- direct calls to the weekly service ------------------------------------------------------------------------------------------

    public function test_applying_weekly_changes_directly_replaces_only_what_it_is_told_the_user_authorized(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $this->plan($this->day(4), 'Friday plan');
        $changes = [
            ['date' => $this->day(2), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x'],
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'x'],
            ['date' => $this->day(4), 'action' => 'clear', 'reason' => 'x'],
        ];

        $none = app(WeeklyPlanService::class)->applyChanges($this->user, $changes);
        $this->assertSame([$this->day(2)], $none['applied']);
        $this->assertSame([['date' => $this->day(3), 'reason' => 'needs_confirmation'], ['date' => $this->day(4), 'reason' => 'needs_confirmation']], $none['skipped']);
        $this->assertSame('Thursday plan', $this->planOn($this->day(3))->title);

        $one = app(WeeklyPlanService::class)->applyChanges($this->user, $changes, [], [$this->day(3)]);
        $this->assertSame('Mobility', $this->planOn($this->day(3))->title);
        $this->assertSame('Friday plan', $this->planOn($this->day(4))->title);
        // Wednesday's walk was written by the first call, so it is now a plan like any other.
        $this->assertSame([['date' => $this->day(2), 'reason' => 'needs_confirmation'], ['date' => $this->day(4), 'reason' => 'needs_confirmation']], $one['skipped']);

        app(WeeklyPlanService::class)->applyChanges($this->user, $changes, [], true);
        $this->assertSame('Rest', $this->planOn($this->day(4))->title);
    }

    // ---- stale, concurrent and replayed ----------------------------------------------------------------------------------------------

    public function test_a_confirmation_applied_twice_changes_things_once(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $turn = $this->say('Give me a workout for Thursday', null, $this->createCall('Legs', $this->day(3)), new AiResponse(text: 'Here.'));
        $conversation = Conversation::findOrFail($turn['conversation']);
        $store = app(PendingProposalStore::class);

        $first = $store->claimReplacement($conversation, $this->user, $turn['message']);
        $second = $store->claimReplacement($conversation, $this->user, $turn['message']);
        $this->assertNotNull($first);
        $this->assertNull($second, 'Two confirmations racing: exactly one is let through.');

        $replacer = app(PlanReplacer::class);
        $this->assertIsArray($replacer->apply($this->user, $first));
        $this->planOn($this->day(3))->update(['title' => 'Edited since']);
        $this->assertIsString($replacer->apply($this->user, $first), 'Even the same proposal applied again finds the day changed.');
        $this->assertSame('Edited since', $this->planOn($this->day(3))->title);
    }

    public function test_a_proposal_for_one_user_cannot_be_applied_to_another(): void
    {
        $this->plan($this->today);
        $turn = $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: 'Here.'));
        $proposal = PlanReplacementProposal::fromMeta($this->pending($turn['message']));

        $other = $this->newUser();
        $theirs = $this->plan($this->today, 'Theirs', user: $other);

        $result = app(PlanReplacer::class)->apply($other, $proposal);

        $this->assertIsString($result, 'Their plan is not the one the proposal names.');
        $this->assertSame('Theirs', $theirs->fresh()->title);
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
    }

    public function test_performance_logged_after_the_question_blocks_every_day_of_the_confirmation(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $this->plan($this->day(4), 'Friday plan');
        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'x'],
            ['date' => $this->day(4), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x'],
        ]), new AiResponse(text: 'Done.'));

        $friday = $this->planOn($this->day(4));
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/workout-plans/{$friday->id}/exercises/{$friday->exercises[0]->id}/performance", ['sets' => [['reps' => 10, 'weight_kg' => 20]]])
            ->assertOk();

        $this->say('yes', $turn['conversation']);

        $this->assertSame('Thursday plan', $this->planOn($this->day(3))->title);
        $this->assertSame('Friday plan', $this->planOn($this->day(4))->title);
    }

    public function test_the_signature_covers_every_day_and_action(): void
    {
        $this->plan($this->day(3), 'Thursday plan');
        $turn = $this->say('I played soccer yesterday', null, $this->weeklyCall([
            ['date' => $this->day(3), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'x'],
        ]), new AiResponse(text: 'Done.'));
        $meta = $this->pending($turn['message']);

        $this->assertTrue(PlanReplacementProposal::fromMeta($meta)->isAuthentic());

        $forged = $meta;
        $forged['changes'][] = ['date' => $this->day(5), 'existing_plan_id' => 999, 'existing_revision' => 'x', 'existing_summary' => 'x', 'action' => 'clear', 'reason' => '', 'replacement' => null];
        $this->assertFalse(PlanReplacementProposal::fromMeta($forged)->isAuthentic(), 'A day added to the list invalidates the signature.');

        $forged = $meta;
        $forged['changes'][0]['action'] = 'clear';
        $forged['changes'][0]['replacement'] = null;
        $this->assertFalse(PlanReplacementProposal::fromMeta($forged)->isAuthentic());
    }

    public function test_one_users_pending_replacement_never_reaches_another_users_plan(): void
    {
        $this->plan($this->today);
        $bob = $this->newUser();
        $bobPlan = $this->plan($this->today, 'Bob plan', user: $bob);

        $this->say('Give me a workout for today', null, $this->createCall('Legs'), new AiResponse(text: 'Here.'));

        $this->assertSame('Bob plan', $bobPlan->fresh()->title);
        $this->assertSame($bobPlan->revision(), $bobPlan->fresh()->revision());
    }

    // ---- the grant itself, and races ----------------------------------------------------------------------------------------------

    public function test_a_grant_covers_only_the_days_it_names(): void
    {
        $this->assertFalse(ReplacementGrant::permits([], '2026-10-28'));
        $this->assertFalse(ReplacementGrant::permits([ReplacementGrant::KEY => ['2026-10-29']], '2026-10-28'));
        $this->assertFalse(ReplacementGrant::permits([ReplacementGrant::KEY => 'yes'], '2026-10-28'));
        $this->assertTrue(ReplacementGrant::permits([ReplacementGrant::KEY => ['2026-10-28']], '2026-10-28'));
        $this->assertTrue(ReplacementGrant::permits([ReplacementGrant::KEY => true], '2026-10-28'));
        $this->assertSame([], ReplacementGrant::dates([ReplacementGrant::KEY => 'everything']));
        $this->assertArrayNotHasKey(ReplacementGrant::KEY, ReplacementGrant::strip([ReplacementGrant::KEY => true, 'a' => 1]));
    }

    public function test_a_plan_that_appears_while_the_call_is_in_flight_is_not_overwritten_even_if_the_model_claims_authorization(): void
    {
        $user = $this->user;
        $plans = app(WorkoutPlanService::class);

        // The day is empty when the call is authorized; another request fills it before the write.
        $racing = new class($plans) implements AiTool
        {
            public function __construct(private WorkoutPlanService $plans) {}

            public function name(): string
            {
                return 'create_workout_plan';
            }

            public function description(): string
            {
                return 'x';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $arguments, User $user): mixed
            {
                $this->plans->createOrReplace($user, ['planned_date' => $arguments['planned_date'], 'activity_type' => 'strength', 'title' => 'Concurrent'], replaceExisting: true);

                return app(CreateWorkoutPlanTool::class)->execute($arguments, $user);
            }
        };

        $turn = (new GuardedToolExecutor(new ToolRegistry([$racing]), app(PlanLoadGuard::class), app(ProposalMatcher::class), app(WeeklyPlanService::class)))
            ->begin(new ToolAuthorization([ToolCapability::PlanCreate], 'session_request'), 1);

        $result = $turn->call('create_workout_plan', [
            'planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Mine', 'decision_summary' => 'x', 'reasoning_factors' => ['y'],
            ReplacementGrant::KEY => true,
        ], $user);

        $this->assertStringContainsString('has not authorized replacing it', $result['error']);
        $this->assertSame('Concurrent', $this->planOn($this->today)->title);
    }

    public function test_a_replacement_held_for_confirmation_gets_the_strictest_load_rules_even_when_the_request_allowed_more(): void
    {
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->plan($this->today);

        // "Update my plan" lets a change add up to the permitted maximum; a confirmation of a swapped workout does not.
        $turn = $this->say('Update my plan for today', null, $this->createCall('Chest', null, [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 42]]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->pending($turn['message']));
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
    }
}
