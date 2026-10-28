<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Production: the user asked "Add this workout to my dashboard" and the coach answered that it could not push a
 * plan to the dashboard. Cause: the request was read as an unclear wish, so the plan-writing tool was withheld for
 * that turn. These replay the real sequence through the real endpoint with a scripted model.
 *
 * Authorization is not loosened to make it work: the write is limited to one day's plan, it needs a workout to
 * exist in the conversation, and the load rules still decide every weight.
 */
class SaveWorkoutToDashboardTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    private const DESCRIBED = "Here's a 30-minute upper body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function save(array $exercises, ?string $date = null, string $title = 'Upper body'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'Saved as asked.', 'reasoning_factors' => ['You asked to add it'],
            'planned_date' => $date, 'exercises' => $exercises,
        ]))]);
    }

    private function exercises(): array
    {
        return [
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
        ];
    }

    /** Turn one: the coach describes a workout. Returns the conversation id. */
    private function coachDescribesAWorkout(): int
    {
        $this->fake->queue(new AiResponse(text: self::DESCRIBED));

        return $this->ask($this->user, 'Give me a quick upper body workout')['conversation'];
    }

    /** @return array{result: array, calls: array} */
    private function say(string $message, ?int $conversation, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return ['result' => $result, 'calls' => array_slice($this->fake->calls, $before)];
    }

    private function offered(array $call): array
    {
        return array_column($call['tools'], 'name');
    }

    private function plansFor(User $user, ?string $date = null): int
    {
        return WorkoutPlan::where('user_id', $user->id)->when($date, fn ($q) => $q->whereDate('planned_date', $date))->count();
    }

    private function lastAssistant(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    // ---- the production sequence ---------------------------------------------------------------------------

    public function test_add_this_workout_to_my_dashboard_saves_the_workout_the_coach_just_described(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $turn = $this->say('Add this workout to my dashboard', $conversation, $this->save($this->exercises()), new AiResponse(text: "Done, it's on your dashboard for today."));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]), 'The plan tool is offered for this message (it was withheld in production).');

        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->firstOrFail();
        $this->assertSame('Upper body', $plan->title);
        $this->assertSame(['Push-ups', 'Dumbbell rows', 'Plank'], $plan->exercises->pluck('exercise_name')->all());
        $this->assertSame([12, 10, null], $plan->exercises->pluck('planned_reps')->all());
        $this->assertSame([null, null, null], $plan->exercises->pluck('planned_weight_kg')->all(), 'No weight was named, so none is set.');

        $reply = $this->lastAssistant($conversation);
        $this->assertSame("Done, it's on your dashboard for today.", $reply->content, 'No refusal text is appended.');
        $this->assertArrayNotHasKey('blocked_tool_calls', $reply->meta ?? []);
        $this->assertSame(['create_workout_plan'], array_column($reply->meta['tool_calls'], 'name'));
    }

    public function test_the_coach_is_told_exactly_what_to_write(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $turn = $this->say('Add this workout to my dashboard', $conversation, new AiResponse(text: 'Added.'));
        $prompt = $turn['calls'][0]['messages'][0]['content'];

        $this->assertStringContainsString('put one workout on their plan', $prompt);
        $this->assertStringContainsString('planned_date '.$this->today, $prompt, 'The day is the application\'s, stated outright.');
        $this->assertStringContainsString('Push-ups 3x12 | Dumbbell rows 3x10 | Plank 3x30 seconds', $prompt, 'The exercises are listed as written.');
        $this->assertStringContainsString('do not use another date', $prompt);
        $this->assertStringContainsString('Do not choose or raise a weight', $prompt);
        $this->assertStringContainsString('nothing has changed until the user confirms', $prompt);
    }

    public function test_the_other_phrasings_work_too(): void
    {
        foreach (['Can you add this workout to my dashboard?', 'Save this workout', 'Add it to my plan', 'Push this to my dashboard', 'Put it on my dashboard'] as $i => $message) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $conversation = $this->coachDescribesAWorkout();

            $this->say($message, $conversation, $this->save($this->exercises(), $this->today, "Saved {$i}"), new AiResponse(text: 'Done.'));

            $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->where('title', "Saved {$i}")->count(), $message);
        }
    }

    public function test_the_user_can_name_the_day(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $friday = now('America/Bogota')->next('Friday')->toDateString();

        $this->say('Add that to my plan for Friday', $conversation, $this->save($this->exercises(), $friday), new AiResponse(text: 'Added for Friday.'));

        $this->assertSame(1, $this->plansFor($this->user, $friday));
        $this->assertSame(0, $this->plansFor($this->user, $this->today));
    }

    public function test_a_workout_the_user_writes_themselves_needs_no_earlier_reply(): void
    {
        $turn = $this->say('Add this workout to my dashboard: squats 3x10 and lunges 3x12', null, $this->save([['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Lunges', 'planned_sets' => 3, 'planned_reps' => 12]], null, 'Leg day'), new AiResponse(text: 'Added.'));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]));
        $this->assertSame(1, $this->plansFor($this->user, $this->today));
    }

    public function test_a_user_who_names_a_new_workout_gets_it_saved(): void
    {
        $turn = $this->say('Add a 30 minute treadmill run to my plan for today', null, new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'r', name: 'create_workout_plan', arguments: [
            'activity_type' => 'treadmill', 'title' => '30 minute run', 'duration_minutes' => 30, 'decision_summary' => 'As asked.', 'reasoning_factors' => ['You asked'],
        ])]), new AiResponse(text: 'Added.'));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]));
        $this->assertSame('30 minute run', WorkoutPlan::where('user_id', $this->user->id)->value('title'));
    }

    // ---- nothing to save, and things that are not requests -----------------------------------------------------------

    public function test_with_no_workout_in_the_conversation_nothing_is_written_and_the_user_is_told_why(): void
    {
        $turn = $this->say('Add this workout to my dashboard', null);

        $this->assertSame([], $turn['calls'], 'The model is not asked to improvise around a reference nobody can resolve.');
        $this->assertSame(0, $this->plansFor($this->user));
        $this->assertStringContainsString("I don't see a workout in my last reply or in your message to add", $turn['result']['answer']);
        $this->assertStringContainsString('add this workout to my dashboard: squats 3x10', $turn['result']['answer'], 'It says what to send instead, and that example is itself a working request.');
    }

    public function test_the_example_the_coach_suggests_when_there_is_nothing_to_save_actually_works(): void
    {
        $turn = $this->say('Add this workout to my dashboard: squats 3x10, push-ups 3x12', null, $this->save([['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]], null, 'From the example'), new AiResponse(text: 'Added.'));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]));
        $this->assertSame(1, $this->plansFor($this->user, $this->today));
    }

    public function test_questions_denials_and_other_peoples_words_do_not_save(): void
    {
        foreach ([
            'Should I add this workout to my plan?',
            "Don't add this workout to my dashboard",
            'I added this workout to my plan yesterday',
            'My trainer said add this workout to my plan',
            'What should I focus on next?',
        ] as $message) {
            $conversation = $this->coachDescribesAWorkout();
            $turn = $this->say($message, $conversation, $this->save($this->exercises(), $this->today, 'Sneaky'), new AiResponse(text: 'Okay.'));

            $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->where('title', 'Sneaky')->count(), $message);
            $this->assertNotContains('create_workout_plan', $this->offered($turn['calls'][0]), $message);
        }
    }

    public function test_yes_after_the_coach_offered_to_add_it_points_to_the_phrase_that_works(): void
    {
        $this->fake->queue(new AiResponse(text: self::DESCRIBED."\n\nWant me to add this to your dashboard?"));
        $conversation = $this->ask($this->user, 'Give me a quick upper body workout')['conversation'];

        $turn = $this->say('yes', $conversation, $this->save($this->exercises(), $this->today, 'From a yes'), new AiResponse(text: 'Added.'));

        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->where('title', 'From a yes')->count(), "The model's own offer is still not permission.");
        $this->assertStringContainsString('say "add this workout to my dashboard"', $turn['result']['answer']);
    }

    // ---- the protections still hold ---------------------------------------------------------------------------------

    public function test_a_heavier_load_than_the_user_has_done_is_still_refused(): void
    {
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->fake->queue(new AiResponse(text: "Try this:\n1. Bench Press 3x10 at 60 kg\n2. Push-ups 3x12"));
        $conversation = $this->ask($this->user, 'Give me a chest workout')['conversation'];

        $turn = $this->say('Add this workout to my dashboard', $conversation, $this->save([['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 60], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]], $this->today, 'Heavy'), new AiResponse(text: 'Added.'));

        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->where('title', 'Heavy')->count());
        $this->assertSame('load_above_limit', $this->lastAssistant($conversation)->meta['blocked_tool_calls'][0]['reason']);

        $this->say('Add this workout to my dashboard', $conversation, $this->save([['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]], $this->today, 'Steady'), new AiResponse(text: 'Added.'));
        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->where('title', 'Steady')->count(), 'The load the user has actually done is fine.');
    }

    public function test_a_starting_weight_is_never_invented_for_an_exercise_with_no_history(): void
    {
        $this->fake->queue(new AiResponse(text: "Try this:\n1. Goblet Squat 3x10 at 24 kg\n2. Push-ups 3x12"));
        $conversation = $this->ask($this->user, 'Give me a quick leg workout')['conversation'];

        $this->say('Add this workout to my dashboard', $conversation, $this->save([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]), new AiResponse(text: 'Added.'));

        $row = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail()->exercises->first();
        $this->assertSame('Goblet Squat', $row->exercise_name);
        $this->assertNull($row->planned_weight_kg, 'The weight in the coach\'s own reply is not a baseline, and the user did not choose it.');
        $this->assertStringContainsString('left unset', json_encode($this->fake->calls[count($this->fake->calls) - 1]['messages']));
        $this->assertStringContainsString('I left the starting weight for Goblet Squat unset', $this->lastAssistant($conversation)->content);
    }

    public function test_a_day_with_recorded_performance_is_not_replaced(): void
    {
        $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $before = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->firstOrFail();
        $conversation = $this->coachDescribesAWorkout();

        $this->say('Add this workout to my dashboard', $conversation, $this->save($this->exercises()), new AiResponse(text: "I couldn't save it."));

        $after = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->firstOrFail();
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->title, $after->title);
        $this->assertSame(['Bench Press'], $after->exercises->pluck('exercise_name')->all());
        $this->assertStringContainsString('already has recorded workout performance', json_encode($this->lastAssistant($conversation)->meta['tool_calls'][0]['result']));
    }

    public function test_it_writes_only_to_the_signed_in_users_plan(): void
    {
        $other = $this->newUser();
        app(WorkoutPlanService::class)->createOrReplace($other, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Their plan']);
        $conversation = $this->coachDescribesAWorkout();

        $this->say('Add this workout to my dashboard', $conversation, $this->save($this->exercises()), new AiResponse(text: 'Added.'));

        $this->assertSame('Their plan', WorkoutPlan::where('user_id', $other->id)->value('title'));
        $this->assertSame(1, $this->plansFor($this->user, $this->today));
    }

    public function test_nothing_but_the_one_days_plan_is_authorized(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $turn = $this->say('Add this workout to my dashboard', $conversation, new AiResponse(text: 'Added.'));
        $tools = $this->offered($turn['calls'][0]);

        $this->assertContains('create_workout_plan', $tools);
        $this->assertNotContains('propose_weekly_plan_changes', $tools, 'It cannot rewrite the week.');
        $this->assertNotContains('update_exercise_status', $tools, 'It cannot mark anything done.');
    }
}
