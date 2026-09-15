<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdherenceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_a_series_and_a_summary(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->completed()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/adherence?weeks=4');

        $response->assertOk()
            ->assertJsonCount(4, 'series')
            ->assertJsonStructure(['series', 'summary' => ['adherence_pct', 'active_days']]);
    }

    public function test_index_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $plan = WorkoutPlan::factory()->for($owner)->create();
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->completed()->create();

        $response = $this->actingAs($intruder, 'sanctum')->getJson('/api/adherence');

        $response->assertOk()->assertJsonPath('summary.due_exercise_count', 0);
    }
}
