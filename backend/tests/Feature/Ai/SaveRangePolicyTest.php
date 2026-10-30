<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * A workout plan stores ONE whole number each for sets, reps and seconds (workout_plan_exercises.planned_sets,
 * planned_reps, planned_duration_seconds are integers). A prescription such as "3 x 8-12 reps" cannot be kept as written,
 * so the policy is the application's and fixed: a range is stored as its LOW end, whatever the model chose inside it or
 * left out; a number outside the range is refused; exact numbers are stored as written; and the user is told.
 */
class SaveRangePolicyTest extends PerformanceEvalCase
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
        Cache::flush();
    }

    private function workout(string ...$lines): string
    {
        $numbered = [];

        foreach ($lines as $i => $line) {
            $numbered[] = ($i + 1).'. '.$line;
        }

        return "Here's a workout for you:\n\n".implode("\n", $numbered)."\n\nWould you like me to add this to your dashboard?";
    }

    private function saveCall(array $exercises): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'Session', 'decision_summary' => 'As asked.', 'reasoning_factors' => ['You asked to add it'],
            'exercises' => $exercises,
        ])]);
    }

    /** The coach shows $reply, the user says "Add this workout to my dashboard" and the model answers with $responses. */
    private function save(string $reply, AiResponse ...$responses): array
    {
        $conversation = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        Message::create(['conversation_id' => $conversation, 'role' => 'user', 'content' => 'Give me a workout', 'meta' => null]);
        Message::create(['conversation_id' => $conversation, 'role' => 'assistant', 'content' => $reply, 'meta' => null]);

        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, 'Add this workout to my dashboard', $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return $result;
    }

    private function stored(): array
    {
        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->first();

        return $plan === null ? [] : $plan->exercises->mapWithKeys(fn ($e) => [$e->exercise_name => [$e->planned_sets, $e->planned_reps, $e->planned_duration_seconds]])->all();
    }

    private function toolErrors(): array
    {
        $errors = [];

        foreach ($this->fake->calls as $call) {
            foreach ($call['messages'] as $message) {
                foreach ($message['tool_results'] ?? [] as $result) {
                    $errors[] = $result;
                }
            }
        }

        return $errors;
    }

    // ---- what is stored for each kind of prescription ----------------------------------------------------------------

    public function test_each_prescription_is_stored_as_the_application_decides_never_the_model(): void
    {
        $this->save(
            $this->workout(
                '**Goblet Squat:** 3 × 8–12 reps',
                '**Push-ups:** 2–3 sets × 10–12 reps',
                '**Plank:** 3 × 45 seconds',
                '**Dumbbell Row:** 3 × 12 reps',
            ),
            // The model picks a number inside every range (the middle, or the top), as it would unprompted.
            $this->saveCall([
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 45],
                ['exercise_name' => 'Dumbbell Row', 'planned_sets' => 3, 'planned_reps' => 12],
            ]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame([
            'Goblet Squat' => [3, 8, null],   // 3 x 8-12 reps: the exact 3 sets, the low end of the reps
            'Push-ups' => [2, 10, null],      // 2-3 sets x 10-12 reps: the low end of both
            'Plank' => [3, null, 45],         // 3 x 45 seconds: exact
            'Dumbbell Row' => [3, 12, null],  // 3 x 12 reps: exact
        ], $this->stored());
    }

    public function test_a_range_of_seconds_is_stored_as_its_low_end(): void
    {
        $this->save(
            $this->workout('**Plank Hold:** 3 sets of 30-45 seconds', '**Push-ups:** 3 x 12'),
            $this->saveCall([
                ['exercise_name' => 'Plank Hold', 'planned_sets' => 3, 'planned_duration_seconds' => 40],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame(['Plank Hold' => [3, null, 30], 'Push-ups' => [3, 12, null]], $this->stored());
    }

    public function test_values_the_model_leaves_out_of_a_range_are_filled_in_by_the_application(): void
    {
        $this->save(
            $this->workout('**Goblet Squat:** 2-3 sets of 8-12 reps', '**Push-ups:** 3 x 12'),
            $this->saveCall([['exercise_name' => 'Goblet Squat'], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame(['Goblet Squat' => [2, 8, null], 'Push-ups' => [3, 12, null]], $this->stored());
    }

    public function test_the_user_is_told_which_ranges_were_stored_as_one_number(): void
    {
        $result = $this->save(
            $this->workout('**Goblet Squat:** 3 × 8–12 reps', '**Plank:** 3 × 45 seconds'),
            $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 11], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 45]]),
            new AiResponse(text: 'All set, it is on your dashboard.'),
        );

        $this->assertStringStartsWith('All set, it is on your dashboard.', $result['answer']);
        $this->assertStringContainsString('I saved the low end of every range: Goblet Squat 8 reps (written 8-12).', $result['answer']);
        $this->assertStringNotContainsString('Plank', explode('low end of every range', $result['answer'])[1], 'An exact prescription is not mentioned.');
    }

    public function test_nothing_is_said_when_no_prescription_was_a_range(): void
    {
        $result = $this->save(
            $this->workout('**Push-ups:** 3 x 12', '**Plank:** 3 x 45 seconds'),
            $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 45]]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame('Done.', $result['answer']);
    }

    // ---- what is refused ------------------------------------------------------------------------------------------------

    public function test_a_value_outside_the_range_is_refused_and_nothing_is_stored(): void
    {
        $this->save(
            $this->workout('**Goblet Squat:** 3 × 8–12 reps', '**Push-ups:** 3 x 12'),
            $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 15], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]),
            new AiResponse(text: 'All saved.'),
        );

        $this->assertSame([], $this->stored(), 'Fifteen is not in 8-12.');
        $this->assertStringContainsString('the reps must be 8-12', json_encode($this->fake->calls[1]['messages']));
    }

    public function test_a_number_taken_from_the_wrong_field_is_refused(): void
    {
        // 10 is inside the REPS range 8-12, but these are SETS: "3".
        $this->save(
            $this->workout('**Goblet Squat:** 3 × 8–12 reps', '**Push-ups:** 3 x 12'),
            $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 10, 'planned_reps' => 8], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]),
            new AiResponse(text: 'All saved.'),
        );

        $this->assertSame([], $this->stored());
        $this->assertStringContainsString('the sets must be 3', json_encode($this->fake->calls[1]['messages']));
    }

    public function test_an_exact_prescription_cannot_be_changed(): void
    {
        $this->save(
            $this->workout('**Push-ups:** 3 x 12', '**Plank:** 3 x 45 seconds'),
            $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 45]]),
            new AiResponse(text: 'All saved.'),
        );

        $this->assertSame([], $this->stored());
        $this->assertStringContainsString('the reps must be 12', json_encode($this->fake->calls[1]['messages']));
    }

    public function test_a_corrected_call_after_a_refusal_is_stored_by_the_same_policy(): void
    {
        $this->save(
            $this->workout('**Goblet Squat:** 3 × 8–12 reps', '**Push-ups:** 3 x 12'),
            $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 15], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]),
            $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame(['Goblet Squat' => [3, 8, null], 'Push-ups' => [3, 12, null]], $this->stored(), 'Twelve is in the range, and the low end is what is stored.');
    }
}
