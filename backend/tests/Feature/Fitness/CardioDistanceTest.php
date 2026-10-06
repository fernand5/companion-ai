<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Fitness\ActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CardioDistanceTest extends TestCase
{
    use RefreshDatabase;

    private function log(User $user, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/activity', $payload);
    }

    public function test_a_treadmill_session_records_distance_and_duration(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['type' => 'treadmill', 'duration_minutes' => 32, 'distance_km' => 5.2, 'intensity' => 'moderate'])
            ->assertCreated()
            ->assertJsonPath('distance_km', 5.2)
            ->assertJsonPath('duration_minutes', 32);

        $log = ActivityLog::where('user_id', $user->id)->first();
        $this->assertSame('5.20', $log->distance_km);
        $this->assertSame(32, $log->duration_minutes);
    }

    public function test_speed_and_pace_are_derived_not_stored(): void
    {
        $user = User::factory()->create();

        $response = $this->log($user, ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4.0])->assertCreated();

        // 4 km in 30 min = 8 km/h = 7:30 per km (450 s).
        $this->assertEquals(8.0, $response->json('speed_kmh'));
        $this->assertSame(450, $response->json('pace_seconds_per_km'));
        $this->assertArrayNotHasKey('speed_kmh', ActivityLog::first()->getAttributes());
    }

    public function test_a_week_over_week_progression_is_visible_in_the_data(): void
    {
        $user = User::factory()->create();

        foreach ([['2026-09-14', 4.0, 30], ['2026-09-21', 4.5, 32], ['2026-09-28', 5.0, 34]] as [$date, $km, $min]) {
            $this->log($user, ['type' => 'treadmill', 'logged_date' => $date, 'duration_minutes' => $min, 'distance_km' => $km])->assertCreated();
        }

        $rows = $this->actingAs($user, 'sanctum')->getJson('/api/activity?type=treadmill')->assertOk()->json();

        $this->assertEquals([5.0, 4.5, 4.0], array_column($rows, 'distance_km'));
        $this->assertEquals([34, 32, 30], array_column($rows, 'duration_minutes'));
    }

    public function test_speed_and_pace_are_null_when_they_cannot_be_derived(): void
    {
        $user = User::factory()->create();

        $noDistance = $this->log($user, ['type' => 'treadmill', 'duration_minutes' => 20])->assertCreated();
        $noDuration = $this->log($user, ['type' => 'treadmill', 'distance_km' => 3])->assertCreated();

        $this->assertNull($noDistance->json('distance_km'));
        $this->assertNull($noDistance->json('speed_kmh'));
        $this->assertNull($noDuration->json('speed_kmh'));
        $this->assertNull($noDuration->json('pace_seconds_per_km'));
    }

    public function test_existing_logs_without_distance_remain_valid(): void
    {
        $user = User::factory()->create();
        ActivityLog::factory()->for($user)->create(['type' => 'treadmill', 'duration_minutes' => 20, 'metadata' => null]);

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/activity')->assertOk()->json('0');

        $this->assertNull($row['distance_km']);
        $this->assertSame(20, $row['duration_minutes']);
    }

    public function test_sport_sessions_may_record_a_distance(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['type' => 'sport', 'duration_minutes' => 60, 'distance_km' => 7.4, 'metadata' => ['sport' => 'Soccer']])
            ->assertCreated()
            ->assertJsonPath('distance_km', 7.4);
    }

    public function test_distance_is_validated(): void
    {
        $user = User::factory()->create();

        $this->log($user, ['type' => 'treadmill', 'distance_km' => -1])->assertUnprocessable()->assertJsonValidationErrors('distance_km');
        $this->log($user, ['type' => 'treadmill', 'distance_km' => 1001])->assertUnprocessable()->assertJsonValidationErrors('distance_km');
        $this->log($user, ['type' => 'treadmill', 'distance_km' => 'far'])->assertUnprocessable()->assertJsonValidationErrors('distance_km');
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_distance_is_refused_for_activity_types_it_does_not_apply_to(): void
    {
        $user = User::factory()->create();

        foreach (['steps', 'strength', 'recovery'] as $type) {
            $this->log($user, ['type' => $type, 'distance_km' => 3])->assertUnprocessable()->assertJsonValidationErrors('distance_km');
        }
    }

    public function test_the_service_enforces_the_same_rules_for_ai_and_internal_callers(): void
    {
        $user = User::factory()->create();

        $log = app(ActivityService::class)->log($user, ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4.5]);
        $this->assertSame('4.50', $log->fresh()->distance_km);

        $this->expectException(ValidationException::class);
        app(ActivityService::class)->log($user, ['type' => 'steps', 'distance_km' => 4.5]);
    }

    public function test_distance_can_be_corrected_on_a_treadmill_log_but_not_added_to_steps(): void
    {
        $user = User::factory()->create();
        $treadmill = ActivityLog::factory()->for($user)->create(['type' => 'treadmill', 'duration_minutes' => 30, 'metadata' => null]);
        $steps = ActivityLog::factory()->for($user)->create(['type' => 'steps']);

        $this->actingAs($user, 'sanctum')->putJson("/api/activity/{$treadmill->id}", ['distance_km' => 4.2])
            ->assertOk()->assertJsonPath('distance_km', 4.2);
        $this->actingAs($user, 'sanctum')->putJson("/api/activity/{$steps->id}", ['distance_km' => 4.2])
            ->assertUnprocessable()->assertJsonValidationErrors('distance_km');
    }

    public function test_another_users_log_cannot_be_modified(): void
    {
        $owner = User::factory()->create();
        $log = ActivityLog::factory()->for($owner)->create(['type' => 'treadmill', 'metadata' => null]);

        $this->actingAs(User::factory()->create(), 'sanctum')->putJson("/api/activity/{$log->id}", ['distance_km' => 9])->assertForbidden();
        $this->assertNull($log->fresh()->distance_km);
    }
}
