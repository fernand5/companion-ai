<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Ai\FitnessContextBuilder;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A treadmill session's structured distance must reach the coach through the
 * same tools it already uses: recorded distance, plus speed and pace derived
 * from it. Missing distance stays missing; interval data is shown but never
 * overrides the recorded distance.
 */
class TreadmillActivityAiExposureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function tool(string $name, array $args = []): mixed
    {
        return app(ToolRegistry::class)->call($name, $args, $this->user);
    }

    private function logThroughTheApi(array $payload): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', $payload)->assertCreated();
    }

    public function test_a_new_treadmill_activity_exposes_its_distance_to_the_ai(): void
    {
        $this->logThroughTheApi(['type' => 'treadmill', 'duration_minutes' => 34, 'distance_km' => 5.0]);

        $log = $this->tool('get_recent_activity')[0];

        $this->assertSame('treadmill', $log['type']);
        $this->assertSame(5.0, $log['distance_km']);
        $this->assertSame(34, $log['duration_minutes']);
        $this->assertArrayNotHasKey('distance_note', $log);
    }

    public function test_a_new_treadmill_activity_exposes_derived_speed(): void
    {
        $this->logThroughTheApi(['type' => 'treadmill', 'duration_minutes' => 34, 'distance_km' => 5.0]);

        $this->assertSame(8.8, $this->tool('get_recent_activity')[0]['speed_kmh']);
    }

    public function test_a_new_treadmill_activity_exposes_derived_pace(): void
    {
        $this->logThroughTheApi(['type' => 'treadmill', 'duration_minutes' => 34, 'distance_km' => 5.0]);

        $log = $this->tool('get_recent_activity')[0];

        $this->assertSame(408, $log['pace_seconds_per_km']);
        $this->assertSame('6:48 /km', $log['pace']);
    }

    public function test_every_activity_tool_the_coach_uses_carries_the_distance(): void
    {
        $this->logThroughTheApi(['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4.0]);
        $today = $this->user->localToday();

        $results = [
            'get_recent_activity' => $this->tool('get_recent_activity')[0],
            'get_today_activity' => $this->tool('get_today_activity')[0],
            'get_activity_for_date' => $this->tool('get_activity_for_date', ['date' => $today])[0],
            'get_activity_history' => $this->tool('get_activity_history', ['type' => 'treadmill'])[0],
            'fitness context' => app(FitnessContextBuilder::class)->build($this->user, 'hi')['recent_activity'][0] ?? null,
        ];

        foreach ($results as $source => $log) {
            $this->assertNotNull($log, "{$source} returned nothing.");
            $this->assertSame(4.0, $log['distance_km'], $source);
            $this->assertSame(8.0, $log['speed_kmh'], $source);
            $this->assertSame(450, $log['pace_seconds_per_km'], $source);
        }
    }

    public function test_logging_a_treadmill_through_the_coach_tool_stores_a_structured_distance(): void
    {
        $logged = $this->tool('log_activity', ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4.0]);

        $this->assertSame(4.0, $logged['distance_km']);
        $this->assertSame(8.0, $logged['speed_kmh']);
        $this->assertSame('4.00', ActivityLog::first()->distance_km);
    }

    public function test_the_coach_tool_no_longer_steers_distance_into_metadata(): void
    {
        $declaration = collect(app(ToolRegistry::class)->declarations())->firstWhere('name', 'log_activity');

        $this->assertArrayHasKey('distance_km', $declaration['parameters']['properties']);
        $this->assertStringNotContainsString('"distance_km"', $declaration['parameters']['properties']['metadata']['description']);
    }

    public function test_a_distance_the_coach_put_in_metadata_is_promoted_to_the_column(): void
    {
        $logged = $this->tool('log_activity', ['type' => 'treadmill', 'duration_minutes' => 20, 'metadata' => ['distance_km' => 3.2]]);

        $this->assertSame(3.2, $logged['distance_km']);
        $this->assertSame(9.6, $logged['speed_kmh']);
    }

    public function test_a_missing_distance_is_reported_as_not_recorded_and_never_guessed(): void
    {
        $this->logThroughTheApi(['type' => 'treadmill', 'duration_minutes' => 15, 'intensity' => 'moderate']);

        $log = $this->tool('get_recent_activity')[0];

        $this->assertNull($log['distance_km']);
        $this->assertNull($log['speed_kmh']);
        $this->assertNull($log['pace_seconds_per_km']);
        $this->assertNull($log['pace']);
        $this->assertSame('distance not recorded', $log['distance_note']);
        $this->assertNull(ActivityLog::first()->distance_km, 'Nothing was written to the database either.');
    }

    public function test_a_nonsense_metadata_distance_is_not_promoted(): void
    {
        foreach (['far', -3, 0, 5000] as $bad) {
            $logged = $this->tool('log_activity', ['type' => 'treadmill', 'duration_minutes' => 20, 'metadata' => ['distance_km' => $bad]]);

            $this->assertNull($logged['distance_km'], 'Bad value: '.json_encode($bad));
        }
    }

    public function test_activity_types_without_a_distance_concept_do_not_grow_distance_fields(): void
    {
        $this->logThroughTheApi(['type' => 'steps', 'metadata' => ['steps' => 6000]]);

        $log = $this->tool('get_recent_activity')[0];

        $this->assertArrayNotHasKey('distance_km', $log);
        $this->assertArrayNotHasKey('speed_kmh', $log);
    }

    public function test_interval_data_is_exposed_next_to_the_recorded_distance_without_replacing_it(): void
    {
        // Real shape of an older log: 12 min @ 9 km/h + 5 min @ 5.5 km/h = 2.26 km
        // by the intervals, but the user recorded 2.6 km.
        $log = ActivityLog::factory()->for($this->user)->create([
            'type' => 'treadmill',
            'duration_minutes' => 17,
            'distance_km' => 2.6,
            'metadata' => ['intervals' => [['minutes' => 12, 'speed_kmh' => 9], ['minutes' => 5, 'speed_kmh' => 5.5]], 'distance_km' => 2.6],
        ]);

        $presented = $this->tool('get_recent_activity')[0];

        $this->assertSame(2.6, $presented['distance_km'], 'The recorded distance is authoritative.');
        $this->assertSame(2.26, $presented['interval_implied_distance_km']);
        $this->assertSame(9.2, $presented['speed_kmh'], 'Speed is from the recorded distance, not the intervals.');
        $this->assertSame([['minutes' => 12, 'speed_kmh' => 9], ['minutes' => 5, 'speed_kmh' => 5.5]], $presented['metadata']['intervals'], 'The interval metadata is preserved.');

        $this->assertSame('2.60', $log->fresh()->distance_km, 'Nothing was rewritten.');
    }

    public function test_malformed_intervals_produce_no_implied_distance(): void
    {
        ActivityLog::factory()->for($this->user)->create([
            'type' => 'treadmill', 'duration_minutes' => 17, 'distance_km' => 2.6,
            'metadata' => ['intervals' => [['minutes' => 12], 'nonsense']],
        ]);

        $this->assertArrayNotHasKey('interval_implied_distance_km', $this->tool('get_recent_activity')[0]);
    }

    public function test_the_tool_describes_distance_as_authoritative_and_intervals_as_a_cross_check(): void
    {
        $description = collect(app(ToolRegistry::class)->declarations())->firstWhere('name', 'get_recent_activity')['description'];

        $this->assertStringContainsString('distance_km is the authoritative distance', $description);
        $this->assertStringContainsString('not recorded', $description);
    }
}
