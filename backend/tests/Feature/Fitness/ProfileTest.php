<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_has_no_profile_yet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertNoContent();
    }

    public function test_user_can_create_their_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'height_cm' => 168,
            'weight_kg' => 86.5,
            'age' => 30,
            'fitness_level' => 'intermediate',
            'primary_goal' => 'Lose body fat while maintaining muscle',
            'equipment' => ['dumbbells', 'treadmill'],
            'preferred_training_days' => [1, 3, 5],
            'preferred_training_duration_minutes' => 45,
        ]);

        $response->assertCreated()->assertJsonPath('primary_goal', 'Lose body fat while maintaining muscle');

        $this->assertDatabaseHas('fitness_profiles', ['user_id' => $user->id, 'age' => 30]);
    }

    public function test_user_can_update_their_existing_profile(): void
    {
        $user = User::factory()->has(FitnessProfile::factory(), 'fitnessProfile')->create();

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'weight_kg' => 84.0,
        ]);

        $response->assertOk();
        $this->assertEquals(84.0, $response->json('weight_kg'));
        $this->assertDatabaseHas('fitness_profiles', ['user_id' => $user->id, 'weight_kg' => 84.0]);
        $this->assertSame(1, $user->fresh()->fitnessProfile()->count());
    }

    public function test_profile_validation_rejects_bad_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['weight_kg' => -5])
            ->assertUnprocessable();
    }
}
