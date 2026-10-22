<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Fitness\ExerciseHistoryService;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Services\Fitness\PerformanceComparison;
use App\Services\Fitness\PerformanceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deterministic comparisons the coach is given instead of doing arithmetic:
 * what changed between measured sessions, whether the target was reached, and
 * when two sessions are not like-for-like. Records are built through the real
 * performance endpoint, so these exercise the same data the coach sees. Facts
 * only: nothing here judges a session "better" or recommends a load.
 */
class PerformanceComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 17:00:00'); // 12:00 in Bogota

        $this->user = User::factory()->create(['timezone' => 'America/Bogota']);
    }

    // ---- helpers ---------------------------------------------------------------

    /**
     * Log one exercise the way a user does (plan row, then the per-set endpoint).
     *
     * @param  array{0: ?int, 1: ?int, 2: float|int|null, 3?: ?int}  $target  sets, reps, kg, seconds
     * @param  array<int, array<string, mixed>>  $sets
     */
    private function log(string $date, string $name, array $target, array $sets, string $type = 'strength', ?User $as = null): WorkoutPlan
    {
        $as ??= $this->user;
        $plan = WorkoutPlan::factory()->for($as)->create(['planned_date' => $date, 'activity_type' => $type]);
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => $name, 'position' => 0,
            'planned_sets' => $target[0], 'planned_reps' => $target[1], 'planned_weight_kg' => $target[2], 'planned_duration_seconds' => $target[3] ?? null,
        ]);

        $this->actingAs($as, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/performance", ['sets' => $sets])
            ->assertOk();

        return $plan;
    }

    /** @return array<int, array{reps: int, weight_kg: float|int}> */
    private function sets(int ...$reps): array
    {
        return array_map(fn ($r) => ['reps' => $r, 'weight_kg' => 40], $reps);
    }

    private function withLoad(array $sets, float $kg): array
    {
        return array_map(fn ($s) => ['weight_kg' => $kg] + $s, $sets);
    }

    private function oneTap(string $date, string $name, array $target): void
    {
        $plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => $date]);
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => $name, 'position' => 0, 'planned_sets' => $target[0], 'planned_reps' => $target[1], 'planned_weight_kg' => $target[2],
        ]);
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'completed'])->assertOk();
    }

    /** @return array<int, ExercisePerformanceRecord> newest first */
    private function records(string $slug, ?User $as = null): array
    {
        return app(ExerciseHistoryService::class)->recent($as ?? $this->user, $slug, 10, includeUnmeasured: true)
            ->map(fn ($e) => ExercisePerformanceRecord::from($e, $e->workoutSession->logged_date->toDateString()))
            ->values()->all();
    }

    private function bench(): array
    {
        return $this->records('bench-press');
    }

    // ---- improved reps at the same weight --------------------------------------------

    public function test_more_reps_at_the_same_weight_is_reported_as_exactly_that(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 9, 8));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 9));

        [$latest, $previous] = $this->bench();
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertTrue($c['comparable']);
        $this->assertSame([], $c['caveats']);
        $this->assertEquals(['completed_sets' => 0, 'total_reps' => 2, 'top_load_kg' => 0, 'volume_kg' => 80], array_intersect_key($c['change'], array_flip(['completed_sets', 'total_reps', 'top_load_kg', 'volume_kg'])));
        $this->assertSame(
            'sets 3→3 | total reps 27→29 (+2) | top load 40→40 kg (0) | volume (reps × kg) 1080→1160 kg (+80)',
            PerformanceComparison::describeStrength($c),
        );
    }

    // ---- higher weight, fewer reps ----------------------------------------------------

    public function test_a_higher_weight_with_fewer_reps_is_two_facts_not_a_verdict(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->withLoad($this->sets(8, 8, 7), 45));

        [$latest, $previous] = $this->bench();
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertEquals(-7, $c['change']['total_reps']);
        $this->assertEquals(5, $c['change']['top_load_kg']);
        $this->assertEquals(-165, $c['change']['volume_kg'], '1200 → 1035 kg: lower volume even though the load rose.');

        $line = PerformanceComparison::describeStrength($c);
        $this->assertStringContainsString('total reps 30→23 (-7)', $line);
        $this->assertStringContainsString('top load 40→45 kg (+5)', $line);
        $this->assertDoesNotMatchRegularExpression('/better|worse|stronger|weaker|improv|regress/i', $line, 'It states changes; it does not judge them.');
    }

    // ---- target attainment --------------------------------------------------------------

    public function test_a_missed_target_is_reported_per_session_from_the_stored_snapshot(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 9, 8));
        $this->log('2026-10-02', 'Squat', [3, 10, 40], $this->sets(10, 10, 10));
        $this->log('2026-10-03', 'Overhead Press', [3, 10, 40], $this->sets(10, 10));

        $this->assertSame(['met' => false, 'sets_reaching' => 1, 'of' => 3], $this->bench()[0]->targetAttainment());
        $this->assertSame(['met' => true, 'sets_reaching' => 3, 'of' => 3], $this->records('squat')[0]->targetAttainment());
        $this->assertSame(['met' => false, 'sets_reaching' => 2, 'of' => 3], $this->records('overhead-press')[0]->targetAttainment(), 'A set that was not done counts as not reached.');
        $this->assertStringContainsString('target 3×10 @ 40 kg — NOT reached (1 of 3 sets did)', $this->bench()[0]->toCompactLine());
        $this->assertStringContainsString('target 3×10 @ 40 kg — reached', $this->records('squat')[0]->toCompactLine());
    }

    public function test_a_target_is_judged_against_the_snapshot_not_the_plan_as_it_is_now(): void
    {
        $plan = $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));
        $plan->exercises[0]->update(['planned_reps' => 15, 'planned_weight_kg' => 100]);

        $this->assertTrue($this->bench()[0]->targetAttainment()['met']);
    }

    public function test_heavier_but_short_of_the_rep_target_is_not_reported_as_reached(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->withLoad($this->sets(8, 8, 7), 45));

        $this->assertSame(['met' => false, 'sets_reaching' => 0, 'of' => 3], $this->bench()[0]->targetAttainment());
    }

    public function test_attainment_is_unknown_when_it_cannot_be_judged(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], [['reps' => 10], ['reps' => 10], ['reps' => 10]]); // no weight recorded
        $this->log('2026-10-02', 'Squat', [null, null, null], $this->sets(5, 5));                              // no target at all
        $this->oneTap('2026-10-03', 'Row', [3, 12, 22.5]);                                                      // nothing measured

        $this->assertNull($this->bench()[0]->targetAttainment(), 'The target had a load; none was recorded, so it is not "not met".');
        $this->assertNull($this->records('squat')[0]->targetAttainment());
        $this->assertNull($this->records('row')[0]->targetAttainment());
    }

    // ---- trends ---------------------------------------------------------------------------

    public function test_a_consistent_direction_over_three_comparable_sessions_is_a_trend(): void
    {
        $this->log('2026-09-20', 'Bench Press', [3, 10, 40], $this->sets(8, 8, 8));
        $this->log('2026-09-27', 'Bench Press', [3, 10, 40], $this->sets(9, 9, 9));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        $series = PerformanceComparison::series($this->bench());

        $this->assertSame(3, $series['measured_sessions']);
        $this->assertTrue($series['sustained_trend_supported']);
        $this->assertSame('up', $series['consistent_direction']);
        $this->assertSame('volume up in each of the last 2 comparisons (3 measured sessions)', $series['trend']);
    }

    public function test_two_sessions_are_a_comparison_never_a_trend(): void
    {
        $this->log('2026-09-27', 'Bench Press', [3, 10, 40], $this->sets(8, 8, 8));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        $series = PerformanceComparison::series($this->bench());

        $this->assertFalse($series['sustained_trend_supported']);
        $this->assertNull($series['consistent_direction']);
        $this->assertSame('2 measured sessions: a comparison, not a trend', $series['trend']);
        $this->assertNotNull($series['comparison']);
    }

    public function test_a_single_session_supports_neither_comparison_nor_trend(): void
    {
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        $series = PerformanceComparison::series($this->bench());

        $this->assertSame(1, $series['measured_sessions']);
        $this->assertNull($series['comparison']);
        $this->assertFalse($series['sustained_trend_supported']);
        $this->assertSame('only 1 measured session: no comparison or trend', $series['trend']);
    }

    public function test_mixed_or_flat_changes_are_not_a_trend(): void
    {
        $this->log('2026-09-20', 'Bench Press', [3, 10, 40], $this->sets(8, 8, 8));
        $this->log('2026-09-27', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(9, 9, 9));

        $series = PerformanceComparison::series($this->bench());

        $this->assertFalse($series['sustained_trend_supported']);
        $this->assertStringContainsString('no consistent trend', $series['trend']);
    }

    public function test_a_different_number_of_sets_is_flagged_and_blocks_a_trend(): void
    {
        $this->log('2026-09-20', 'Bench Press', [3, 10, 40], [['reps' => 10, 'weight_kg' => 40]]);
        $this->log('2026-09-27', 'Bench Press', [3, 10, 40], $this->sets(10, 10));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        $records = $this->bench();
        $pair = PerformanceComparison::strength($records[1], $records[0]);

        $this->assertTrue($pair['comparable']);
        $this->assertFalse($pair['like_for_like']);
        $this->assertContains('the number of sets differs (2 vs 3), so totals are not like-for-like', $pair['caveats']);

        $series = PerformanceComparison::series($records);
        $this->assertFalse($series['sustained_trend_supported'], 'Volume rose each time only because more sets were done: that is not a trend.');
        $this->assertStringContainsString('not like-for-like', $series['trend']);
    }

    // ---- evidence ---------------------------------------------------------------------------

    public function test_a_one_tap_session_is_not_measured_and_is_skipped_when_finding_the_latest_two(): void
    {
        $this->log('2026-09-27', 'Bench Press', [3, 10, 40], $this->sets(8, 8, 8));
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));
        $this->oneTap('2026-10-04', 'Bench Press', [3, 10, 40]); // newest, but only acknowledged

        $records = $this->bench();
        $this->assertFalse($records[0]->performanceMeasured);

        $direct = PerformanceComparison::strength($records[1], $records[0]);
        $this->assertFalse($direct['comparable']);
        $this->assertContains('the later session has no measured performance', $direct['reasons']);

        $series = PerformanceComparison::series($records);
        $this->assertSame(2, $series['measured_sessions'], 'The acknowledgment is not a session of evidence.');
        $this->assertSame('2026-09-27', $series['comparison']['previous']['date']);
        $this->assertSame('2026-10-01', $series['comparison']['latest']['date']);
    }

    public function test_migrated_history_compares_but_is_flagged_as_possibly_incomplete(): void
    {
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        $session = WorkoutSession::factory()->for($this->user)->create(['logged_date' => '2026-09-20']);
        $legacy = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => 'Bench Press', 'recorded_as' => 'migrated']);
        foreach ([1, 2, 3] as $n) {
            WorkoutSet::factory()->for($legacy, 'exercise')->create(['set_number' => $n, 'reps' => 8, 'weight_kg' => 40]);
        }

        [$latest, $previous] = $this->bench();
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertSame('migrated', $previous->recordedAs);
        $this->assertTrue($c['comparable']);
        $this->assertSame(['migrated data may be incomplete'], $c['caveats']);
        $this->assertStringContainsString('[limited: migrated data may be incomplete]', PerformanceComparison::describeStrength($c));
    }

    public function test_an_incomplete_session_is_compared_on_what_was_completed_and_flagged(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], [['reps' => 10, 'weight_kg' => 40], ['reps' => 10, 'weight_kg' => 40], ['reps' => 4, 'weight_kg' => 40, 'completed' => false]]);
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 10));

        [$latest, $previous] = $this->bench();
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertSame(2, $c['previous']['completed_sets'], 'Only completed sets are counted.');
        $this->assertSame(20, $c['previous']['total_reps']);
        $this->assertEquals(10, $c['change']['total_reps']);
        $this->assertContains('a session was incomplete, so totals are not like-for-like', $c['caveats']);
        $this->assertSame(['met' => false, 'sets_reaching' => 2, 'of' => 3], $previous->targetAttainment());
    }

    // ---- identity and kind --------------------------------------------------------------------

    public function test_similar_exercise_names_are_never_compared(): void
    {
        $this->log('2026-10-01', 'Dumbbell Rows', [3, 12, 20], $this->sets(12, 12, 12));
        $this->log('2026-10-04', 'Dumbbell Row', [3, 12, 20], $this->sets(12, 12, 12));

        $rows = $this->records('dumbbell-rows')[0];
        $row = $this->records('dumbbell-row')[0];

        $c = PerformanceComparison::strength($rows, $row);
        $this->assertFalse($c['comparable']);
        $this->assertSame(['different exercises'], $c['reasons']);

        // Each history is its own: neither has a second session to compare.
        $this->assertSame(1, PerformanceComparison::series($this->records('dumbbell-rows'))['measured_sessions']);
        $this->assertSame(1, PerformanceComparison::series($this->records('dumbbell-row'))['measured_sessions']);

        $context = app(PerformanceContext::class)->render($this->user);
        $this->assertStringNotContainsString('Change ', $context, 'No comparison is drawn across the two names.');
    }

    public function test_a_duration_based_and_a_repetition_based_record_are_not_compared(): void
    {
        $this->log('2026-10-01', 'Plank', [3, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45], ['duration_seconds' => 40]]);
        $this->log('2026-10-04', 'Plank', [3, 10, null], [['reps' => 10], ['reps' => 10], ['reps' => 10]]);

        [$latest, $previous] = $this->records('plank');
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertFalse($c['comparable']);
        $this->assertSame(['one is duration-based and the other repetition-based'], $c['reasons']);
        $this->assertSame('not comparable: one is duration-based and the other repetition-based', PerformanceComparison::describeStrength($c));
    }

    public function test_two_duration_based_sessions_compare_on_time(): void
    {
        $this->log('2026-10-01', 'Plank', [3, null, null, 45], [['duration_seconds' => 40], ['duration_seconds' => 40], ['duration_seconds' => 35]]);
        $this->log('2026-10-04', 'Plank', [3, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45], ['duration_seconds' => 40]]);

        [$latest, $previous] = $this->records('plank');
        $line = PerformanceComparison::describeStrength(PerformanceComparison::strength($previous, $latest));

        $this->assertSame('top hold 40→45 s (+5) | total time 115→130 s (+15)', $line);
    }

    public function test_bodyweight_and_loaded_sessions_are_flagged_not_silently_compared_on_load(): void
    {
        $this->log('2026-10-01', 'Push-ups', [3, 10, null], [['reps' => 10], ['reps' => 10], ['reps' => 10]]);
        $this->log('2026-10-04', 'Push-ups', [3, 10, null], $this->withLoad($this->sets(10, 10, 10), 10));

        [$latest, $previous] = $this->records('push-ups');
        $c = PerformanceComparison::strength($previous, $latest);

        $this->assertContains('load was not recorded in both sessions', $c['caveats']);
        $this->assertNull($c['change']['top_load_kg']);
        $this->assertStringNotContainsString('top load', PerformanceComparison::describeStrength($c));
    }

    // ---- treadmill ------------------------------------------------------------------------------

    private function logRun(string $date, int $minutes, ?float $km, array $extra = []): ActivityLog
    {
        $payload = ['type' => 'treadmill', 'duration_minutes' => $minutes, 'logged_date' => $date] + ($km !== null ? ['distance_km' => $km] : []) + $extra;
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', $payload)->assertCreated();

        return ActivityLog::where('user_id', $this->user->id)->whereDate('logged_date', $date)->where('type', 'treadmill')->latest('id')->first();
    }

    public function test_two_logged_runs_compare_on_distance_time_speed_and_pace(): void
    {
        $earlier = $this->logRun('2026-09-28', 30, 4.0);
        $later = $this->logRun('2026-10-04', 32, 4.5);

        $c = PerformanceComparison::treadmill($earlier, $later);

        $this->assertTrue($c['comparable']);
        $this->assertEquals(0.5, $c['change']['distance_km']);
        $this->assertEquals(2, $c['change']['duration_minutes']);
        $this->assertEquals(0.4, $c['change']['speed_kmh']);
        $this->assertSame(-23, $c['change']['pace_seconds_per_km'], '7:30 → 7:07 per km');
        $this->assertSame('4.0→4.5 km (+0.5) | 30→32 min (+2) | 8.0→8.4 km/h (+0.4) | 7:30→7:07/km (23 s/km faster)', PerformanceComparison::describeTreadmill($c));
    }

    public function test_a_run_with_no_distance_is_not_compared_and_its_distance_is_not_guessed(): void
    {
        $earlier = $this->logRun('2026-09-28', 30, null);
        $later = $this->logRun('2026-10-04', 32, 4.5);

        $c = PerformanceComparison::treadmill($earlier, $later);

        $this->assertFalse($c['comparable']);
        $this->assertSame(['the earlier run has no distance recorded'], $c['reasons']);
        $this->assertSame([], $c['change']);
        $this->assertNull($earlier->distance_km);
    }

    public function test_a_plan_completion_entry_is_not_a_run(): void
    {
        $plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-30', 'activity_type' => 'treadmill']);
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => 'Easy Treadmill', 'position' => 0, 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 900]);
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'completed'])->assertOk();

        $mirror = ActivityLog::where('user_id', $this->user->id)->where('type', 'treadmill')->sole();
        $run = $this->logRun('2026-10-04', 32, 4.5);

        $c = PerformanceComparison::treadmill($mirror->load('completedPlan'), $run);

        $this->assertFalse($c['comparable']);
        $this->assertStringContainsString('only marks a plan as completed; nothing was measured', $c['reasons'][0]);
    }

    public function test_interval_runs_and_very_different_durations_are_flagged(): void
    {
        $earlier = $this->logRun('2026-09-28', 17, 2.6, ['metadata' => ['intervals' => [['minutes' => 12, 'speed_kmh' => 9], ['minutes' => 5, 'speed_kmh' => 5.5]]]]);
        $later = $this->logRun('2026-10-04', 40, 6.0);

        $c = PerformanceComparison::treadmill($earlier, $later);

        $this->assertTrue($c['comparable']);
        $this->assertContains('an interval session: average speed and pace blend the segments', $c['caveats']);
        $this->assertContains('durations differ a lot (17 vs 40 min)', $c['caveats']);
        $this->assertStringContainsString('[limited:', PerformanceComparison::describeTreadmill($c));
    }

    // ---- isolation ------------------------------------------------------------------------------

    public function test_comparisons_never_cross_users(): void
    {
        $this->log('2026-10-01', 'Bench Press', [3, 10, 40], $this->sets(10, 9, 8));
        $this->log('2026-10-04', 'Bench Press', [3, 10, 40], $this->sets(10, 10, 9));

        $other = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->log('2026-10-03', 'Bench Press', [3, 5, 100], [['reps' => 5, 'weight_kg' => 100], ['reps' => 5, 'weight_kg' => 100], ['reps' => 5, 'weight_kg' => 100]], 'strength', $other);

        $theirs = PerformanceComparison::series($this->records('bench-press', $other));
        $this->assertSame(1, $theirs['measured_sessions'], 'The other user has one session of their own, none of this user\'s.');
        $this->assertNull($theirs['comparison']);

        $context = app(PerformanceContext::class)->render($other);
        $this->assertStringContainsString('Only 1 measured session', $context);
        $this->assertStringNotContainsString('1080', $context);
        $this->assertStringNotContainsString('Change', $context);

        $mine = app(PerformanceContext::class)->render($this->user);
        $this->assertStringNotContainsString('100 kg', $mine);
    }
}
