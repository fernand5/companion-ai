<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A one-tap "done" only assumes the plan's targets. The AI's existing read
 * tools must never present those numbers as performance the user measured.
 */
class AssumedPerformanceIsNotMeasuredTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutPlan $plan;

    private WorkoutPlanExercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => $this->user->localToday()]);
        $this->exercise = WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')->create([
            'exercise_name' => 'Bench Press', 'position' => 0, 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40,
        ]);
    }

    private function tool(string $name, array $args = []): mixed
    {
        return app(ToolRegistry::class)->call($name, $args, $this->user);
    }

    private function markExercise(string $status): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson(
            "/api/workout-plans/{$this->plan->id}/exercises/{$this->exercise->id}",
            ['status' => $status],
        )->assertOk();
    }

    private function enter(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$this->plan->id}/exercises/{$this->exercise->id}/performance",
            ['sets' => [['reps' => 10, 'weight_kg' => 42.5], ['reps' => 10, 'weight_kg' => 42.5], ['reps' => 8, 'weight_kg' => 42.5]]],
        )->assertOk();
    }

    public function test_a_one_tap_completion_shows_no_performance_numbers_to_the_ai(): void
    {
        $this->markExercise('completed');

        $exercise = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame('as_planned', $exercise['recorded_as']);
        $this->assertNull($exercise['sets']);
        $this->assertNull($exercise['reps']);
        $this->assertNull($exercise['weight_kg']);
    }

    public function test_the_plan_tool_shows_no_actuals_for_a_one_tap_completion(): void
    {
        $this->markExercise('completed');

        $exercise = $this->tool('get_todays_plan')['exercises'][0];

        $this->assertSame('completed', $exercise['status']);
        $this->assertNull($exercise['actual_sets']);
        $this->assertNull($exercise['actual_reps']);
        $this->assertNull($exercise['actual_weight_kg']);
        $this->assertSame(10, $exercise['planned_reps']);
    }

    public function test_entered_numbers_are_shown_as_performance(): void
    {
        $this->enter();

        $workout = $this->tool('get_recent_workouts')[0]['exercises'][0];
        $this->assertSame('entered', $workout['recorded_as']);
        $this->assertSame(3, $workout['sets']);
        $this->assertSame(10, $workout['reps']);
        $this->assertSame(42.5, $workout['weight_kg']);

        $plan = $this->tool('get_todays_plan')['exercises'][0];
        $this->assertSame(10, $plan['actual_reps']);
        $this->assertSame(42.5, $plan['actual_weight_kg']);
        $this->assertSame(10, $plan['planned_reps'], 'Planned and actual are reported separately.');
    }

    public function test_taking_the_one_tap_back_removes_it_from_what_the_ai_sees(): void
    {
        $this->markExercise('completed');
        $this->markExercise('pending');

        $this->assertSame([], $this->tool('get_recent_workouts'));
    }
}
