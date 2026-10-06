<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the AI can read about past performance: exact per-set actuals, the
 * planned target as snapshotted back then, how reliable the numbers are, and
 * whether the exercise was actually done. Read-only; nothing here decides
 * anything about progression.
 */
class PerformanceHistoryAiToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function tool(string $name, array $args = [], ?User $as = null): mixed
    {
        return app(ToolRegistry::class)->call($name, $args, $as ?? $this->user);
    }

    /** A plan with one exercise, for the given date. */
    private function plannedExercise(string $date, string $name = 'Bench Press', array $planned = ['planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40], ?User $user = null): array
    {
        $user ??= $this->user;
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => $date]);
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => $name, 'position' => 0] + $planned);

        return [$plan, $exercise];
    }

    private function enter(WorkoutPlan $plan, WorkoutPlanExercise $exercise, array $sets, ?User $user = null): void
    {
        $this->actingAs($user ?? $this->user, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/performance", ['sets' => $sets])
            ->assertOk();
    }

    private function oneTap(WorkoutPlan $plan, WorkoutPlanExercise $exercise, string $status, ?User $user = null): void
    {
        $this->actingAs($user ?? $this->user, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => $status])
            ->assertOk();
    }

    /**
     * A historical exercise as older/other code paths left it: a row that may or
     * may not have sets, optionally tied to a plan exercise whose status says
     * what happened to it.
     *
     * @param  array<int, array{0: ?int, 1: ?float, 2?: bool}>  $sets
     */
    private function legacyRow(string $date, string $name, string $recordedAs, array $sets, ?string $planStatus = null, ?User $user = null, array $snapshot = []): WorkoutExercise
    {
        $user ??= $this->user;
        $session = WorkoutSession::factory()->for($user)->create(['logged_date' => $date]);

        $planExerciseId = null;
        if ($planStatus !== null) {
            [, $planExercise] = $this->plannedExercise($date, $name, [], $user);
            $planExercise->update(['status' => $planStatus]);
            $planExerciseId = $planExercise->id;
        }

        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create([
            'exercise_name' => $name,
            'recorded_as' => $recordedAs,
            'workout_plan_exercise_id' => $planExerciseId,
        ] + $snapshot);

        foreach ($sets as $i => $set) {
            WorkoutSet::factory()->for($exercise, 'exercise')->create([
                'set_number' => $i + 1,
                'reps' => $set[0],
                'weight_kg' => $set[1],
                'completed' => $set[2] ?? true,
            ]);
        }

        return $exercise;
    }

    // ---- get_recent_workouts: exact actuals -----------------------------------

    public function test_a_measured_exercise_returns_the_exact_reps_of_every_set(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 8, 'weight_kg' => 45], ['reps' => 8, 'weight_kg' => 45], ['reps' => 7, 'weight_kg' => 45]]);

        $record = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame([8, 8, 7], array_column($record['performed_sets'], 'reps'), 'The 7 must not be flattened into three sets of 8.');
        $this->assertSame([1, 2, 3], array_column($record['performed_sets'], 'set_number'));
        $this->assertSame('8,8,7 @ 45 kg', $record['performed_summary']);
    }

    public function test_a_measured_exercise_returns_the_exact_weight_of_every_set(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 8, 'weight_kg' => 45], ['reps' => 8, 'weight_kg' => 47.5], ['reps' => 6, 'weight_kg' => 50]]);

        $record = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame([45.0, 47.5, 50.0], array_column($record['performed_sets'], 'weight_kg'));
        $this->assertSame('8@45, 8@47.5, 6@50 kg', $record['performed_summary']);
    }

    public function test_an_unfinished_set_is_visible_as_not_completed(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 10, 'weight_kg' => 40], ['reps' => 10, 'weight_kg' => 40], ['reps' => 4, 'weight_kg' => 40, 'completed' => false]]);

        $record = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame([true, true, false], array_column($record['performed_sets'], 'completed'));
        $this->assertSame('partial', $record['outcome']);
        $this->assertSame('10,10 @ 40 kg (+1 not completed)', $record['performed_summary']);
    }

    public function test_the_planned_target_is_returned_next_to_the_actuals_and_stays_separate(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 8, 'weight_kg' => 45], ['reps' => 8, 'weight_kg' => 45], ['reps' => 7, 'weight_kg' => 45]]);

        $record = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame(3, $record['planned_sets']);
        $this->assertSame(10, $record['planned_reps']);
        $this->assertSame(40.0, $record['planned_weight_kg']);
        $this->assertSame('bench-press', $record['exercise_slug']);
        $this->assertSame('Bench Press', $record['exercise_name']);
        $this->assertSame('entered', $record['recorded_as']);
        $this->assertTrue($record['performance_measured']);
        $this->assertSame('completed', $record['outcome']);
        $this->assertSame(45.0, $record['performed_sets'][0]['weight_kg'], 'Actual 45 kg is not the planned 40 kg.');
    }

    public function test_the_session_identifier_and_date_are_returned(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 10, 'weight_kg' => 40]]);

        $session = $this->tool('get_recent_workouts')[0];

        $this->assertSame($plan->fresh()->workout_session_id, $session['workout_session_id']);
        $this->assertSame($this->user->localToday(), $session['date']);
    }

    // ---- as_planned is never presented as measured -----------------------------

    public function test_as_planned_exposes_no_performance_numbers_and_is_flagged_unmeasured(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->oneTap($plan, $exercise, 'completed');

        $record = $this->tool('get_recent_workouts')[0]['exercises'][0];

        $this->assertSame('as_planned', $record['recorded_as']);
        $this->assertFalse($record['performance_measured']);
        $this->assertSame('completed_as_planned', $record['outcome']);
        $this->assertNull($record['performed_sets']);
        $this->assertNull($record['performed_summary']);
        // The target is still shown, but only under its planned_* names.
        $this->assertSame(10, $record['planned_reps']);
        $this->assertSame(40.0, $record['planned_weight_kg']);
    }

    public function test_as_planned_stored_sets_never_leak_through_either_tool(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->oneTap($plan, $exercise, 'completed');
        $this->assertGreaterThan(0, WorkoutSet::count(), 'Precondition: assumed sets are stored.');

        foreach ([
            $this->tool('get_recent_workouts')[0]['exercises'][0],
            $this->tool('get_exercise_history', ['exercise' => 'Bench Press'])['sessions'][0],
        ] as $record) {
            $this->assertFalse($record['performance_measured']);
            $this->assertNull($record['performed_sets']);
            $this->assertNull($record['performed_summary']);
        }
    }

    public function test_the_record_itself_holds_no_actual_sets_for_an_as_planned_exercise(): void
    {
        $exercise = $this->legacyRow('2026-09-02', 'Bench Press', 'as_planned', [[10, 40], [10, 40], [10, 40]], 'completed', snapshot: ['planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]);

        $record = ExercisePerformanceRecord::from($exercise->load(['performedSets', 'planExercise']), '2026-09-02');

        $this->assertSame([], $record->sets, 'Any consumer of the record, not just the tools, is kept away from assumed numbers.');
        $this->assertFalse($record->performanceMeasured);
        $this->assertStringNotContainsString('40 kg —', $record->toCompactLine());
        $this->assertSame('2026-09-02 Bench Press: completed as planned, amounts not measured (planned 3×10 @ 40 kg)', $record->toCompactLine());
    }

    // ---- skipped vs completed vs partial vs unmeasured -------------------------

    public function test_the_outcomes_are_distinguishable(): void
    {
        $this->legacyRow('2026-09-01', 'A Done Measured', 'entered', [[10, 40], [10, 40]], 'completed');
        $this->legacyRow('2026-09-02', 'B Done As Planned', 'as_planned', [[10, 40], [10, 40]], 'completed');
        $this->legacyRow('2026-09-03', 'C Partial As Planned', 'as_planned', [], 'partial');
        $this->legacyRow('2026-09-04', 'D Skipped', 'as_planned', [], 'skipped');
        $this->legacyRow('2026-09-05', 'E Pending', 'as_planned', [], 'pending');
        $this->legacyRow('2026-09-06', 'F Nothing Known', 'as_planned', []);
        $this->legacyRow('2026-09-07', 'G Migrated', 'migrated', [[15, 6], [15, 6]]);
        $this->legacyRow('2026-09-08', 'H Measured But Empty', 'migrated', []);

        $byName = collect($this->tool('get_recent_workouts', ['days' => 60]))
            ->flatMap(fn ($session) => $session['exercises'])
            ->keyBy('exercise_name');

        $this->assertSame('completed', $byName['A Done Measured']['outcome']);
        $this->assertSame('completed_as_planned', $byName['B Done As Planned']['outcome']);
        $this->assertSame('partial', $byName['C Partial As Planned']['outcome']);
        $this->assertSame('skipped', $byName['D Skipped']['outcome']);
        $this->assertSame('pending', $byName['E Pending']['outcome']);
        $this->assertSame('no_measurable_performance', $byName['F Nothing Known']['outcome'], 'With no plan row to say it was done, completion is not assumed.');
        $this->assertSame('completed', $byName['G Migrated']['outcome']);
        $this->assertSame('no_measurable_performance', $byName['H Measured But Empty']['outcome']);

        $measured = $byName->filter(fn ($r) => $r['performance_measured'])->keys()->sort()->values()->all();
        $this->assertSame(['A Done Measured', 'G Migrated'], $measured, 'Only genuinely recorded sets count as measured.');

        foreach (['B Done As Planned', 'C Partial As Planned', 'D Skipped', 'E Pending', 'F Nothing Known', 'H Measured But Empty'] as $name) {
            $this->assertNull($byName[$name]['performed_sets'], "{$name} must expose no performed sets.");
        }
    }

    public function test_migrated_records_are_flagged_as_migrated_but_still_show_their_sets(): void
    {
        $this->legacyRow('2026-09-14', 'Dumbbell Rows', 'migrated', [[15, 6], [15, 6], [15, 6]]);

        $record = $this->tool('get_recent_workouts', ['days' => 60])[0]['exercises'][0];

        $this->assertSame('migrated', $record['recorded_as']);
        $this->assertTrue($record['performance_measured']);
        $this->assertSame('15,15,15 @ 6 kg', $record['performed_summary']);
        $this->assertNull($record['planned_sets'], 'A freeform session has no planned target; none is invented.');
    }

    // ---- planned snapshot is immutable -----------------------------------------

    public function test_the_planned_target_is_the_historical_snapshot_not_the_current_plan(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 8, 'weight_kg' => 45]]);

        // The plan row is edited afterwards (e.g. a later rewrite of the target).
        $exercise->update(['planned_sets' => 5, 'planned_reps' => 5, 'planned_weight_kg' => 100]);

        $record = $this->tool('get_exercise_history', ['exercise' => 'bench-press'])['sessions'][0];

        $this->assertSame(3, $record['planned_sets']);
        $this->assertSame(10, $record['planned_reps']);
        $this->assertSame(40.0, $record['planned_weight_kg']);
    }

    public function test_editing_the_logged_sets_keeps_the_original_planned_snapshot(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 8, 'weight_kg' => 45]]);

        $exercise->update(['planned_reps' => 12, 'planned_weight_kg' => 60]);
        $this->enter($plan, $exercise, [['reps' => 9, 'weight_kg' => 45]]);

        $record = $this->tool('get_exercise_history', ['exercise' => 'bench-press'])['sessions'][0];

        $this->assertSame(10, $record['planned_reps']);
        $this->assertSame(40.0, $record['planned_weight_kg']);
        $this->assertSame([9], array_column($record['performed_sets'], 'reps'), 'The actuals were updated; the target was not.');
    }

    // ---- get_exercise_history ---------------------------------------------------

    public function test_the_history_tool_returns_sessions_newest_first_with_everything_needed_to_compare(): void
    {
        foreach ([['2026-09-21', [[10, 40], [10, 40], [10, 40]]], ['2026-09-28', [[10, 42.5], [10, 42.5], [10, 42.5]]], ['2026-10-04', [[8, 45], [8, 45], [7, 45]]]] as [$date, $sets]) {
            [$plan, $exercise] = $this->plannedExercise($date);
            $this->enter($plan, $exercise, array_map(fn ($s) => ['reps' => $s[0], 'weight_kg' => $s[1]], $sets));
        }

        $result = $this->tool('get_exercise_history', ['exercise' => 'Bench Press']);

        $this->assertSame('bench-press', $result['exercise_slug']);
        $this->assertSame(['2026-10-04', '2026-09-28', '2026-09-21'], array_column($result['sessions'], 'date'), 'Chronological ordering: newest first.');
        $this->assertCount(3, array_unique(array_column($result['sessions'], 'workout_session_id')), 'Each session is distinguishable.');
        $this->assertSame('8,8,7 @ 45 kg', $result['sessions'][0]['performed_summary']);
        $this->assertSame('10,10,10 @ 42.5 kg', $result['sessions'][1]['performed_summary']);
        $this->assertSame('entered', $result['sessions'][0]['recorded_as']);
        $this->assertSame(40.0, $result['sessions'][0]['planned_weight_kg']);
    }

    public function test_the_history_tool_accepts_a_slug_as_well_as_a_name(): void
    {
        [$plan, $exercise] = $this->plannedExercise($this->user->localToday(), 'Dumbbell Row');
        $this->enter($plan, $exercise, [['reps' => 12, 'weight_kg' => 22.5]]);

        $this->assertSame(
            $this->tool('get_exercise_history', ['exercise' => 'Dumbbell Row']),
            $this->tool('get_exercise_history', ['exercise' => 'dumbbell-row']),
        );
    }

    public function test_the_history_tool_reports_completion_status_including_skipped_and_one_tap(): void
    {
        $this->legacyRow('2026-09-10', 'Goblet Squat', 'entered', [[10, 20], [10, 20]], 'completed');
        $this->legacyRow('2026-09-11', 'Goblet Squat', 'as_planned', [[10, 20], [10, 20]], 'completed');
        $this->legacyRow('2026-09-12', 'Goblet Squat', 'as_planned', [], 'skipped');

        $sessions = $this->tool('get_exercise_history', ['exercise' => 'goblet-squat'])['sessions'];

        $this->assertSame(['skipped', 'completed_as_planned', 'completed'], array_column($sessions, 'outcome'));
        $this->assertSame([false, false, true], array_column($sessions, 'performance_measured'));
    }

    public function test_the_history_tool_is_scoped_to_the_authenticated_user(): void
    {
        $other = User::factory()->create();
        [$otherPlan, $otherExercise] = $this->plannedExercise($this->user->localToday(), 'Bench Press', ['planned_sets' => 3, 'planned_reps' => 5, 'planned_weight_kg' => 100], $other);
        $this->enter($otherPlan, $otherExercise, [['reps' => 5, 'weight_kg' => 100]], $other);

        [$plan, $exercise] = $this->plannedExercise($this->user->localToday());
        $this->enter($plan, $exercise, [['reps' => 10, 'weight_kg' => 40]]);

        $mine = $this->tool('get_exercise_history', ['exercise' => 'Bench Press']);
        $this->assertCount(1, $mine['sessions']);
        $this->assertSame('10 @ 40 kg', $mine['sessions'][0]['performed_summary']);

        // Arguments cannot widen the scope.
        $sneaky = $this->tool('get_exercise_history', ['exercise' => 'Bench Press', 'user_id' => $other->id]);
        $this->assertSame($mine, $sneaky);

        $theirs = $this->tool('get_exercise_history', ['exercise' => 'Bench Press'], $other);
        $this->assertSame('5 @ 100 kg', $theirs['sessions'][0]['performed_summary']);

        $stranger = User::factory()->create();
        $this->assertSame([], $this->tool('get_exercise_history', ['exercise' => 'Bench Press'], $stranger)['sessions']);
    }

    public function test_the_history_limit_defaults_to_three_and_is_bounded(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->legacyRow(sprintf('2026-09-%02d', $i), 'Bench Press', 'entered', [[10, 40]]);
        }

        $count = fn (array $args) => count($this->tool('get_exercise_history', ['exercise' => 'Bench Press'] + $args)['sessions']);

        $this->assertSame(3, $count([]), 'Default');
        $this->assertSame(2, $count(['limit' => 2]));
        $this->assertSame(5, $count(['limit' => 5]));
        $this->assertSame(5, $count(['limit' => 50]), 'Capped at 5 however much is asked for.');
        $this->assertSame(1, $count(['limit' => 0]), 'Floored at 1.');
        $this->assertSame(1, $count(['limit' => -4]));
        $this->assertSame(5, $count(['limit' => 5000000]));

        $newest = $this->tool('get_exercise_history', ['exercise' => 'Bench Press', 'limit' => 2])['sessions'];
        $this->assertSame(['2026-09-08', '2026-09-07'], array_column($newest, 'date'), 'The bound keeps the most recent sessions.');
    }

    public function test_similar_exercise_names_are_not_merged(): void
    {
        $this->legacyRow('2026-09-14', 'Dumbbell Rows', 'entered', [[15, 6]]);
        $this->legacyRow('2026-09-16', 'Dumbbell Row', 'entered', [[12, 8]]);
        $this->legacyRow('2026-09-17', 'Lunges', 'entered', [[10, 6]]);
        $this->legacyRow('2026-09-18', 'Dumbbell Lunges', 'entered', [[10, 8]]);

        $rows = $this->tool('get_exercise_history', ['exercise' => 'Dumbbell Rows']);
        $this->assertSame('dumbbell-rows', $rows['exercise_slug']);
        $this->assertSame(['15 @ 6 kg'], array_column($rows['sessions'], 'performed_summary'));

        $row = $this->tool('get_exercise_history', ['exercise' => 'Dumbbell Row']);
        $this->assertSame(['12 @ 8 kg'], array_column($row['sessions'], 'performed_summary'));

        $this->assertSame(['10 @ 6 kg'], array_column($this->tool('get_exercise_history', ['exercise' => 'lunges'])['sessions'], 'performed_summary'));
        $this->assertSame(['10 @ 8 kg'], array_column($this->tool('get_exercise_history', ['exercise' => 'dumbbell-lunges'])['sessions'], 'performed_summary'));
    }

    public function test_an_empty_history_says_so_without_implying_similar_exercises_were_checked(): void
    {
        $this->legacyRow('2026-09-14', 'Dumbbell Rows', 'entered', [[15, 6]]);

        $result = $this->tool('get_exercise_history', ['exercise' => 'Dumbbell Row']);

        $this->assertSame([], $result['sessions']);
        $this->assertStringContainsString('exact exercise name', $result['note']);
        $this->assertStringContainsString('not merged', $result['note']);
    }

    public function test_the_history_tool_rejects_a_missing_exercise(): void
    {
        $this->assertArrayHasKey('error', $this->tool('get_exercise_history', []));
        $this->assertArrayHasKey('error', $this->tool('get_exercise_history', ['exercise' => '$$$']));
        $this->assertArrayHasKey('error', $this->tool('get_exercise_history', ['exercise' => ['bench-press']]));
    }

    public function test_the_history_tool_is_declared_to_the_model_as_read_only_and_bounded(): void
    {
        $declaration = collect(app(ToolRegistry::class)->declarations())->firstWhere('name', 'get_exercise_history');

        $this->assertNotNull($declaration);
        $this->assertSame(['exercise'], $declaration['parameters']['required']);
        $this->assertSame(['exercise', 'limit'], array_keys($declaration['parameters']['properties']));
        $this->assertStringContainsString('max 5', $declaration['parameters']['properties']['limit']['description']);
    }

    public function test_history_ordering_stays_chronological_when_sessions_were_logged_out_of_order(): void
    {
        $this->legacyRow('2026-09-28', 'Bench Press', 'entered', [[10, 42.5]]);
        $this->legacyRow('2026-09-14', 'Bench Press', 'entered', [[10, 40]]);
        $this->legacyRow('2026-10-04', 'Bench Press', 'entered', [[8, 45]]);

        $dates = array_column($this->tool('get_exercise_history', ['exercise' => 'bench-press'])['sessions'], 'date');

        $this->assertSame(['2026-10-04', '2026-09-28', '2026-09-14'], $dates);
    }
}
