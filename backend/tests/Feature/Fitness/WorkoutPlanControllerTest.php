<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_no_content_when_no_plan_exists_for_the_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/workout-plans')->assertNoContent();
    }

    public function test_index_returns_the_plan_for_a_given_date(): void
    {
        $user = User::factory()->create();
        WorkoutPlan::factory()->for($user)->create(['planned_date' => '2026-09-15', 'title' => 'Leg Day']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/workout-plans?date=2026-09-15')
            ->assertOk()
            ->assertJsonPath('title', 'Leg Day');
    }

    public function test_store_creates_a_manual_plan(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/workout-plans', [
            'activity_type' => 'strength',
            'title' => 'My Own Plan',
            'exercises' => [['exercise_name' => 'Squat']],
        ]);

        $response->assertCreated()->assertJsonPath('title', 'My Own Plan')->assertJsonPath('source', 'manual');
        $this->assertDatabaseHas('workout_plans', ['user_id' => $user->id, 'source' => 'manual']);
    }

    public function test_update_exercise_marks_status_and_recomputes_plan(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('workout_plan_exercises', ['id' => $exercise->id, 'status' => 'completed']);
    }

    public function test_update_exercise_is_forbidden_for_another_users_plan(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($owner)->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_update_exercise_404s_when_exercise_does_not_belong_to_the_url_plan(): void
    {
        $user = User::factory()->create();
        $planA = WorkoutPlan::factory()->for($user)->create(['planned_date' => '2026-09-10']);
        $planB = WorkoutPlan::factory()->for($user)->create(['planned_date' => '2026-09-11']);
        $exerciseOnB = WorkoutPlanExercise::factory()->for($planB, 'workoutPlan')->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/workout-plans/{$planA->id}/exercises/{$exerciseOnB->id}", ['status' => 'completed'])
            ->assertNotFound();
    }
}
