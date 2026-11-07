<?php

namespace Tests\Feature\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\PlanReplacer;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Production: the user asked the coach for a plan for the rest of the week; the coach answered with Saturday and Sunday
 * and said "just say 'add this plan to my dashboard'"; the user did, and the application answered "that workout looks
 * unfinished". The multi-day plan had been read as ONE workout, and "the rest of the week" is one of the phrases that
 * mark a workout as cut off.
 *
 * These replay that conversation, turn by turn, through the real endpoint with a scripted model, and check that a plan of
 * several days is saved whole or not at all, on exactly the days shown, under every protection a single workout has.
 */
class WeekPlanSaveTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    /** The week is Mon 26 Oct - Sun 1 Nov 2026; "today" is Thursday the 29th. */
    private const SATURDAY = '2026-10-31';

    private const SUNDAY = '2026-11-01';

    private const PLAN = "Here is a plan for the rest of the week:\n\n**Saturday: Full Body Strength**\n* Goblet Squats: 3 sets of 10-12 reps\n* Push-ups: 3 sets of 8-12 reps\n* Plank: 3 sets of 30 seconds\n\n**Sunday: Active Recovery**\n* Easy walk: 30 minutes\n* Light stretching: 10 minutes\n\nIf this plan looks good to you, just say \"add this plan to my dashboard\" and I'll update it for Saturday and Sunday!";

    private const PLAN_WITH_REST = "Here is your weekend:\n\n**Saturday: Lower Body**\n1. Goblet Squats 3x10\n2. Plank 3 x 30 seconds\n\n**Sunday: Rest day**\nTake it easy and let your body recover.\n\nJust say \"add this plan to my dashboard\" and I'll add it!";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function saturday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Full Body Strength', 'reason' => 'As in the plan you were shown',
            'exercises' => [
                ['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 11],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ];
    }

    private function sunday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'As in the plan you were shown',
            'exercises' => [
                ['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800],
                ['exercise_name' => 'Light stretching', 'planned_duration_seconds' => 600],
            ],
        ];
    }

    private function restSunday(): array
    {
        return ['date' => self::SUNDAY, 'action' => 'clear', 'reason' => 'Rest day, as in the plan'];
    }

    private function weekCall(array $changes): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'The plan you were shown.', 'reasoning_factors' => ['You asked to add this plan'], 'changes' => $changes,
        ])]);
    }

    private function conversationWith(string $reply, string $request = 'create a plan for the remainder of the week', ?User $as = null): int
    {
        $user = $as ?? $this->user;
        $conversation = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        Message::create(['conversation_id' => $conversation, 'role' => 'user', 'content' => $request, 'meta' => null]);
        Message::create(['conversation_id' => $conversation, 'role' => 'assistant', 'content' => $reply, 'meta' => null]);

        return $conversation;
    }

    /** @return array{answer: string, calls: int, message: Message, conversation: int} */
    private function say(string $message, int $conversation, ?User $as = null, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($as ?? $this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'], 'calls' => count($this->fake->calls) - $before, 'conversation' => $conversation,
            'message' => Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function plansOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::with('exercises')->where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    private function plans(?User $user = null): int
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function existing(string $date, string $title = 'My own session', string $exercise = 'Bench Press'): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $date, 'activity_type' => 'strength', 'title' => $title, 'source' => 'manual',
            'exercises' => [['exercise_name' => $exercise, 'planned_sets' => 3, 'planned_reps' => 8]],
        ]);
    }

    private function pending(Message $message): array
    {
        return $message->fresh()->meta[PendingProposalStore::REPLACEMENT_META_KEY];
    }

    // ---- the production conversation ---------------------------------------------------------------------------------------

    public function test_the_production_conversation_saves_both_days(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Done, both days are on your dashboard!'));

        $saturday = $this->plansOn(self::SATURDAY);
        $sunday = $this->plansOn(self::SUNDAY);
        $this->assertNotNull($saturday);
        $this->assertNotNull($sunday);
        $this->assertSame(['Goblet Squats', 'Push-ups', 'Plank'], $saturday->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame(['Easy walk', 'Light stretching'], $sunday->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame(2, $this->plans(), 'Exactly the two days shown, no other day.');
        $this->assertNull($this->plansOn('2026-10-29'), 'Not today.');

        $this->assertStringStartsWith('Done. I added this to your plan, all together, and checked that it is saved:', $turn['answer']);
        $this->assertStringContainsString('Saturday, Oct 31', $turn['answer']);
        $this->assertStringContainsString('Sunday, Nov 1', $turn['answer']);
        $this->assertStringNotContainsString('unfinished', $turn['answer']);
        $this->assertStringNotContainsString('both days are on your dashboard', $turn['answer'], 'The application speaks for what was saved, not the model.');
        $this->assertSame('applied', $turn['message']->meta['plan_replacement']);
    }

    public function test_ranges_in_the_plan_are_stored_as_their_low_end_and_the_user_is_told(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Done.'));

        $saturday = $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->values();
        $this->assertSame([3, 10, null], [$saturday[0]->planned_sets, $saturday[0]->planned_reps, $saturday[0]->planned_duration_seconds], '10-12 reps stored as 10, whatever the model sent.');
        $this->assertSame([3, 8, null], [$saturday[1]->planned_sets, $saturday[1]->planned_reps, $saturday[1]->planned_duration_seconds], '8-12 reps stored as 8.');
        $this->assertSame([3, null, 30], [$saturday[2]->planned_sets, $saturday[2]->planned_reps, $saturday[2]->planned_duration_seconds], '30 seconds is exact.');
        $this->assertStringContainsString('I saved the low end of every range', $turn['answer']);
        $this->assertStringContainsString('Goblet Squats 10 reps (written 10-12)', $turn['answer']);
    }

    public function test_a_rest_day_is_saved_as_a_rest_day_and_no_workout_is_invented(): void
    {
        $conversation = $this->conversationWith(self::PLAN_WITH_REST);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([
            $this->saturday(['title' => 'Lower Body', 'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30]]]),
            $this->restSunday(),
        ]), new AiResponse(text: 'Saved.'));

        $this->assertTrue($this->plansOn(self::SUNDAY)->isRestDay());
        $this->assertSame(0, $this->plansOn(self::SUNDAY)->exercises->count());
        $this->assertSame(2, $this->plansOn(self::SATURDAY)->exercises->count());
        $this->assertStringContainsString('Sunday, Nov 1: rest day', $turn['answer']);
    }

    public function test_a_rest_day_cannot_be_written_as_a_workout_and_a_workout_cannot_be_a_rest_day(): void
    {
        $conversation = $this->conversationWith(self::PLAN_WITH_REST);
        $lower = $this->saturday(['title' => 'Lower Body', 'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30]]]);

        $this->say('add this plan to my dashboard', $conversation, null,
            $this->weekCall([$lower, $this->sunday(['title' => 'Walk'])]),
            $this->weekCall([['date' => self::SATURDAY, 'action' => 'clear', 'reason' => 'x'], $this->restSunday()]),
            new AiResponse(text: 'Saved.'),
        );

        $this->assertSame(0, $this->plans(), 'Both calls were outside what the plan says.');
    }

    public function test_a_plan_with_a_workout_day_and_a_recovery_day_saves_both(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));

        $this->assertSame('recovery', $this->plansOn(self::SUNDAY)->activity_type);
        $this->assertSame('strength', $this->plansOn(self::SATURDAY)->activity_type);
    }

    // ---- all or none --------------------------------------------------------------------------------------------------------

    public function test_a_day_that_already_has_a_plan_holds_the_whole_week_for_confirmation(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->existing(self::SUNDAY);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved!'));

        $this->assertNull($this->plansOn(self::SATURDAY), 'Not even the empty day is written before the user confirms.');
        $this->assertSame('My own session', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringStartsWith("I haven't changed your plan yet.", $turn['answer']);
        $this->assertStringContainsString('2026-10-31', $turn['answer']);
        $this->assertStringContainsString('2026-11-01', $turn['answer']);
        $this->assertStringContainsString('My own session', $turn['answer'], 'The exact plan that would be replaced is shown.');
        $this->assertStringContainsString('Reply "yes" within 15 minutes', $turn['answer']);
        $this->assertStringNotContainsString('Saved!', $turn['answer']);
        $this->assertArrayHasKey(PendingProposalStore::REPLACEMENT_META_KEY, $turn['message']->meta);

        $yes = $this->say('yes', $conversation);

        $this->assertSame(0, $yes['calls'], 'The application applies the signed proposal; the model is not involved.');
        $this->assertStringStartsWith('Done.', $yes['answer']);
        $this->assertStringContainsString('I checked that it is saved', $yes['answer']);
        $this->assertSame(['Goblet Squats', 'Push-ups', 'Plank'], $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame('Active Recovery', $this->plansOn(self::SUNDAY)->title);
        $this->assertSame('applied', $yes['message']->meta['plan_replacement']);
    }

    public function test_a_confirmation_is_single_use(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->existing(self::SUNDAY);
        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));
        $this->say('yes', $conversation);

        // The user then edits Sunday themselves, and a second "yes" must not undo that.
        $this->plansOn(self::SUNDAY)->update(['title' => 'Edited by me']);
        $again = $this->say('yes', $conversation, null, new AiResponse(text: 'OK, noted.'));

        $this->assertStringContainsString('nothing waiting for your confirmation', $again['answer']);
        $this->assertSame('Edited by me', $this->plansOn(self::SUNDAY)->title);
        $this->assertSame(['Goblet Squats', 'Push-ups', 'Plank'], $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
    }

    public function test_a_started_or_completed_day_stops_the_whole_plan_and_nothing_is_half_saved(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $done = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => self::SUNDAY, 'status' => WorkoutPlan::STATUS_COMPLETED, 'title' => 'Done already']);
        WorkoutPlanExercise::factory()->for($done, 'workoutPlan')->create(['exercise_name' => 'Squat', 'status' => WorkoutPlanExercise::STATUS_COMPLETED, 'position' => 0]);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'All saved!'));

        $this->assertNull($this->plansOn(self::SATURDAY), 'Saturday is not saved either: a plan is saved whole or not at all.');
        $this->assertSame('Done already', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringContainsString("I didn't save any day of it", $turn['answer']);
        $this->assertStringContainsString('2026-11-01', $turn['answer']);
        $this->assertStringContainsString('already has recorded workout performance', $turn['answer']);
        $this->assertStringNotContainsString('All saved', $turn['answer']);

        // The user asks for the other day only; that is now a plain, complete save.
        $this->say('Saturday', $conversation, null, $this->weekCall([$this->saturday()]), new AiResponse(text: 'ok'));

        $this->assertSame(['Goblet Squats', 'Push-ups', 'Plank'], $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame('Done already', $this->plansOn(self::SUNDAY)->title);
    }

    public function test_a_started_day_is_never_replaced_even_with_a_confirmation_in_hand(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->existing(self::SUNDAY);
        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));

        // After the question was shown, the user did Sunday's bench press.
        $sunday = $this->plansOn(self::SUNDAY);
        $sunday->exercises->first()->update(['status' => WorkoutPlanExercise::STATUS_COMPLETED]);
        $sunday->update(['status' => WorkoutPlan::STATUS_COMPLETED]);

        $yes = $this->say('yes', $conversation);

        $this->assertNull($this->plansOn(self::SATURDAY), 'All or none.');
        $this->assertSame('My own session', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringContainsString("I haven't replaced it", $yes['answer']);
    }

    public function test_a_plan_that_appears_on_an_empty_day_after_the_question_stops_the_confirmation(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->existing(self::SUNDAY);
        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));

        $this->existing(self::SATURDAY, 'Added meanwhile');
        $yes = $this->say('yes', $conversation);

        $this->assertSame('Added meanwhile', $this->plansOn(self::SATURDAY)->title);
        $this->assertSame('My own session', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringContainsString('a plan appeared on '.self::SATURDAY, $yes['answer']);
    }

    public function test_the_apply_is_atomic_when_a_later_day_fails_to_write(): void
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [
            ['date' => self::SATURDAY, 'existing_plan_id' => 0, 'existing_revision' => '', 'existing_summary' => 'nothing planned', 'action' => 'create', 'reason' => 'x',
                'replacement' => ['activity_type' => 'strength', 'title' => 'Fine', 'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 10]], 'decision_summary' => 'x', 'reasoning_factors' => ['x']]],
            ['date' => self::SUNDAY, 'existing_plan_id' => 0, 'existing_revision' => '', 'existing_summary' => 'nothing planned', 'action' => 'create', 'reason' => 'x',
                'replacement' => ['activity_type' => 'strength', 'title' => 'Broken', 'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 99, 'planned_reps' => 10]], 'decision_summary' => 'x', 'reasoning_factors' => ['x']]],
        ]);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertIsString($result);
        $this->assertSame(0, $this->plans(), 'The first day was rolled back with the second.');
    }

    // ---- ambiguity and incomplete plans -----------------------------------------------------------------------------------

    public function test_an_exercise_alternative_is_asked_about_and_the_answer_completes_the_save(): void
    {
        $conversation = $this->conversationWith("**Saturday: Legs**\n1. Romanian Deadlift or Goblet Squat 3x10\n2. Plank 3 x 30 seconds\n\n**Sunday: Rest day**\nRecover.\n\nJust say \"add this plan to my dashboard\"!");

        $ask = $this->say('add this plan to my dashboard', $conversation);

        $this->assertSame(0, $ask['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('"Romanian Deadlift" or "Goblet Squat" (Saturday: Legs)', $ask['answer']);
        $this->assertStringContainsString('Reply with just the name', $ask['answer']);
        $this->assertSame('choice', $ask['message']->meta['save_pending']['kind']);

        $this->say('Goblet Squat', $conversation, null, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Legs', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30]]],
            $this->restSunday(),
        ]), new AiResponse(text: 'ok'));

        $this->assertSame(['Goblet Squat', 'Plank'], $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertTrue($this->plansOn(self::SUNDAY)->isRestDay());
    }

    public function test_the_model_cannot_pick_the_other_alternative(): void
    {
        $conversation = $this->conversationWith("**Saturday: Legs**\n1. Romanian Deadlift or Goblet Squat 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes");
        $this->say('add this plan to my dashboard', $conversation);

        $this->say('Goblet Squat', $conversation, null, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Legs', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Romanian Deadlift', 'planned_sets' => 3, 'planned_reps' => 10]]],
            ['date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800]]],
        ]), new AiResponse(text: 'Saved.'));

        $this->assertSame(0, $this->plans());
    }

    public function test_an_incomplete_plan_is_rejected_with_the_specific_day_and_nothing_is_saved(): void
    {
        $conversation = $this->conversationWith("**Saturday: Legs**\nSquats 3x10, lunges 3x10, etc.\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $turn = $this->say('add this plan to my dashboard', $conversation);

        $this->assertSame(0, $turn['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('"Saturday: Legs"', $turn['answer']);
        $this->assertStringContainsString('cut off or unfinished', $turn['answer']);
        $this->assertStringContainsString('Nothing was saved for any day.', $turn['answer']);
        $this->assertStringNotContainsString('that workout looks unfinished', $turn['answer'], 'Not the single-workout message.');
    }

    public function test_a_day_with_nothing_in_it_is_asked_about_not_invented(): void
    {
        $conversation = $this->conversationWith("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Mobility**\nSome light movement of your choice.");

        $turn = $this->say('add this plan to my dashboard', $conversation);

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('"Sunday: Mobility"', $turn['answer']);
        $this->assertStringContainsString('I don\'t want to guess or invent a workout', $turn['answer']);
    }

    public function test_days_that_have_passed_are_asked_about_and_the_answer_picks_the_rest(): void
    {
        $conversation = $this->conversationWith("**Wednesday: Legs**\nSquats 3x10\n\n**Saturday: Full Body Strength**\nGoblet Squats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $ask = $this->say('add this plan to my dashboard', $conversation);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('"Wednesday: Legs"', $ask['answer']);
        $this->assertStringContainsString('already passed', $ask['answer']);
        $this->assertSame('week_days', $ask['message']->meta['save_pending']['kind']);

        $this->say('Saturday and Sunday', $conversation, null, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Full Body Strength', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10]]],
            ['date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800]]],
        ]), new AiResponse(text: 'ok'));

        $this->assertSame(2, $this->plans());
        $this->assertNull($this->plansOn('2026-10-28'), 'Wednesday was never written.');
    }

    // ---- the model cannot widen what was authorized -----------------------------------------------------------------------

    public function test_the_model_cannot_add_a_day_drop_a_day_move_a_day_or_change_an_exercise(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $this->say('add this plan to my dashboard', $conversation, null,
            $this->weekCall([$this->saturday(), $this->sunday(), ['date' => '2026-11-02', 'action' => 'clear', 'reason' => 'x']]),
            $this->weekCall([$this->saturday()]),
            $this->weekCall([$this->saturday(['date' => '2026-10-30']), $this->sunday()]),
            $this->weekCall([$this->saturday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]]), $this->sunday()]),
            new AiResponse(text: 'All saved!'),
        );

        $this->assertSame(0, $this->plans(), 'Every one of those calls is outside the plan the user was shown.');
    }

    public function test_the_model_cannot_use_create_workout_plan_for_a_plan_of_several_days(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $call = new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'Everything', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => '2026-10-29',
            'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ])]);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $call, new AiResponse(text: 'Done!'));

        $this->assertSame(0, $this->plans());
        $this->assertNotContains('create_workout_plan', array_column($this->fake->calls[0]['tools'], 'name'));
        $this->assertStringContainsString("I haven't changed your plan", $turn['answer']);
    }

    public function test_no_starting_weight_is_invented_for_any_day(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $withWeight = $this->saturday();
        $withWeight['exercises'][0]['planned_weight_kg'] = 40;

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$withWeight, $this->sunday()]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->plansOn(self::SATURDAY)->exercises->sortBy('position')->first()->planned_weight_kg, 'No baseline, no load.');
        $this->assertStringContainsString('left the starting weight for Goblet Squats unset', $turn['answer']);
    }

    public function test_a_load_above_the_limit_stops_the_whole_plan_and_says_why(): void
    {
        $this->logSets($this->user, 1, 'Goblet Squats', [3, 10, 20], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 20]));
        $conversation = $this->conversationWith(self::PLAN);
        $heavy = $this->saturday();
        $heavy['exercises'][0]['planned_weight_kg'] = 40;

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$heavy, $this->sunday()]), new AiResponse(text: 'Saved!'));

        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertNull($this->plansOn(self::SUNDAY));
        $this->assertStringContainsString("I haven't changed your plan", $turn['answer']);
        $this->assertStringContainsString('Nothing was saved for any day.', $turn['answer']);
        $this->assertStringNotContainsString('Saved!', $turn['answer']);
    }

    // ---- honesty --------------------------------------------------------------------------------------------------------------

    public function test_a_model_that_claims_success_without_saving_is_corrected(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, new AiResponse(text: "I've added your plan to the dashboard for Saturday and Sunday!"));

        $this->assertSame(0, $this->plans());
        $this->assertStringStartsWith("I haven't changed your plan", $turn['answer']);
        $this->assertStringContainsString('nothing was saved for any day', $turn['answer']);
        $this->assertStringNotContainsString('added your plan', $turn['answer']);
        $this->assertTrue($turn['message']->meta['save_clarification']);
    }

    public function test_a_model_that_claims_it_has_no_access_is_not_passed_on(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say('add this plan to my dashboard', $conversation, null, new AiResponse(text: "I'm sorry, I don't have access to the plan updating tool right now."));

        $this->assertStringNotContainsString("don't have access", $turn['answer']);
        $this->assertStringContainsString("I haven't changed your plan", $turn['answer']);
        $this->assertContains('propose_weekly_plan_changes', array_column($this->fake->calls[0]['tools'], 'name'), 'The tool was offered.');
    }

    public function test_the_success_message_is_built_from_what_is_in_the_database(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(['title' => 'Full Body Strength']), $this->sunday()]), new AiResponse(text: 'Saved Monday and Tuesday!'));

        foreach ([self::SATURDAY, self::SUNDAY] as $date) {
            $this->assertStringContainsString($this->plansOn($date)->title, $turn['answer']);
        }

        $this->assertStringNotContainsString('Monday', $turn['answer']);
    }

    // ---- confirmations cannot be bent ---------------------------------------------------------------------------------

    private function held(): array
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->existing(self::SUNDAY);
        $question = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));

        return [$conversation, $question['message']];
    }

    public function test_an_expired_confirmation_saves_nothing(): void
    {
        [$conversation] = $this->held();
        $this->travelTo(Carbon::parse('2026-10-29 10:16', 'America/Bogota'));

        $yes = $this->say('yes', $conversation);

        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertStringContainsString('no longer valid', $yes['answer']);
    }

    public function test_a_forged_week_confirmation_saves_nothing(): void
    {
        [$conversation, $question] = $this->held();

        // Someone edits the stored proposal: another exercise on Saturday.
        $meta = $question->meta;
        $meta[PendingProposalStore::REPLACEMENT_META_KEY]['changes'][0]['replacement']['exercises'][0]['exercise_name'] = 'Burpees';
        $question->update(['meta' => $meta]);

        $yes = $this->say('yes', $conversation);

        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertSame('My own session', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringContainsString('no longer valid', $yes['answer']);
    }

    public function test_a_confirmation_cannot_be_moved_to_a_different_week(): void
    {
        [$conversation, $question] = $this->held();

        $meta = $question->meta;
        $meta[PendingProposalStore::REPLACEMENT_META_KEY]['changes'][0]['date'] = '2026-11-07';
        $question->update(['meta' => $meta]);

        $this->say('yes', $conversation);

        $this->assertNull($this->plansOn('2026-11-07'));
        $this->assertNull($this->plansOn(self::SATURDAY));
    }

    public function test_a_stale_confirmation_after_the_plan_changed_saves_nothing(): void
    {
        [$conversation] = $this->held();
        $this->plansOn(self::SUNDAY)->update(['title' => 'Changed after I asked']);

        $yes = $this->say('yes', $conversation);

        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertStringContainsString('changed after I asked', $yes['answer']);
    }

    public function test_another_user_cannot_confirm_it(): void
    {
        [$conversation] = $this->held();
        $bob = $this->newUser();

        $intruder = $this->ask($bob, 'yes', $conversation);

        $this->assertNotNull($intruder['error']);
        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertSame(0, $this->plans($bob));
    }

    public function test_a_confirmation_copied_into_another_conversation_or_user_grants_nothing(): void
    {
        [, $question] = $this->held();

        // Another conversation of the same user.
        $other = $this->conversationWith('Hello');
        Message::create(['conversation_id' => $other, 'role' => 'assistant', 'content' => $question->content, 'meta' => $question->meta]);
        $this->say('yes', $other);
        $this->assertNull($this->plansOn(self::SATURDAY));

        // Another user's conversation.
        $bob = $this->newUser();
        $theirs = $this->conversationWith('Hello', 'hi', $bob);
        Message::create(['conversation_id' => $theirs, 'role' => 'assistant', 'content' => $question->content, 'meta' => $question->meta]);
        $this->say('yes', $theirs, $bob);
        $this->assertSame(0, $this->plans($bob));
        $this->assertNull($this->plansOn(self::SATURDAY));
    }

    // ---- who may save what -----------------------------------------------------------------------------------------------

    public function test_a_plan_shown_to_another_user_cannot_be_saved_by_this_one(): void
    {
        $bob = $this->newUser();
        $theirs = $this->conversationWith(self::PLAN, 'plan', $bob);
        $conversation = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved.'));

        $this->assertSame(0, $this->plans());
        $this->assertSame(0, $this->plans($bob));
        $this->assertSame(0, $turn['calls'], 'There is no plan in this conversation to save.');
        $this->assertStringContainsString("I don't see a workout", $turn['answer']);
        $this->assertNotNull(Conversation::find($theirs));
    }

    public function test_a_retry_inside_the_window_saves_the_same_plan_and_one_after_it_does_not(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $bad = $this->weekCall([$this->saturday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]]), $this->sunday()]);
        $this->say('add this plan to my dashboard', $conversation, null, $bad, $bad, $bad, new AiResponse(text: 'Done!'));
        $this->assertSame(0, $this->plans());

        $this->travelTo(Carbon::parse('2026-10-29 10:05', 'America/Bogota'));
        $this->say('could you try to add them again?', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));
        $this->assertSame(2, $this->plans(), 'A retry is the same request.');

        $late = $this->conversationWith(self::PLAN);
        $this->say('add this plan to my dashboard', $late, null, $bad, $bad, $bad, new AiResponse(text: 'Done!'));
        $this->travelTo(Carbon::parse('2026-10-29 10:30', 'America/Bogota'));
        $expired = $this->say('try again', $late);
        $this->assertStringContainsString('more than 15 minutes old', $expired['answer']);
    }

    // ---- dates and timezones --------------------------------------------------------------------------------------------

    public function test_the_plan_lands_on_the_users_dates_late_in_the_evening(): void
    {
        // 23:30 on Friday in Bogota is already Saturday in UTC. "Saturday" is still tomorrow for this user.
        $this->travelTo(Carbon::parse('2026-10-30 23:30', 'America/Bogota'));
        $conversation = $this->conversationWith(self::PLAN);

        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertNotNull($this->plansOn(self::SUNDAY));
        $this->assertSame(2, $this->plans());
    }

    public function test_a_plan_with_explicit_dates_uses_those_dates(): void
    {
        $conversation = $this->conversationWith("**Saturday, Oct 31: Legs**\nGoblet Squats 3x10\n\n**Sunday, Nov 1: Walk**\nEasy walk 30 minutes\n\nSay \"add this plan to my dashboard\"!");

        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Legs', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10]]],
            ['date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800]]],
        ]), new AiResponse(text: 'ok'));

        $this->assertSame(2, $this->plans());
    }

    public function test_a_date_that_does_not_match_its_weekday_is_asked_about(): void
    {
        $conversation = $this->conversationWith("**Saturday, Nov 1: Legs**\nGoblet Squats 3x10\n\n**Sunday, Nov 2: Walk**\nEasy walk 30 minutes");

        $turn = $this->say('add this plan to my dashboard', $conversation);

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString("can't tell which dates", $turn['answer']);
    }

    // ---- single workouts are unchanged ------------------------------------------------------------------------------------

    public function test_a_single_day_workout_is_still_saved_as_before(): void
    {
        $conversation = $this->conversationWith("Here's a quick upper-body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $call = new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'x', 'reasoning_factors' => ['x'],
            'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]],
        ])]);

        $this->say('add this workout to my dashboard', $conversation, null, $call, new AiResponse(text: 'Added.'));

        $this->assertNotNull($this->plansOn('2026-10-29'));
        $this->assertSame(1, $this->plans());
    }

    // ---- all or none, before and after the confirmation ---------------------------------------------------------------

    /** What the plans in the database are, exactly: rows, exercises and when each was last touched. */
    private function plansFingerprint(): string
    {
        return md5(json_encode([
            WorkoutPlan::orderBy('id')->get(['id', 'user_id', 'planned_date', 'title', 'status', 'updated_at'])->toArray(),
            WorkoutPlanExercise::orderBy('id')->get(['id', 'workout_plan_id', 'exercise_name', 'planned_sets', 'planned_reps', 'planned_weight_kg', 'status', 'updated_at'])->toArray(),
        ]));
    }

    /** Make writing Sunday's "Easy walk" fail, after Saturday has already been written in the same transaction. */
    private function failWhenSundayIsWritten(): void
    {
        Event::listen('eloquent.creating: '.WorkoutPlanExercise::class, function (WorkoutPlanExercise $exercise) {
            if ($exercise->exercise_name === 'Easy walk') {
                throw new \RuntimeException('disk full');
            }
        });
    }

    public function test_a_failure_on_the_second_day_rolls_the_first_day_back_when_nothing_needed_confirming(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->failWhenSundayIsWritten();
        $before = $this->plansFingerprint();

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved both!'));

        $this->assertSame($before, $this->plansFingerprint(), 'Saturday was written first and is gone again.');
        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertStringContainsString('none of the days were saved', $turn['answer']);
        $this->assertStringContainsString('Nothing was saved for any day.', $turn['answer']);
        $this->assertStringNotContainsString('Saved both', $turn['answer']);
        $this->assertArrayNotHasKey('plan_replacement', $turn['message']->meta, 'Nothing is recorded as saved, so a later retry stays possible.');
    }

    public function test_a_failure_on_the_second_day_after_the_confirmation_changes_nothing_and_the_old_plan_stays(): void
    {
        [$conversation] = $this->held();
        $this->failWhenSundayIsWritten();
        $before = $this->plansFingerprint();

        $yes = $this->say('yes', $conversation);

        $this->assertSame($before, $this->plansFingerprint());
        $this->assertSame('My own session', $this->plansOn(self::SUNDAY)->title);
        $this->assertSame(['Bench Press'], $this->plansOn(self::SUNDAY)->exercises->pluck('exercise_name')->all(), 'The replaced plan was not half-deleted.');
        $this->assertNull($this->plansOn(self::SATURDAY));
        $this->assertStringContainsString("I haven't replaced it", $yes['answer']);
        $this->assertStringContainsString('none of the days were saved', $yes['answer']);
    }

    public function test_a_competing_request_that_fills_a_day_first_leaves_neither_a_partial_week_nor_a_duplicate(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        // Between this request's check and its write, another request writes Sunday.
        Event::listen('eloquent.creating: '.WorkoutPlan::class, function (WorkoutPlan $plan) {
            if ($plan->planned_date->toDateString() === self::SUNDAY && ! DB::table('workout_plans')->where('title', 'Competing')->exists()) {
                DB::table('workout_plans')->insert(['user_id' => $plan->user_id, 'planned_date' => self::SUNDAY, 'activity_type' => 'strength', 'title' => 'Competing', 'source' => 'manual', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved!'));

        // (In this single-connection test the competing row is inside the same transaction, so it is rolled back with it; with
        // a second connection it would stay. Either way: no partial week, and never two plans for one day.)
        $this->assertNull($this->plansOn(self::SATURDAY), 'Saturday was written first and is gone again.');
        $this->assertLessThanOrEqual(1, WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', self::SUNDAY)->count());
        $this->assertSame(0, WorkoutPlan::where('title', 'Full Body Strength')->count(), 'Nothing of this request was kept.');
        $this->assertStringStartsWith("I haven't changed your plan", $turn['answer']);
    }

    public function test_a_day_that_has_been_started_blocks_the_whole_plan(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $started = $this->existing(self::SUNDAY);
        $started->update(['status' => WorkoutPlan::STATUS_IN_PROGRESS]);
        $before = $this->plansFingerprint();

        $turn = $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved!'));

        $this->assertSame($before, $this->plansFingerprint());
        $this->assertStringContainsString('already started', $turn['answer']);
        $this->assertStringContainsString("I didn't save any day of it", $turn['answer']);
    }

    public function test_a_partly_performed_day_is_protected_before_and_after_the_confirmation(): void
    {
        [$conversation] = $this->held();
        $sunday = $this->plansOn(self::SUNDAY);
        $sunday->exercises->first()->update(['status' => WorkoutPlanExercise::STATUS_PARTIAL]);
        $before = $this->plansFingerprint();

        $yes = $this->say('yes', $conversation);

        $this->assertSame($before, $this->plansFingerprint());
        $this->assertStringContainsString("I haven't replaced it", $yes['answer']);
    }

    public function test_every_confirmation_that_is_not_valid_writes_nothing_at_all(): void
    {
        $scenarios = [
            'expired' => function (int $conversation, Message $question) {
                $this->travelTo(Carbon::parse('2026-10-29 10:20', 'America/Bogota'));
            },
            'forged' => function (int $conversation, Message $question) {
                $meta = $question->meta;
                $meta[PendingProposalStore::REPLACEMENT_META_KEY]['changes'][1]['replacement']['title'] = 'Forged';
                $question->update(['meta' => $meta]);
            },
            'stale' => function (int $conversation, Message $question) {
                $this->plansOn(self::SUNDAY)->exercises->first()->update(['planned_reps' => 9]);
            },
            'already used' => function (int $conversation, Message $question) {
                $meta = $question->meta;
                $meta[PendingProposalStore::REPLACEMENT_META_KEY]['consumed_at'] = now()->toIso8601String();
                $question->update(['meta' => $meta]);
            },
            'unsigned' => function (int $conversation, Message $question) {
                $meta = $question->meta;
                unset($meta[PendingProposalStore::REPLACEMENT_META_KEY]['signature']);
                $question->update(['meta' => $meta]);
            },
        ];

        foreach ($scenarios as $name => $tamper) {
            Carbon::setTestNow();
            $this->travelTo(Carbon::parse('2026-10-29 10:00', 'America/Bogota'));
            WorkoutPlan::query()->delete();
            [$conversation, $question] = $this->held();
            $tamper($conversation, $question);
            $before = $this->plansFingerprint();

            $this->say('yes', $conversation, null, new AiResponse(text: 'OK.'));

            $this->assertSame($before, $this->plansFingerprint(), "A {$name} confirmation wrote something.");
            $this->assertNull($this->plansOn(self::SATURDAY), $name);
        }
    }

    public function test_a_used_confirmation_cannot_be_used_again_and_a_second_claim_loses(): void
    {
        [$conversation, $question] = $this->held();
        $store = app(PendingProposalStore::class);
        $conversationModel = Conversation::findOrFail($conversation);

        $first = $store->claimReplacement($conversationModel, $this->user, $question);
        $second = $store->claimReplacement($conversationModel, $this->user, $question);
        $third = $store->claimReplacement($conversationModel, $this->user, Message::findOrFail($question->id));

        $this->assertNotNull($first);
        $this->assertNull($second, 'The same stale copy of the message cannot redeem it twice.');
        $this->assertNull($third);
    }

    public function test_confirming_twice_in_a_row_leaves_exactly_one_copy_of_the_week(): void
    {
        [$conversation] = $this->held();
        $this->say('yes', $conversation);
        $once = $this->plansFingerprint();

        $this->say('yes', $conversation, null, new AiResponse(text: 'OK, noted.'));

        $this->assertSame($once, $this->plansFingerprint());
        $this->assertSame(2, $this->plans());
        $this->assertSame(3, $this->plansOn(self::SATURDAY)->exercises->count(), 'Not six exercises.');
    }

    // ---- what is said back --------------------------------------------------------------------------------------------

    public function test_naming_only_some_days_saves_those_and_says_which_were_left_out(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say('add this plan to my dashboard for Saturday', $conversation, null, $this->weekCall([$this->saturday()]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertNull($this->plansOn(self::SUNDAY), 'Sunday was left out, and the user is told so.');
        $this->assertStringContainsString('I left out Sunday: Active Recovery', $turn['answer']);
    }

    public function test_a_plan_with_exact_dates_is_not_shifted_by_the_servers_clock(): void
    {
        // Saturday 21:30 in Bogota is already Sunday in UTC: the user's Saturday is still today, not past.
        $this->travelTo(Carbon::parse('2026-10-31 21:30', 'America/Bogota'));
        $conversation = $this->conversationWith("**Saturday, Oct 31: Legs**\nGoblet Squats 3x10\n\n**Sunday, Nov 1: Walk**\nEasy walk 30 minutes\n\nSay \"add this plan to my dashboard\"!");

        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Legs', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 10]]],
            ['date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Walk', 'reason' => 'x', 'exercises' => [['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800]]],
        ]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertNotNull($this->plansOn(self::SUNDAY));
        $this->assertSame(2, $this->plans());
    }

    public function test_a_plan_that_is_not_dated_inside_what_was_authorized_is_not_written(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $this->say('add this plan to my dashboard', $conversation, null,
            $this->weekCall([$this->saturday(['date' => '2026-11-07']), $this->sunday()]),
            $this->weekCall([$this->saturday(), $this->sunday(['date' => '2027-01-01'])]),
            new AiResponse(text: 'Saved!'),
        );

        $this->assertSame(0, $this->plans());
    }
}
