<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSet;
use App\Services\Fitness\PerformanceContext;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The whole chain, driven the way a real user drives it, through the real
 * endpoints, services and AI tools (nothing here re-implements them):
 *
 *   user action -> workout_sets / workout_exercises -> exercise history
 *     -> AI tools -> compact context
 *
 * Time is pinned so dates (and so the expected text) are deterministic.
 * Reading only: nothing in this chain recommends or changes a training load.
 */
class PerformancePipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutPlan $benchPlan1;

    private WorkoutPlan $benchPlan2;

    private WorkoutPlan $mixedPlan;

    private WorkoutPlan $futurePlan;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-05 12:00 in Bogota.
        $this->travelTo('2026-10-05 17:00:00');

        $this->user = User::factory()->create(['timezone' => 'America/Bogota']);

        $this->benchPlan1 = $this->plan($this->user, '2026-10-01', 'Upper Body A', [['Bench Press', 3, 10, 40]]);
        $this->mixedPlan = $this->plan($this->user, '2026-10-03', 'Mixed', [
            ['Dumbbell Row', 3, 12, 22.5],
            ['Goblet Squat', 3, 12, 20],
            ['Overhead Press', 3, 10, 20],
        ]);
        $this->benchPlan2 = $this->plan($this->user, '2026-10-05', 'Upper Body B', [['Bench Press', 3, 10, 42.5]]);
        $this->futurePlan = $this->plan($this->user, '2026-10-08', 'Upper Body C', [['Bench Press', 3, 10, 45]]);

        // SCENARIO 1: two measured Bench Press sessions, logged through the performance endpoint.
        $this->enter($this->user, $this->benchPlan1, 0, [[10, 40], [9, 40], [8, 40]]);
        $this->enter($this->user, $this->benchPlan2, 0, [[10, 42.5], [10, 42.5], [9, 42.5]]);

        // SCENARIO 2: one-tap completion of the Dumbbell Row; a one-tap partial; a skip.
        $this->tap($this->user, $this->mixedPlan, 0, 'completed');
        $this->tap($this->user, $this->mixedPlan, 1, 'partial');
        $this->tap($this->user, $this->mixedPlan, 2, 'skipped');

        // SCENARIO 3: treadmill through the activity endpoint (+ one with no distance).
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4])->assertCreated();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 15, 'logged_date' => '2026-10-03'])->assertCreated();
    }

    // ---- helpers: the real user actions ----------------------------------------

    /**
     * @param  array<int, array{0: string, 1: int, 2: int, 3: float|int}>  $exercises
     */
    private function plan(User $user, string $date, string $title, array $exercises): WorkoutPlan
    {
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => $date, 'title' => $title]);

        foreach ($exercises as $i => [$name, $sets, $reps, $kg]) {
            WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
                'exercise_name' => $name, 'position' => $i, 'planned_sets' => $sets, 'planned_reps' => $reps, 'planned_weight_kg' => $kg,
            ]);
        }

        return $plan->load('exercises');
    }

    /** @param  array<int, array{0: int, 1: float|int}>  $sets reps, kg */
    private function enter(User $as, WorkoutPlan $plan, int $position, array $sets)
    {
        return $this->actingAs($as, 'sanctum')->putJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}/performance",
            ['sets' => array_map(fn ($s) => ['reps' => $s[0], 'weight_kg' => $s[1]], $sets)],
        );
    }

    private function tap(User $as, WorkoutPlan $plan, int $position, string $status)
    {
        return $this->actingAs($as, 'sanctum')->patchJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}",
            ['status' => $status],
        );
    }

    private function tool(string $name, array $args = [], ?User $as = null): mixed
    {
        return app(ToolRegistry::class)->call($name, $args, $as ?? $this->user);
    }

    private function context(?User $as = null): string
    {
        return app(PerformanceContext::class)->render($as ?? $this->user);
    }

    private function recordFor(WorkoutPlan $plan, int $position): WorkoutExercise
    {
        return WorkoutExercise::where('workout_plan_exercise_id', $plan->exercises[$position]->id)->sole();
    }

    // ---- SCENARIO 1: measured strength -----------------------------------------

    public function test_measured_sets_are_stored_exactly_and_marked_entered(): void
    {
        $first = $this->recordFor($this->benchPlan1, 0);
        $second = $this->recordFor($this->benchPlan2, 0);

        $rows = fn (WorkoutExercise $e) => WorkoutSet::where('workout_exercise_id', $e->id)->orderBy('set_number')->get()
            ->map(fn ($s) => [$s->set_number, $s->reps, (float) $s->weight_kg, $s->completed])->all();

        $this->assertSame([[1, 10, 40.0, true], [2, 9, 40.0, true], [3, 8, 40.0, true]], $rows($first));
        $this->assertSame([[1, 10, 42.5, true], [2, 10, 42.5, true], [3, 9, 42.5, true]], $rows($second));
        $this->assertSame('entered', $first->recorded_as);
        $this->assertSame('entered', $second->recorded_as);
        $this->assertSame('bench-press', $first->exercise_slug);
        $this->assertNotSame($first->workout_session_id, $second->workout_session_id, 'Two sessions, not one.');
    }

    // ---- SCENARIO 2: as planned -------------------------------------------------

    public function test_a_one_tap_completion_is_stored_as_planned_and_never_read_as_measured(): void
    {
        $row = $this->recordFor($this->mixedPlan, 0);

        $this->assertSame('as_planned', $row->recorded_as);
        $this->assertGreaterThan(0, $row->performedSets()->count(), 'The assumed sets exist in the database…');

        $workouts = collect($this->tool('get_recent_workouts', ['days' => 30]))->flatMap(fn ($s) => $s['exercises'])->keyBy('exercise_name');
        $row = $workouts['Dumbbell Row'];

        $this->assertSame('as_planned', $row['recorded_as']);
        $this->assertFalse($row['performance_measured']);
        $this->assertSame('completed_as_planned', $row['outcome']);
        $this->assertNull($row['performed_sets'], '…but are never offered to the AI as performed.');
        $this->assertNull($row['performed_summary']);
        $this->assertSame([3, 12, 22.5], [$row['planned_sets'], $row['planned_reps'], $row['planned_weight_kg']]);

        $history = $this->tool('get_exercise_history', ['exercise' => 'Dumbbell Row'])['sessions'][0];
        $this->assertFalse($history['performance_measured']);
        $this->assertNull($history['performed_sets']);

        $this->assertStringNotContainsString('22.5 kg |', $this->context(), 'The assumed 22.5 kg is only ever shown as a target.');
    }

    // ---- SCENARIO 3: treadmill --------------------------------------------------

    public function test_a_treadmill_session_exposes_distance_speed_and_pace(): void
    {
        $this->assertSame('4.00', ActivityLog::where('user_id', $this->user->id)->where('duration_minutes', 30)->sole()->distance_km);

        $log = collect($this->tool('get_recent_activity', ['days' => 30]))->firstWhere('duration_minutes', 30);

        $this->assertSame(4.0, $log['distance_km']);
        $this->assertSame(8.0, $log['speed_kmh']);
        $this->assertSame(450, $log['pace_seconds_per_km']);
        $this->assertSame('7:30 /km', $log['pace']);
    }

    public function test_a_treadmill_session_without_distance_stays_without_distance(): void
    {
        $log = collect($this->tool('get_recent_activity', ['days' => 30]))->firstWhere('duration_minutes', 15);

        $this->assertNull($log['distance_km']);
        $this->assertNull($log['speed_kmh']);
        $this->assertNull($log['pace_seconds_per_km']);
        $this->assertSame('distance not recorded', $log['distance_note']);
    }

    // ---- SCENARIO 4 + 5: AI tools, planned vs actual --------------------------------

    public function test_get_recent_workouts_keeps_each_set_and_each_sessions_own_target(): void
    {
        $sessions = collect($this->tool('get_recent_workouts', ['days' => 30]))->keyBy('date');

        $one = $sessions['2026-10-01']['exercises'][0];
        $two = $sessions['2026-10-05']['exercises'][0];

        $this->assertSame([10, 9, 8], array_column($one['performed_sets'], 'reps'));
        $this->assertSame('10,9,8 @ 40 kg', $one['performed_summary']);
        $this->assertSame([10, 10, 9], array_column($two['performed_sets'], 'reps'));
        $this->assertSame('10,10,9 @ 42.5 kg', $two['performed_summary']);

        $this->assertSame([3, 10, 40.0], [$one['planned_sets'], $one['planned_reps'], $one['planned_weight_kg']]);
        $this->assertSame([3, 10, 42.5], [$two['planned_sets'], $two['planned_reps'], $two['planned_weight_kg']]);

        foreach ([$one, $two] as $record) {
            $this->assertSame('entered', $record['recorded_as']);
            $this->assertTrue($record['performance_measured']);
            $this->assertSame('completed', $record['outcome']);
            $this->assertSame('bench-press', $record['exercise_slug']);
        }

        $this->assertArrayNotHasKey('sets', $one, 'No flattened "3 sets x 10 reps" summary exists.');
        $this->assertNotSame($sessions['2026-10-01']['workout_session_id'], $sessions['2026-10-05']['workout_session_id']);
    }

    public function test_exercise_history_returns_both_bench_sessions_newest_first_with_exact_sets(): void
    {
        $history = $this->tool('get_exercise_history', ['exercise' => 'Bench Press']);

        $this->assertSame('bench-press', $history['exercise_slug']);
        $this->assertSame(['2026-10-05', '2026-10-01'], array_column($history['sessions'], 'date'));
        $this->assertSame(['10,10,9 @ 42.5 kg', '10,9,8 @ 40 kg'], array_column($history['sessions'], 'performed_summary'));
        $this->assertSame([[10, 10, 9], [10, 9, 8]], array_map(fn ($s) => array_column($s['performed_sets'], 'reps'), $history['sessions']));
        $this->assertSame([[42.5, 42.5, 42.5], [40.0, 40.0, 40.0]], array_map(fn ($s) => array_column($s['performed_sets'], 'weight_kg'), $history['sessions']));
        $this->assertSame([42.5, 40.0], array_column($history['sessions'], 'planned_weight_kg'));
        $this->assertSame(['entered', 'entered'], array_column($history['sessions'], 'recorded_as'));
        $this->assertCount(1, $this->tool('get_exercise_history', ['exercise' => 'bench-press', 'limit' => 1])['sessions']);
    }

    public function test_changing_the_plan_afterwards_does_not_rewrite_either_historical_target(): void
    {
        // The current plan moves on: today's and last week's plan rows are edited…
        $this->benchPlan1->exercises[0]->update(['planned_sets' => 5, 'planned_reps' => 5, 'planned_weight_kg' => 100]);
        $this->benchPlan2->exercises[0]->update(['planned_sets' => 1, 'planned_reps' => 1, 'planned_weight_kg' => 120]);

        // …and an attempt to replace a day that already has performance is refused.
        try {
            app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => '2026-10-01', 'activity_type' => 'strength', 'title' => 'Rewrite history']);
            $this->fail('A plan with recorded performance must not be replaceable.');
        } catch (ValidationException) {
        }

        $sessions = $this->tool('get_exercise_history', ['exercise' => 'bench-press'])['sessions'];

        $this->assertSame([[3, 10, 42.5], [3, 10, 40.0]], array_map(fn ($s) => [$s['planned_sets'], $s['planned_reps'], $s['planned_weight_kg']], $sessions));
        $this->assertSame(['10,10,9 @ 42.5 kg', '10,9,8 @ 40 kg'], array_column($sessions, 'performed_summary'), 'The actuals are untouched too.');
        $this->assertSame('Upper Body A', $this->benchPlan1->fresh()->title);
    }

    public function test_skipped_and_partial_are_not_presented_as_performance(): void
    {
        $partial = $this->recordFor($this->mixedPlan, 1);
        $this->assertSame('as_planned', $partial->recorded_as);
        $this->assertSame(0, $partial->performedSets()->count(), 'A partial with no amounts records no sets.');

        $this->assertNull(WorkoutExercise::where('workout_plan_exercise_id', $this->mixedPlan->exercises[2]->id)->first(), 'A skipped exercise leaves no performance row.');

        $workouts = collect($this->tool('get_recent_workouts', ['days' => 30]))->flatMap(fn ($s) => $s['exercises'])->keyBy('exercise_name');
        $this->assertSame('partial', $workouts['Goblet Squat']['outcome']);
        $this->assertFalse($workouts['Goblet Squat']['performance_measured']);
        $this->assertArrayNotHasKey('Overhead Press', $workouts->all());

        // The plan itself still says what happened to the skipped one.
        $plan = collect($this->tool('get_todays_plan', ['date' => '2026-10-03'])['exercises'])->keyBy('exercise_name');
        $this->assertSame('skipped', $plan['Overhead Press']['status']);
        $this->assertSame('partial', $plan['Goblet Squat']['status']);
    }

    public function test_a_partial_with_entered_sets_is_partial_but_measured(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$this->futurePlan->id}/exercises/{$this->futurePlan->exercises[0]->id}/performance",
            ['sets' => [['reps' => 10, 'weight_kg' => 45], ['reps' => 10, 'weight_kg' => 45], ['reps' => 3, 'weight_kg' => 45, 'completed' => false]]],
        )->assertOk();

        $latest = $this->tool('get_exercise_history', ['exercise' => 'bench-press'])['sessions'][0];

        $this->assertSame('partial', $latest['outcome']);
        $this->assertTrue($latest['performance_measured']);
        $this->assertSame('10,10 @ 45 kg (+1 not completed)', $latest['performed_summary']);
        $this->assertSame([true, true, false], array_column($latest['performed_sets'], 'completed'));
    }

    // ---- SCENARIO 6: user isolation ----------------------------------------------

    public function test_user_b_never_sees_user_a_and_user_a_never_sees_user_b(): void
    {
        $b = User::factory()->create(['timezone' => 'America/Bogota']);
        $bPlan = $this->plan($b, '2026-10-04', 'B Day', [['Bench Press', 5, 5, 100]]);
        $this->enter($b, $bPlan, 0, [[5, 100], [5, 100], [5, 100]])->assertOk();
        $this->actingAs($b, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 60, 'distance_km' => 10, 'logged_date' => '2026-10-04'])->assertCreated();

        // B sees only B…
        $this->assertSame(['5,5,5 @ 100 kg'], array_column($this->tool('get_exercise_history', ['exercise' => 'Bench Press'], $b)['sessions'], 'performed_summary'));
        $this->assertSame(10.0, collect($this->tool('get_recent_activity', ['days' => 30], $b))->firstWhere('type', 'treadmill')['distance_km']);

        // …and A's data is untouched by B's, in every tool and the context.
        $aEverything = json_encode([
            $this->tool('get_exercise_history', ['exercise' => 'Bench Press']),
            $this->tool('get_recent_workouts', ['days' => 30]),
            $this->tool('get_recent_activity', ['days' => 30]),
        ]).$this->context();

        foreach (['100 kg', '5,5,5', '"distance_km":10', '10.0 km', 'B Day'] as $bsData) {
            $this->assertStringNotContainsString($bsData, $aEverything, "User A must not see B's {$bsData}");
        }

        $bEverything = json_encode([$this->tool('get_recent_workouts', ['days' => 30], $b), $this->tool('get_recent_activity', ['days' => 30], $b)]).$this->context($b);
        foreach (['42.5', '10,9,8', '22.5', 'Dumbbell Row', 'Upper Body A', '4.0 km'] as $asData) {
            $this->assertStringNotContainsString($asData, $bEverything, "User B must not see A's {$asData}");
        }
    }

    public function test_another_users_busy_week_cannot_crowd_out_this_users_exercises_from_the_context(): void
    {
        $b = User::factory()->create(['timezone' => 'America/Bogota']);
        $names = ['Squat', 'Deadlift', 'Pull-up', 'Dip', 'Lunge', 'Curl', 'Press', 'Row', 'Crunch'];
        $bPlan = $this->plan($b, '2026-10-05', 'B Big Day', array_map(fn ($n) => [$n, 3, 5, 50], $names));

        foreach ($names as $i => $name) {
            $this->enter($b, $bPlan, $i, [[5, 50]])->assertOk();
        }

        $context = $this->context();

        $this->assertStringContainsString('Dumbbell Row', $context, "B's nine newer exercises must not push A's out of the 8-exercise cap.");
        $this->assertStringContainsString('Goblet Squat', $context);
        $this->assertStringNotContainsString('Deadlift', $context);
        $this->assertStringNotContainsString('50 kg', $context);
    }

    public function test_user_b_cannot_read_or_write_user_as_plans_and_performance_over_http(): void
    {
        $b = User::factory()->create(['timezone' => 'America/Bogota']);
        $before = WorkoutSet::count();

        $this->enter($b, $this->benchPlan1, 0, [[1, 1]])->assertForbidden();
        $this->tap($b, $this->benchPlan1, 0, 'pending')->assertForbidden();
        $this->assertSame($before, WorkoutSet::count());

        $this->actingAs($b, 'sanctum')->getJson('/api/exercises/bench-press/history')->assertOk()->assertJsonPath('sessions', []);
        $this->assertSame([], $this->tool('get_activity_for_date', ['date' => '2026-10-05'], $b));
        $this->assertArrayHasKey('error', $this->tool('get_todays_plan', ['date' => '2026-10-05'], $b), 'A\'s plan is not visible to B.');
    }

    // ---- SCENARIO 7: context ------------------------------------------------------

    public function test_the_compact_context_is_exactly_what_the_real_serializer_produces(): void
    {
        $expected = <<<'TXT'
Bench Press
- 2026-10-01: performed 10,9,8 @ 40 kg | target 3×10 @ 40 kg — NOT reached (1 of 3 sets did) | entered
- 2026-10-05: performed 10,10,9 @ 42.5 kg | target 3×10 @ 42.5 kg — NOT reached (2 of 3 sets did) | entered
Change 2026-10-01 → 2026-10-05: sets 3→3 | total reps 27→29 (+2) | top load 40→42.5 kg (+2.5) | volume (reps × kg) 1080→1232.5 kg (+152.5) | trend: 2 measured sessions: a comparison, not a trend
Next planned (2026-10-08): 3×10 @ 45 kg

Dumbbell Row
- 2026-10-03: completed as planned | performance not measured | plan only: 3×12 @ 22.5 kg (not what was lifted)

Goblet Squat
- 2026-10-03: partial | performance not measured | plan only: 3×12 @ 20 kg (not what was lifted)

Treadmill
- 2026-10-03: distance not recorded | 15 min
- 2026-10-05: 4.0 km | 30 min | 8.0 km/h | 7:30/km
TXT;

        $context = $this->context();

        $this->assertSame($expected, $context);
        $this->assertLessThan(1000, strlen($context), 'Compact: about 820 characters (roughly 205-275 tokens) for 3 exercises, a computed change and 2 treadmill sessions.');
    }

    public function test_the_context_is_far_smaller_than_the_raw_tool_json_it_comes_from(): void
    {
        $raw = strlen(json_encode([
            $this->tool('get_exercise_history', ['exercise' => 'bench-press']),
            $this->tool('get_recent_workouts', ['days' => 30]),
            $this->tool('get_recent_activity', ['days' => 30]),
        ]));

        $this->assertLessThan($raw / 5, strlen($this->context()));
    }

    public function test_the_context_for_a_user_with_no_history_is_empty(): void
    {
        $this->assertSame('', $this->context(User::factory()->create()));
    }

    public function test_the_context_does_not_present_a_skipped_or_unmeasured_exercise_as_done(): void
    {
        $context = $this->context();

        $this->assertStringNotContainsString('Overhead Press', $context);
        $this->assertSame(1, substr_count($context, 'Dumbbell Row'));
        $this->assertStringContainsString('| entered', $context);
        $this->assertSame(2, substr_count($context, 'performance not measured'), 'Both one-tap rows say so; neither shows an assumed load as done.');
    }

    // ---- the whole chain, once, end to end ------------------------------------------

    public function test_one_action_flows_from_the_database_to_the_context(): void
    {
        // The day of the planned session arrives, and the user logs it.
        $this->travelTo('2026-10-08 17:00:00');
        $this->enter($this->user, $this->futurePlan, 0, [[8, 45], [8, 45], [7, 45]])->assertOk();

        $set = WorkoutSet::orderByDesc('id')->first();
        $this->assertSame([7, 3], [$set->reps, $set->set_number]);

        $record = $this->recordFor($this->futurePlan, 0);
        $this->assertSame([8, 45.0, 'entered'], [$record->performedSets[0]->reps, (float) $record->performedSets[0]->weight_kg, $record->recorded_as]);

        $latest = $this->tool('get_exercise_history', ['exercise' => 'Bench Press', 'limit' => 3])['sessions'];
        $this->assertSame(['2026-10-08', '2026-10-05', '2026-10-01'], array_column($latest, 'date'));
        $this->assertSame('8,8,7 @ 45 kg', $latest[0]['performed_summary']);

        $this->assertStringContainsString(
            "- 2026-10-08: performed 8,8,7 @ 45 kg | target 3×10 @ 45 kg — NOT reached (0 of 3 sets did) | entered\n",
            $this->context()."\n",
        );
        $this->assertStringNotContainsString('Next planned', explode("\n\n", $this->context())[0], 'Nothing is left to plan once the last one is done.');
    }
}
