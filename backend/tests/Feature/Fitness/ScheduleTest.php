<?php

namespace Tests\Feature\Fitness;

use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_recurring_schedule_entry(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/schedule', [
            'activity_type' => 'Football',
            'day_of_week' => 2,
            'start_time' => '20:00',
            'expected_duration_minutes' => 90,
            'intensity' => 'high',
        ]);

        $response->assertCreated()->assertJsonPath('activity_type', 'Football');
        $this->assertDatabaseHas('training_schedules', ['user_id' => $user->id, 'day_of_week' => 2]);
    }

    public function test_user_can_list_update_and_delete_their_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = TrainingSchedule::factory()->for($user)->create(['activity_type' => 'Football']);

        $this->actingAs($user, 'sanctum')->getJson('/api/schedule')
            ->assertOk()->assertJsonCount(1);

        $this->actingAs($user, 'sanctum')->putJson("/api/schedule/{$schedule->id}", ['activity_type' => 'Futsal'])
            ->assertOk()->assertJsonPath('activity_type', 'Futsal');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/schedule/{$schedule->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('training_schedules', ['id' => $schedule->id]);
    }

    public function test_user_cannot_modify_another_users_schedule(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $schedule = TrainingSchedule::factory()->for($owner)->create();

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/schedule/{$schedule->id}", ['activity_type' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/schedule/{$schedule->id}")
            ->assertForbidden();
    }
}
