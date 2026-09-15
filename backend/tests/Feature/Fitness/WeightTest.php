<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_a_weight_entry_and_it_updates_the_profile(): void
    {
        $user = User::factory()->has(FitnessProfile::factory()->state(['weight_kg' => 90]), 'fitnessProfile')->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/weight', ['weight_kg' => 86.5]);

        $response->assertCreated()->assertJsonPath('metadata.weight_kg', 86.5);
        $this->assertEquals(86.5, (float) $user->fresh()->fitnessProfile->weight_kg);
    }

    public function test_weight_history_is_ordered_most_recent_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/weight', ['weight_kg' => 90, 'logged_date' => now()->subDays(10)->toDateString()]);
        $this->actingAs($user, 'sanctum')->postJson('/api/weight', ['weight_kg' => 88, 'logged_date' => now()->subDays(5)->toDateString()]);
        $this->actingAs($user, 'sanctum')->postJson('/api/weight', ['weight_kg' => 86.5, 'logged_date' => now()->toDateString()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/weight');

        $response->assertOk()->assertJsonPath('0.metadata.weight_kg', 86.5);
    }
}
