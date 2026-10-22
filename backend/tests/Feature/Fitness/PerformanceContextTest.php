<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Fitness\PerformanceContext;
use App\Services\Fitness\WorkoutService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The size limits, the "next planned" rule and the strength-only filter of the
 * compact performance context. (The shape of the context itself, and the
 * entered/as_planned/user-isolation behaviour, are covered end to end in
 * Ai\PerformancePipelineEndToEndTest.)
 */
class PerformanceContextTest extends TestCase
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
     * @param  array<int, array<string, mixed>>  $exercises
     */
    private function plan(string $date, array $exercises, string $type = 'strength', ?User $user = null, string $title = 'Plan'): WorkoutPlan
    {
        $plan = WorkoutPlan::factory()->for($user ?? $this->user)->create(['planned_date' => $date, 'activity_type' => $type, 'title' => $title]);

        foreach (array_values($exercises) as $i => $exercise) {
            WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create($exercise + [
                'position' => $i, 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40, 'planned_duration_seconds' => null,
            ]);
        }

        return $plan->load('exercises');
    }

    private function enter(WorkoutPlan $plan, int $position, array $sets = [[10, 40]], ?User $as = null): void
    {
        $this->actingAs($as ?? $this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}/performance",
            ['sets' => array_map(fn ($s) => ['reps' => $s[0], 'weight_kg' => $s[1]], $sets)],
        )->assertOk();
    }

    private function tap(WorkoutPlan $plan, int $position, string $status): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}",
            ['status' => $status],
        )->assertOk();
    }

    private function daysAgo(int $days): string
    {
        return $this->user->localNow()->subDays($days)->toDateString();
    }

    private function context(array $options = [], ?User $as = null): string
    {
        return app(PerformanceContext::class)->render($as ?? $this->user, ...$options);
    }

    /** Exercise blocks only (everything except the Treadmill section), keyed by exercise name. */
    private function exerciseBlocks(string $context): array
    {
        $blocks = [];

        foreach (array_filter(explode("\n\n", $context)) as $block) {
            $name = strtok($block, "\n");

            if ($name !== 'Treadmill') {
                $blocks[$name] = $block;
            }
        }

        return $blocks;
    }

    private function treadmillLines(string $context): array
    {
        foreach (explode("\n\n", $context) as $block) {
            if (str_starts_with($block, "Treadmill\n")) {
                return array_slice(explode("\n", $block), 1);
            }
        }

        return [];
    }

    // ---- CONTEXT LIMITS ---------------------------------------------------------

    public function test_the_defaults_and_ceilings_are_the_documented_ones(): void
    {
        $this->assertSame([3, 60, 8, 3], [
            PerformanceContext::DEFAULT_SESSIONS_PER_EXERCISE, PerformanceContext::DEFAULT_DAYS,
            PerformanceContext::DEFAULT_MAX_EXERCISES, PerformanceContext::DEFAULT_TREADMILL_SESSIONS,
        ]);
        $this->assertSame([5, 180, 12, 10], [
            PerformanceContext::MAX_SESSIONS_PER_EXERCISE, PerformanceContext::MAX_DAYS,
            PerformanceContext::MAX_EXERCISES, PerformanceContext::MAX_TREADMILL_SESSIONS,
        ]);
    }

    public function test_max_exercises_is_clamped_to_its_ceiling_and_floor(): void
    {
        $names = array_map(fn ($i) => 'Exercise '.chr(65 + $i), range(0, 14)); // 15 exercises
        $plan = $this->plan($this->daysAgo(1), array_map(fn ($n) => ['exercise_name' => $n], $names));
        foreach (array_keys($names) as $i) {
            $this->enter($plan, $i);
        }

        $this->assertCount(8, $this->exerciseBlocks($this->context()), 'Default');
        $this->assertCount(3, $this->exerciseBlocks($this->context(['maxExercises' => 3])));
        $this->assertCount(12, $this->exerciseBlocks($this->context(['maxExercises' => 12])));
        $this->assertCount(12, $this->exerciseBlocks($this->context(['maxExercises' => 13])), 'Just over the ceiling');
        $this->assertCount(12, $this->exerciseBlocks($this->context(['maxExercises' => 1_000_000])));
        $this->assertCount(1, $this->exerciseBlocks($this->context(['maxExercises' => 0])), 'Floored at one');
        $this->assertCount(1, $this->exerciseBlocks($this->context(['maxExercises' => -7])));
    }

    public function test_sessions_per_exercise_is_clamped_to_its_ceiling_and_floor(): void
    {
        foreach (range(1, 8) as $day) {
            $this->enter($this->plan($this->daysAgo($day), [['exercise_name' => 'Bench Press']]), 0, [[10, 40]]);
        }
        $lines = fn (array $o) => count(array_filter(explode("\n", $this->exerciseBlocks($this->context($o))['Bench Press']), fn ($l) => str_starts_with($l, '- ')));

        $this->assertSame(3, $lines([]), 'Default');
        $this->assertSame(5, $lines(['sessionsPerExercise' => 5]));
        $this->assertSame(5, $lines(['sessionsPerExercise' => 6]), 'Just over the ceiling');
        $this->assertSame(5, $lines(['sessionsPerExercise' => 500]));
        $this->assertSame(1, $lines(['sessionsPerExercise' => 0]));
        $this->assertSame(1, $lines(['sessionsPerExercise' => -3]));
    }

    public function test_treadmill_sessions_is_clamped_to_its_ceiling_and_floor(): void
    {
        foreach (range(1, 14) as $day) {
            ActivityLog::factory()->for($this->user)->create(['type' => 'treadmill', 'logged_date' => $this->daysAgo($day), 'duration_minutes' => 20, 'distance_km' => 2]);
        }

        $this->assertCount(3, $this->treadmillLines($this->context()), 'Default');
        $this->assertCount(10, $this->treadmillLines($this->context(['treadmillSessions' => 10])));
        $this->assertCount(10, $this->treadmillLines($this->context(['treadmillSessions' => 11])), 'Just over the ceiling');
        $this->assertCount(10, $this->treadmillLines($this->context(['treadmillSessions' => 9999])));
        $this->assertCount(1, $this->treadmillLines($this->context(['treadmillSessions' => 0])));
        $this->assertCount(1, $this->treadmillLines($this->context(['treadmillSessions' => -1])));
    }

    public function test_the_day_window_is_clamped_to_its_ceiling_and_floor(): void
    {
        $this->enter($this->plan($this->daysAgo(0), [['exercise_name' => 'Today Lift']]), 0);
        $this->enter($this->plan($this->daysAgo(100), [['exercise_name' => 'Hundred Days Ago Lift']]), 0);
        $this->enter($this->plan($this->daysAgo(179), [['exercise_name' => 'Edge Lift']]), 0);
        $this->enter($this->plan($this->daysAgo(200), [['exercise_name' => 'Ancient Lift']]), 0);
        ActivityLog::factory()->for($this->user)->create(['type' => 'treadmill', 'logged_date' => $this->daysAgo(200), 'duration_minutes' => 20]);

        $names = fn (array $o) => array_keys($this->exerciseBlocks($this->context($o + ['maxExercises' => 12])));

        $this->assertSame(['Today Lift'], $names([]), 'Default 60 days: nothing older than that');
        $this->assertContains('Hundred Days Ago Lift', $names(['days' => 120]));
        $this->assertContains('Edge Lift', $names(['days' => 180]), 'The ceiling itself still includes 179 days ago');
        $this->assertNotContains('Ancient Lift', $names(['days' => 100_000]), 'Asking for "all time" is clamped to 180 days');
        $this->assertNotContains('Ancient Lift', $names(['days' => 181]));
        $this->assertSame(['Today Lift'], $names(['days' => 0]), 'Floored at one day: today only');
        $this->assertSame(['Today Lift'], $names(['days' => -50]));
        $this->assertSame([], $this->treadmillLines($this->context(['days' => 100_000])), 'The treadmill section respects the same clamp');
    }

    // ---- NEXT PLANNED -------------------------------------------------------------

    public function test_a_pending_exercise_in_a_future_plan_is_shown_as_next_planned(): void
    {
        $this->enter($this->plan($this->daysAgo(2), [['exercise_name' => 'Bench Press']]), 0, [[10, 40]]);
        $this->plan('2026-10-08', [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 45]], title: 'Future');

        $this->assertStringContainsString('Next planned (2026-10-08): 3×10 @ 45 kg', $this->context());
    }

    public function test_the_earliest_pending_target_wins(): void
    {
        $this->enter($this->plan($this->daysAgo(2), [['exercise_name' => 'Bench Press']]), 0);
        $this->plan('2026-10-12', [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 50]]);
        $this->plan('2026-10-07', [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 45]]);

        $this->assertStringContainsString('Next planned (2026-10-07): 3×10 @ 45 kg', $this->context());
        $this->assertStringNotContainsString('2026-10-12', $this->context());
    }

    public function test_an_already_started_exercise_is_not_presented_as_upcoming(): void
    {
        $this->enter($this->plan($this->daysAgo(2), [['exercise_name' => 'Bench Press']]), 0);

        // Today's exercise: logged with an unfinished set, so the plan marks it partial.
        $today = $this->plan('2026-10-05', [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 45]]);
        $this->actingAs($this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$today->id}/exercises/{$today->exercises[0]->id}/performance",
            ['sets' => [['reps' => 10, 'weight_kg' => 45], ['reps' => 4, 'weight_kg' => 45, 'completed' => false]]],
        )->assertOk();
        $this->assertSame('partial', $today->exercises[0]->fresh()->status);

        $context = $this->context();

        $this->assertStringNotContainsString('Next planned', $context, 'A started exercise is not upcoming.');
        $this->assertStringContainsString('10 @ 45 kg (+1 not completed)', $context, 'It is still visible, once, as what was actually done.');
    }

    public function test_completed_skipped_and_past_plans_are_not_next_planned(): void
    {
        $this->enter($this->plan($this->daysAgo(3), [['exercise_name' => 'Bench Press']]), 0);

        $completed = $this->plan('2026-10-06', [['exercise_name' => 'Bench Press']]);
        $this->tap($completed, 0, 'completed');
        $skipped = $this->plan('2026-10-07', [['exercise_name' => 'Bench Press']]);
        $this->tap($skipped, 0, 'skipped');
        $this->plan($this->daysAgo(1), [['exercise_name' => 'Bench Press']], title: 'Yesterday, never done');

        $this->assertStringNotContainsString('Next planned', $this->context());
    }

    public function test_an_exercise_that_is_still_pending_in_a_plan_that_has_begun_is_still_upcoming(): void
    {
        $this->enter($this->plan($this->daysAgo(3), [['exercise_name' => 'Bench Press']]), 0);

        // Today's plan: Squat is done, Bench Press has not been touched.
        $today = $this->plan('2026-10-05', [['exercise_name' => 'Squat'], ['exercise_name' => 'Bench Press', 'planned_weight_kg' => 45]]);
        $this->enter($today, 0);
        $this->assertSame('in_progress', $today->fresh()->status);

        $this->assertStringContainsString('Next planned (2026-10-05): 3×10 @ 45 kg', $this->context());
    }

    public function test_next_planned_matches_the_exact_exercise_and_the_same_user_only(): void
    {
        $this->enter($this->plan($this->daysAgo(2), [['exercise_name' => 'Bench Press']]), 0);
        $this->plan('2026-10-08', [['exercise_name' => 'Incline Bench Press'], ['exercise_name' => 'Bench Presses']], title: 'Similar names');

        $other = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->plan('2026-10-06', [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 99]], user: $other);

        $context = $this->context();

        $this->assertStringNotContainsString('Next planned', $context, 'Similar names and other users are not "the same exercise".');
        $this->assertStringNotContainsString('99', $context);
    }

    // ---- EXERCISE FILTERING -------------------------------------------------------

    public function test_recovery_treadmill_sport_and_steps_plans_do_not_take_strength_history_slots(): void
    {
        $strength = $this->plan($this->daysAgo(1), [['exercise_name' => 'Bench Press']], 'strength');
        $this->enter($strength, 0);

        foreach (['recovery' => 'Gentle Mobility', 'treadmill' => 'Treadmill Walk', 'sport' => 'Football Drills', 'steps' => 'Walk 8000 Steps'] as $type => $name) {
            $plan = $this->plan($this->daysAgo(array_search($type, ['recovery', 'treadmill', 'sport', 'steps']) + 2), [['exercise_name' => $name, 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 900]], $type);
            $this->tap($plan, 0, 'completed');
            $this->assertSame(1, WorkoutExercise::where('exercise_name', $name)->count(), "Precondition: {$name} was recorded.");
        }

        $blocks = $this->exerciseBlocks($this->context());

        $this->assertSame(['Bench Press'], array_keys($blocks));
    }

    public function test_a_busy_recovery_week_cannot_crowd_strength_exercises_out_of_the_cap(): void
    {
        $this->enter($this->plan($this->daysAgo(5), [['exercise_name' => 'Bench Press']], 'strength'), 0);

        // Nine newer mobility exercises, more than the default cap of eight.
        $names = array_map(fn ($i) => 'Mobility Drill '.chr(65 + $i), range(0, 8));
        $recovery = $this->plan($this->daysAgo(1), array_map(fn ($n) => ['exercise_name' => $n, 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 300], $names), 'recovery');
        foreach (array_keys($names) as $i) {
            $this->tap($recovery, $i, 'completed');
        }
        $this->assertSame(9, WorkoutExercise::where('exercise_name', 'like', 'Mobility Drill%')->count(), 'Precondition: all nine were recorded.');

        $this->assertSame(['Bench Press'], array_keys($this->exerciseBlocks($this->context())));
    }

    public function test_the_same_exercise_in_a_recovery_session_and_a_strength_session_shows_only_the_strength_one(): void
    {
        $this->enter($this->plan($this->daysAgo(1), [['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 45]], 'strength'), 0, [[10, 20]]);
        $this->tap($this->plan($this->daysAgo(2), [['exercise_name' => 'Plank', 'planned_duration_seconds' => 600]], 'recovery'), 0, 'completed');

        $lines = array_filter(explode("\n", $this->exerciseBlocks($this->context())['Plank']), fn ($l) => str_starts_with($l, '- '));

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('- '.$this->daysAgo(1), array_values($lines)[0]);
    }

    public function test_freeform_logged_strength_work_is_kept(): void
    {
        app(WorkoutService::class)->log($this->user, ['logged_date' => $this->daysAgo(1), 'exercises' => [['exercise_name' => 'Squat', 'sets' => 3, 'reps' => 5, 'weight_kg' => 60]]]);

        $this->assertStringContainsString('- '.$this->daysAgo(1).': performed 5,5,5 @ 60 kg | entered', $this->exerciseBlocks($this->context())['Squat']);
    }

    public function test_a_legacy_session_with_no_plan_cannot_be_classified_and_is_kept(): void
    {
        $session = WorkoutSession::factory()->for($this->user)->create(['logged_date' => $this->daysAgo(2)]);
        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => 'Pull-ups', 'recorded_as' => 'migrated']);
        WorkoutSet::factory()->for($exercise, 'exercise')->create(['set_number' => 1, 'reps' => 5, 'weight_kg' => null]);

        $this->assertArrayHasKey('Pull-ups', $this->exerciseBlocks($this->context()));
    }

    public function test_a_legacy_session_linked_to_a_recovery_plan_is_filtered_out(): void
    {
        $session = WorkoutSession::factory()->for($this->user)->create(['logged_date' => $this->daysAgo(2)]);
        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => 'Light Stretching', 'recorded_as' => 'as_planned']);
        WorkoutSet::factory()->for($exercise, 'exercise')->create(['set_number' => 1, 'reps' => null, 'weight_kg' => null, 'duration_seconds' => 600]);
        WorkoutPlan::factory()->for($this->user)->create(['activity_type' => 'recovery', 'planned_date' => $this->daysAgo(2), 'workout_session_id' => $session->id]);

        $this->assertSame([], $this->exerciseBlocks($this->context()));
    }

    public function test_filtering_hides_nothing_from_the_history_tool_and_deletes_nothing(): void
    {
        $this->enter($this->plan($this->daysAgo(1), [['exercise_name' => 'Plank']], 'strength'), 0);
        $recovery = $this->plan($this->daysAgo(2), [['exercise_name' => 'Plank', 'planned_duration_seconds' => 600]], 'recovery');
        $this->tap($recovery, 0, 'completed');

        $before = [WorkoutExercise::count(), WorkoutSet::count(), WorkoutSession::count(), ActivityLog::count()];

        $this->context();

        $this->assertSame($before, [WorkoutExercise::count(), WorkoutSet::count(), WorkoutSession::count(), ActivityLog::count()]);

        // The explicit history tool still returns both sessions: the filter is the context's choice.
        $dates = array_column(app(ToolRegistry::class)->call('get_exercise_history', ['exercise' => 'Plank', 'limit' => 5], $this->user)['sessions'], 'date');
        $this->assertSame([$this->daysAgo(1), $this->daysAgo(2)], $dates);
    }

    public function test_cardio_stays_in_its_own_section_while_its_plan_rows_leave_the_exercise_blocks(): void
    {
        $this->enter($this->plan($this->daysAgo(1), [['exercise_name' => 'Bench Press']], 'strength'), 0);
        $this->tap($this->plan($this->daysAgo(2), [['exercise_name' => 'Treadmill Walk', 'planned_duration_seconds' => 900]], 'treadmill'), 0, 'completed');
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4])->assertCreated();

        $context = $this->context();

        $this->assertSame(['Bench Press'], array_keys($this->exerciseBlocks($context)));
        $this->assertStringNotContainsString('Treadmill Walk', $context);
        // The real run is in the cardio section. (Completing a treadmill *plan* also leaves a bare
        // mirror activity log there, with no duration or distance; it claims nothing measured.)
        $this->assertContains('- 2026-10-05: 4.0 km | 30 min | 8.0 km/h | 7:30/km', $this->treadmillLines($context));
    }

    public function test_a_cardio_row_inside_a_strength_typed_plan_stays_but_is_marked_timed_not_resistance(): void
    {
        // DOCUMENTED LIMITATION (see StrengthSessions): classification is per plan, not per exercise,
        // and names are deliberately not guessed from. What can be said structurally is that this row
        // is only a duration (no reps, no load), so it is headed "(timed)". Pinned so a change is a decision.
        $plan = $this->plan($this->daysAgo(1), [['exercise_name' => 'Lunges'], ['exercise_name' => 'Treadmill Warm-up', 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 600]], 'strength');
        $this->enter($plan, 0);
        $this->tap($plan, 1, 'completed');

        $blocks = $this->exerciseBlocks($this->context());

        $this->assertArrayHasKey('Treadmill Warm-up (timed)', $blocks, 'Kept (it cannot be classified as cardio from the plan), but headed as a duration.');
        $this->assertArrayHasKey('Lunges', $blocks, 'The resistance exercise next to it is not marked timed.');
    }

    // ---- behaviour that must not change ----------------------------------------------

    public function test_entered_sets_as_planned_and_chronology_survive_the_changes(): void
    {
        $this->enter($this->plan($this->daysAgo(7), [['exercise_name' => 'Bench Press']]), 0, [[10, 40], [9, 40], [8, 40]]);
        $this->enter($this->plan($this->daysAgo(1), [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 42.5]]), 0, [[10, 42.5], [10, 42.5], [9, 42.5]]);
        $this->tap($this->plan($this->daysAgo(2), [['exercise_name' => 'Dumbbell Row', 'planned_weight_kg' => 22.5]]), 0, 'completed');

        $blocks = $this->exerciseBlocks($this->context());

        $this->assertSame(
            "Bench Press\n- {$this->daysAgo(7)}: performed 10,9,8 @ 40 kg | target 3×10 @ 40 kg — NOT reached (1 of 3 sets did) | entered\n- {$this->daysAgo(1)}: performed 10,10,9 @ 42.5 kg | target 3×10 @ 42.5 kg — NOT reached (2 of 3 sets did) | entered\nChange {$this->daysAgo(7)} → {$this->daysAgo(1)}: sets 3→3 | total reps 27→29 (+2) | top load 40→42.5 kg (+2.5) | volume (reps × kg) 1080→1232.5 kg (+152.5) | trend: 2 measured sessions: a comparison, not a trend",
            $blocks['Bench Press'],
        );
        $this->assertSame("Dumbbell Row\n- {$this->daysAgo(2)}: completed as planned | performance not measured | plan only: 3×10 @ 22.5 kg (not what was lifted)", $blocks['Dumbbell Row']);
    }
}
