<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Support\LegacyPerformanceBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-time conversion of pre-existing rows. The contract: nothing is
 * invented, and nothing plan-synced is ever promoted to "measured".
 */
class LegacyPerformanceBackfillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeSession(string $date, ?string $notes = null): int
    {
        return DB::table('workout_sessions')->insertGetId([
            'user_id' => $this->user->id, 'logged_date' => $date, 'duration_minutes' => 45, 'notes' => $notes,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacyExercise(int $sessionId, string $name, ?int $sets, ?int $reps, ?float $weight, ?int $duration = null, int $position = 0): int
    {
        return DB::table('workout_exercises')->insertGetId([
            'workout_session_id' => $sessionId, 'exercise_name' => $name, 'sets' => $sets, 'reps' => $reps,
            'weight_kg' => $weight, 'duration_seconds' => $duration, 'position' => $position,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('workout_exercises')->find($id);
    }

    private function setsOf(int $id): array
    {
        return DB::table('workout_sets')->where('workout_exercise_id', $id)->orderBy('set_number')->get()->all();
    }

    // ---- Genuine freeform records -> migrated ----------------------------------

    public function test_a_freeform_log_becomes_migrated_sets_exactly_as_claimed(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10'), 'Bench Press', 3, 10, 40.0);

        LegacyPerformanceBackfill::run();

        $row = $this->row($id);
        $this->assertSame('migrated', $row->recorded_as);
        $this->assertSame('bench-press', $row->exercise_slug);
        $sets = $this->setsOf($id);
        $this->assertCount(3, $sets);
        $this->assertSame([10, 10, 10], array_map(fn ($s) => (int) $s->reps, $sets));
        $this->assertSame([40.0, 40.0, 40.0], array_map(fn ($s) => (float) $s->weight_kg, $sets));
    }

    public function test_a_freeform_log_with_notes_on_a_day_without_a_plan_is_still_migrated(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10', 'Felt strong'), 'Row', 3, 8, 30.0);

        LegacyPerformanceBackfill::run();

        $this->assertSame('migrated', $this->row($id)->recorded_as);
    }

    public function test_session_notes_of_null_prove_it_was_not_plan_synced_even_on_a_plan_day(): void
    {
        WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-10']);
        $id = $this->legacyExercise($this->makeSession('2026-09-10', null), 'Row', 3, 8, 30.0);

        LegacyPerformanceBackfill::run();

        $this->assertSame('migrated', $this->row($id)->recorded_as);
    }

    // ---- Plan-synced data is never promoted to measured ------------------------

    public function test_an_orphaned_plan_synced_session_is_as_planned_not_migrated(): void
    {
        // Plan syncing always wrote the plan title as the session notes, and the plan still exists for that date.
        WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-11', 'title' => 'Upper Body']);
        $id = $this->legacyExercise($this->makeSession('2026-09-11', 'Upper Body'), 'Bench Press', 3, 10, 40.0);

        LegacyPerformanceBackfill::run();

        $this->assertSame('as_planned', $this->row($id)->recorded_as);
        $this->assertFalse(WorkoutExercise::find($id)->isMeasured());
        $this->assertCount(3, $this->setsOf($id), 'The values are kept, labelled as assumed.');
    }

    public function test_exercises_the_plan_skipped_were_never_performed_and_get_no_sets(): void
    {
        $sessionId = $this->makeSession('2026-09-12', 'Leg Day');
        $plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-12', 'workout_session_id' => $sessionId]);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => 'Squat', 'position' => 0, 'status' => 'completed', 'planned_sets' => 3, 'planned_reps' => 5, 'planned_weight_kg' => 80]);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => 'Lunge', 'position' => 1, 'status' => 'skipped']);
        $done = $this->legacyExercise($sessionId, 'Squat', 3, 5, 80.0, null, 0);
        $skipped = $this->legacyExercise($sessionId, 'Lunge', 3, 10, 20.0, null, 1);

        $stats = LegacyPerformanceBackfill::run();

        $this->assertSame('as_planned', $this->row($done)->recorded_as);
        $this->assertCount(3, $this->setsOf($done));
        $this->assertSame('as_planned', $this->row($skipped)->recorded_as);
        $this->assertCount(0, $this->setsOf($skipped), 'A skipped exercise was never performed.');
        $this->assertSame(1, $stats['not_performed_no_sets']);
    }

    public function test_a_plan_synced_exercise_is_linked_and_its_target_snapshotted(): void
    {
        $sessionId = $this->makeSession('2026-09-12', 'Leg Day');
        $plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-12', 'workout_session_id' => $sessionId]);
        $planExercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => 'Squat', 'position' => 0, 'status' => 'completed', 'planned_sets' => 3, 'planned_reps' => 5, 'planned_weight_kg' => 80,
        ]);
        $id = $this->legacyExercise($sessionId, 'Squat', 3, 5, 80.0);

        LegacyPerformanceBackfill::run();

        $row = $this->row($id);
        $this->assertSame($planExercise->id, (int) $row->workout_plan_exercise_id);
        $this->assertSame(5, (int) $row->planned_reps);
        $this->assertSame(80.0, (float) $row->planned_weight_kg);
    }

    public function test_real_actuals_on_the_plan_exercise_make_the_row_migrated(): void
    {
        $sessionId = $this->makeSession('2026-09-12', 'Leg Day');
        $plan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-12', 'workout_session_id' => $sessionId]);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => 'Squat', 'position' => 0, 'status' => 'completed', 'actual_sets' => 3, 'actual_reps' => 6, 'actual_weight_kg' => 85,
        ]);
        $id = $this->legacyExercise($sessionId, 'Squat', 3, 6, 85.0);

        LegacyPerformanceBackfill::run();

        $this->assertSame('migrated', $this->row($id)->recorded_as);
    }

    // ---- Nothing is invented -----------------------------------------------------

    public function test_an_unknown_set_count_creates_no_sets(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10'), 'Curl', null, 12, 15.0);

        $stats = LegacyPerformanceBackfill::run();

        $this->assertSame('migrated', $this->row($id)->recorded_as);
        $this->assertCount(0, $this->setsOf($id));
        $this->assertSame(1, $stats['no_recoverable_sets']);
    }

    public function test_a_bare_set_count_with_no_values_creates_no_sets(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10'), 'Pull-ups', 10, null, null);

        LegacyPerformanceBackfill::run();

        $this->assertCount(0, $this->setsOf($id));
    }

    public function test_duration_based_exercises_become_duration_sets(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10'), 'Plank', 3, null, null, 60);

        LegacyPerformanceBackfill::run();

        $sets = $this->setsOf($id);
        $this->assertCount(3, $sets);
        $this->assertSame(60, (int) $sets[0]->duration_seconds);
        $this->assertNull($sets[0]->reps);
    }

    public function test_the_existing_summary_columns_are_left_untouched(): void
    {
        $id = $this->legacyExercise($this->makeSession('2026-09-10'), 'Bench Press', 3, 10, 40.0);

        LegacyPerformanceBackfill::run();

        $row = $this->row($id);
        $this->assertSame(3, (int) $row->sets);
        $this->assertSame(10, (int) $row->reps);
        $this->assertSame(40.0, (float) $row->weight_kg);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->legacyExercise($this->makeSession('2026-09-10'), 'Bench Press', 3, 10, 40.0);

        LegacyPerformanceBackfill::run();
        $setsAfterFirst = DB::table('workout_sets')->count();
        $second = LegacyPerformanceBackfill::run();

        $this->assertSame($setsAfterFirst, DB::table('workout_sets')->count());
        $this->assertSame(0, $second['exercises_processed']);
    }

    // ---- Treadmill distance -------------------------------------------------------

    public function test_treadmill_distance_is_recovered_from_metadata_without_touching_it(): void
    {
        $log = ActivityLog::factory()->for($this->user)->create([
            'type' => 'treadmill', 'duration_minutes' => 30,
            'metadata' => ['distance_km' => 4.2, 'intervals' => [['minutes' => 12, 'speed_kmh' => 9]]],
        ]);

        $stats = LegacyPerformanceBackfill::run();

        $fresh = $log->fresh();
        $this->assertSame('4.20', $fresh->distance_km);
        $this->assertSame(4.2, $fresh->metadata['distance_km']);
        $this->assertCount(1, $fresh->metadata['intervals']);
        $this->assertSame(1, $stats['distance_backfilled']);
    }

    public function test_unusable_distances_and_other_activity_types_are_left_alone(): void
    {
        $text = ActivityLog::factory()->for($this->user)->create(['type' => 'treadmill', 'metadata' => ['distance_km' => 'about 5']]);
        $zero = ActivityLog::factory()->for($this->user)->create(['type' => 'treadmill', 'metadata' => ['distance_km' => 0]]);
        $none = ActivityLog::factory()->for($this->user)->create(['type' => 'treadmill', 'metadata' => null]);
        $steps = ActivityLog::factory()->for($this->user)->create(['type' => 'steps', 'metadata' => ['steps' => 9000, 'distance_km' => 6]]);

        LegacyPerformanceBackfill::run();

        foreach ([$text, $zero, $none, $steps] as $log) {
            $this->assertNull($log->fresh()->distance_km);
        }
    }

    // ---- Through the app ----------------------------------------------------------

    public function test_migrated_rows_show_up_in_history_and_assumed_ones_are_flagged(): void
    {
        $this->legacyExercise($this->makeSession('2026-09-10'), 'Bench Press', 3, 10, 40.0);
        WorkoutPlan::factory()->for($this->user)->create(['planned_date' => '2026-09-11', 'title' => 'Upper Body']);
        $this->legacyExercise($this->makeSession('2026-09-11', 'Upper Body'), 'Bench Press', 3, 10, 40.0);
        LegacyPerformanceBackfill::run();

        $sessions = $this->actingAs($this->user, 'sanctum')->getJson('/api/exercises/bench-press/history')->assertOk()->json('sessions');

        $this->assertSame(['as_planned', 'migrated'], array_column($sessions, 'recorded_as'));
    }
}
