<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_a_strength_workout_with_exercises(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/workouts', [
            'duration_minutes' => 45,
            'notes' => '10 rounds of Cindy',
            'exercises' => [
                ['exercise_name' => 'Pull-ups', 'sets' => 10, 'reps' => 5],
                ['exercise_name' => 'Push-ups', 'sets' => 10, 'reps' => 10],
                ['exercise_name' => 'Squats', 'sets' => 10, 'reps' => 15],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(3, 'exercises');

        $this->assertDatabaseHas('workout_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('workout_exercises', ['exercise_name' => 'Pull-ups']);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'strength']);
    }

    public function test_logging_a_workout_mirrors_into_the_unified_activity_timeline(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/workouts', ['duration_minutes' => 30]);

        $this->actingAs($user, 'sanctum')->getJson('/api/activity?type=strength')
            ->assertOk()->assertJsonCount(1);
    }

    public function test_recent_workouts_are_listed_with_exercises(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/workouts', [
            'exercises' => [['exercise_name' => 'Deadlift', 'sets' => 5, 'reps' => 5, 'weight_kg' => 100]],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/workouts');

        $response->assertOk()->assertJsonPath('0.exercises.0.exercise_name', 'Deadlift');
    }
}
