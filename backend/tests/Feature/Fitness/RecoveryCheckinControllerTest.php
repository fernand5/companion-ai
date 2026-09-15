<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryCheckinControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_no_content_when_no_checkin_exists_today(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/recovery-checkins')->assertNoContent();
    }

    public function test_store_creates_todays_checkin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/recovery-checkins', [
            'energy' => 2,
            'soreness' => 4,
            'motivation' => 3,
            'perceived_difficulty' => 5,
            'pain_notes' => 'Tight hamstrings',
        ]);

        $response->assertCreated()->assertJsonPath('energy', 2)->assertJsonPath('pain_notes', 'Tight hamstrings');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/recovery-checkins')
            ->assertOk()
            ->assertJsonPath('energy', 2);
    }

    public function test_store_validates_the_1_to_5_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/recovery-checkins', ['energy' => 0, 'soreness' => 3, 'motivation' => 3])
            ->assertUnprocessable();
    }

    public function test_resubmitting_today_updates_rather_than_creating_a_second_checkin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/recovery-checkins', ['energy' => 3, 'soreness' => 3, 'motivation' => 3]);
        $this->actingAs($user, 'sanctum')->postJson('/api/recovery-checkins', ['energy' => 5, 'soreness' => 1, 'motivation' => 5]);

        $this->assertSame(1, $user->recoveryCheckins()->count());
    }
}
