<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 5C: a load only goes up when the user approves a calculated suggestion (5B) or asks for the
 * change in so many words. Everything else the model writes (weekly generation and adaptation, a
 * session for today, a reaction to a logged activity) may keep or lower a load, never raise one.
 * These tests play a model that tries anyway.
 */
class NoUnapprovedLoadRaisesTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $day;

    private string $otherDay;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        // The user did 3x10 @ 40 kg three times: the engine would suggest 42.5 kg for next time.
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }

        $this->day = now()->startOfWeek()->addDays(5)->toDateString();      // Saturday of this week
        $this->otherDay = now()->startOfWeek()->addDays(6)->toDateString(); // Sunday

        foreach ([$this->day, $this->otherDay] as $date) {
            app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => "Bench {$date}",
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
            ]);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function weekly(string $date, float $kg): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'Adjusting.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => "Rewritten {$date}", 'reason' => 'test',
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => $kg]]]],
        ])]);
    }

    private function create(string $date, float $kg, string $title = 'Written'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $date,
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => $kg]],
        ])]);
    }

    private function adapt(string $input = 'Rework my week.')
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => $input])->assertOk();
    }

    private function chat(string $message, AiResponse ...$responses): Message
    {
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message);
        $this->assertNull($result['error'], (string) $result['error']);

        return Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    private function load(string $date): float
    {
        return (float) WorkoutPlanExercise::query()
            ->whereHas('workoutPlan', fn ($q) => $q->where('user_id', $this->user->id)->whereDate('planned_date', $date))
            ->where('exercise_slug', 'bench-press')->value('planned_weight_kg');
    }

    private function titled(string $title): int
    {
        return WorkoutPlan::where('user_id', $this->user->id)->where('title', $title)->count();
    }

    // ---- weekly generation and adaptation ------------------------------------------------------------------

    public function test_weekly_adaptation_cannot_raise_a_load_not_even_to_what_the_engine_would_suggest(): void
    {
        foreach ([42.5, 41.0, 40.5] as $kg) {
            $this->fake->queue($this->weekly($this->day, $kg));
            $this->adapt()->assertJsonPath('applied', false);

            $this->assertSame(0, $this->titled("Rewritten {$this->day}"), "{$kg} kg is a raise.");
        }

        $this->assertEquals(40.0, $this->load($this->day));
    }

    public function test_the_refusal_tells_the_model_why_and_where_a_raise_belongs(): void
    {
        $this->fake->queue($this->weekly($this->day, 42.5));
        $result = $this->adapt()->json();

        $this->assertStringContainsString('would raise its load', $result['error']);
        $this->assertStringContainsString('approves a calculated suggestion', $result['error']);
    }

    public function test_weekly_adaptation_may_keep_or_lower_a_load(): void
    {
        $this->fake->queue($this->weekly($this->day, 40.0));
        $this->adapt()->assertJsonPath('applied', true);
        $this->assertSame(1, $this->titled("Rewritten {$this->day}"));

        $this->fake->queue($this->weekly($this->otherDay, 37.5));
        $this->adapt()->assertJsonPath('applied', true);
        $this->assertSame(37.5, $this->load($this->otherDay));
    }

    public function test_an_approved_increase_is_carried_forward_on_its_own_day_but_cannot_be_copied_to_another(): void
    {
        $proposal = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->json('data'))->firstWhere('exercise.name', 'Bench Press')['proposal']['id'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => $proposal])->assertOk();
        $this->assertSame(42.5, $this->load($this->day));
        $this->assertEquals(40.0, $this->load($this->otherDay));

        // Rewriting the approved day keeps the approved load...
        $this->fake->queue($this->weekly($this->day, 42.5));
        $this->adapt()->assertJsonPath('applied', true);
        $this->assertSame(42.5, $this->load($this->day));

        // ...but the next day cannot be quietly brought up to it.
        $this->fake->queue($this->weekly($this->otherDay, 42.5));
        $this->adapt()->assertJsonPath('applied', false);
        $this->assertEquals(40.0, $this->load($this->otherDay));
    }

    // ---- chat ------------------------------------------------------------------------------------------------

    public function test_a_session_for_today_cannot_raise_a_load(): void
    {
        $turn = $this->chat('What should I do today?', $this->create(now()->toDateString(), 42.5, 'Today raised'), new AiResponse(text: 'Here you go.'));

        $this->assertSame(0, $this->titled('Today raised'));
        $this->assertSame('load_above_limit', $turn->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_a_session_for_today_at_the_last_performed_load_is_fine(): void
    {
        $this->chat('What should I do today?', $this->create(now()->toDateString(), 40.0, 'Today steady'), new AiResponse(text: 'Here you go.'));

        $this->assertSame(1, $this->titled('Today steady'));
    }

    public function test_reacting_to_a_logged_activity_cannot_raise_a_load(): void
    {
        $turn = $this->chat('I played soccer yesterday.', $this->weekly($this->day, 42.5), new AiResponse(text: 'Noted.'));

        $this->assertEquals(40.0, $this->load($this->day));
        $this->assertSame('load_above_limit', $turn->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_the_coach_applying_a_calculated_suggestion_on_its_own_is_refused(): void
    {
        config(['ai.progression_tool_enabled' => true]);
        $this->app->forgetInstance(ToolRegistry::class);

        $turn = $this->chat(
            'What should I do today?',
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 't', name: 'get_progression_recommendation', arguments: ['exercise_name' => 'Bench Press'])]),
            $this->create(now()->toDateString(), 42.5, 'Applied suggestion'),
            new AiResponse(text: 'Applied the suggested 42.5 kg.'),
        );

        $this->assertSame(0, $this->titled('Applied suggestion'), 'A calculated suggestion is not a permission.');
        $this->assertEquals(40.0, $this->load($this->day));
        $this->assertSame('load_above_limit', $turn->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_an_explicit_request_may_still_raise_a_load_within_the_maximum_permitted_change(): void
    {
        $this->chat('Please update my plan for the weekend: bench at 44 kg.', $this->create($this->d(-6), 44.0, 'Asked for it'), new AiResponse(text: 'Done.'));
        $this->assertSame(1, $this->titled('Asked for it'));

        $turn = $this->chat('Please update my plan for next week: bench at 60 kg.', $this->create($this->d(-8), 60.0, 'Too far'), new AiResponse(text: 'Done.'));
        $this->assertSame(0, $this->titled('Too far'));
        $this->assertStringContainsString('maximum permitted change', json_encode($turn->meta['tool_calls'][0]['result']));
    }

    public function test_a_new_exercise_with_no_history_is_planned_without_a_model_chosen_load(): void
    {
        $this->chat('What should I do today?', new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'n', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'New lift', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => now()->toDateString(),
            'exercises' => [['exercise_name' => 'Zercher Squat', 'planned_sets' => 3, 'planned_reps' => 5, 'planned_weight_kg' => 60]],
        ])]), new AiResponse(text: 'ok'));

        $this->assertSame(1, $this->titled('New lift'), 'The session is still planned.');
        $this->assertNull(WorkoutPlanExercise::where('exercise_name', 'Zercher Squat')->value('planned_weight_kg'));
    }

    // ---- nothing runs unattended ---------------------------------------------------------------------------------

    public function test_nothing_is_scheduled_that_could_change_a_load_unattended(): void
    {
        $this->assertSame([], app(Schedule::class)->events(), 'No scheduled task exists.');
        $this->assertDirectoryDoesNotExist(app_path('Jobs'));
        $this->assertDirectoryDoesNotExist(app_path('Console/Commands'));
    }
}
