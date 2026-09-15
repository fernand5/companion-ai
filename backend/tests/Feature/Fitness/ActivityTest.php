<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_steps(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/activity', [
            'type' => 'steps',
            'metadata' => ['steps' => 6200],
        ]);

        $response->assertCreated()->assertJsonPath('type', 'steps');
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'steps']);
    }

    public function test_user_can_log_treadmill_with_intervals(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/activity', [
            'type' => 'treadmill',
            'duration_minutes' => 17,
            'metadata' => [
                'intervals' => [
                    ['minutes' => 12, 'speed_kmh' => 9],
                    ['minutes' => 5, 'speed_kmh' => 5.5],
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('duration_minutes', 17);
    }

    public function test_activity_type_must_be_valid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/activity', ['type' => 'not-a-real-type'])
            ->assertUnprocessable();
    }

    public function test_user_can_retrieve_and_filter_activity_history(): void
    {
        $user = User::factory()->create();
        ActivityLog::factory()->for($user)->create(['type' => 'steps', 'logged_date' => now()->toDateString()]);
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/activity?type=steps');

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_user_can_update_and_delete_their_activity_log(): void
    {
        $user = User::factory()->create();
        $log = ActivityLog::factory()->for($user)->create(['notes' => 'original']);

        $this->actingAs($user, 'sanctum')->putJson("/api/activity/{$log->id}", ['notes' => 'updated'])
            ->assertOk()->assertJsonPath('notes', 'updated');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/activity/{$log->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('activity_logs', ['id' => $log->id]);
    }

    public function test_user_cannot_access_another_users_activity_log(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $log = ActivityLog::factory()->for($owner)->create();

        $this->actingAs($intruder, 'sanctum')->getJson("/api/activity/{$log->id}")->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->putJson("/api/activity/{$log->id}", ['notes' => 'x'])->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/activity/{$log->id}")->assertForbidden();
    }
}
