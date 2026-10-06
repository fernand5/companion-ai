<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Set-level strength performance: what the user ACTUALLY did, recorded apart
 * from the plan, plus the integrity rules that keep that history truthful.
 */
class ExercisePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutPlan $plan;

    private WorkoutPlanExercise $bench;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->plan = WorkoutPlan::factory()->for($this->user)->create(['duration_minutes' => 45]);
        $this->bench = $this->planExercise('Bench Press', 0);
    }

    private function planExercise(string $name, int $position, array $overrides = []): WorkoutPlanExercise
    {
        return WorkoutPlanExercise::factory()->for($this->plan, 'workoutPlan')->create([
            'exercise_name' => $name,
            'position' => $position,
            'planned_sets' => 3,
            'planned_reps' => 10,
            'planned_weight_kg' => 40,
            'planned_duration_seconds' => null,
            ...$overrides,
        ]);
    }

    private function record(WorkoutPlanExercise $exercise, array $sets, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$this->plan->id}/exercises/{$exercise->id}/performance",
            ['sets' => $sets] + $extra,
        );
    }

    private function setStatus(WorkoutPlanExercise $exercise, string $status, array $extra = [])
    {
        return $this->actingAs($this->user, 'sanctum')->patchJson(
            "/api/workout-plans/{$this->plan->id}/exercises/{$exercise->id}",
            ['status' => $status] + $extra,
        );
    }

    private function recorded(WorkoutPlanExercise $exercise): ?WorkoutExercise
    {
        return WorkoutExercise::where('workout_plan_exercise_id', $exercise->id)->first();
    }

    private function threeSets(): array
    {
        return [
            ['reps' => 10, 'weight_kg' => 40],
            ['reps' => 10, 'weight_kg' => 40],
            ['reps' => 8, 'weight_kg' => 40],
        ];
    }

    // ---- Recording actual sets, reps and weight --------------------------------

    public function test_each_set_is_recorded_with_its_own_reps_and_weight(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $exercise = $this->recorded($this->bench);
        $this->assertSame('entered', $exercise->recorded_as);
        $this->assertSame('bench-press', $exercise->exercise_slug);

        $sets = $exercise->performedSets;
        $this->assertCount(3, $sets);
        $this->assertSame([1, 2, 3], $sets->pluck('set_number')->all());
        $this->assertSame([10, 10, 8], $sets->pluck('reps')->all());
        $this->assertSame(['40.00', '40.00', '40.00'], $sets->pluck('weight_kg')->all());
        $this->assertSame([true, true, true], $sets->pluck('completed')->all());
    }

    public function test_the_response_exposes_the_recorded_sets_beside_the_untouched_plan(): void
    {
        $response = $this->record($this->bench, $this->threeSets())->assertOk();

        $exercise = $response->json('exercises.0');
        $this->assertSame(10, $exercise['planned_reps']);
        $this->assertEquals(40, $exercise['planned_weight_kg']);
        $this->assertSame('entered', $exercise['performance']['recorded_as']);
        $this->assertSame(8, $exercise['performance']['sets'][2]['reps']);
    }

    public function test_the_workout_exercise_summary_is_derived_from_the_sets(): void
    {
        $this->record($this->bench, [
            ['reps' => 10, 'weight_kg' => 40],
            ['reps' => 8, 'weight_kg' => 45],
            ['reps' => 6, 'weight_kg' => 45],
        ])->assertOk();

        $exercise = $this->recorded($this->bench);
        $this->assertSame(3, $exercise->sets);
        $this->assertSame(8, $exercise->reps);
        $this->assertSame('45.00', $exercise->weight_kg);
    }

    public function test_the_plan_exercise_actual_columns_are_a_derived_summary_of_entered_sets(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $fresh = $this->bench->fresh();
        $this->assertSame(3, $fresh->actual_sets);
        $this->assertSame(10, $fresh->actual_reps);
        $this->assertSame('40.00', $fresh->actual_weight_kg);
    }

    public function test_duration_based_sets_are_recorded(): void
    {
        $plank = $this->planExercise('Plank', 1, ['planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 60]);

        $this->record($plank, [['duration_seconds' => 60], ['duration_seconds' => 45], ['duration_seconds' => 40]])->assertOk();

        $this->assertSame([60, 45, 40], $this->recorded($plank)->performedSets->pluck('duration_seconds')->all());
    }

    // ---- Status is derived from what was recorded ------------------------------

    public function test_recording_every_planned_set_completes_the_exercise(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->assertSame('completed', $this->bench->fresh()->status);
        $this->assertNotNull($this->bench->fresh()->completed_at);
    }

    public function test_recording_fewer_sets_than_planned_is_partial(): void
    {
        $this->record($this->bench, [['reps' => 10, 'weight_kg' => 40], ['reps' => 10, 'weight_kg' => 40]])->assertOk();

        $this->assertSame('partial', $this->bench->fresh()->status);
    }

    public function test_an_unfinished_set_makes_the_exercise_partial_and_is_not_counted(): void
    {
        $this->record($this->bench, [
            ['reps' => 10, 'weight_kg' => 40],
            ['reps' => 10, 'weight_kg' => 40],
            ['reps' => 4, 'weight_kg' => 40, 'completed' => false],
        ])->assertOk();

        $this->assertSame('partial', $this->bench->fresh()->status);
        $this->assertSame(2, $this->recorded($this->bench)->sets);
        $this->assertCount(3, $this->recorded($this->bench)->performedSets);
    }

    public function test_missing_target_reps_still_counts_as_completed(): void
    {
        // 8 reps against a target of 10 is a performance fact, not an incomplete exercise.
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->assertSame('completed', $this->bench->fresh()->status);
    }

    public function test_recording_the_only_exercise_completes_the_plan(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->assertSame('completed', $this->plan->fresh()->status);
    }

    // ---- Validation -------------------------------------------------------------

    public function test_at_least_one_completed_set_is_required(): void
    {
        $this->record($this->bench, [['reps' => 5, 'weight_kg' => 40, 'completed' => false]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sets');

        $this->assertNull($this->recorded($this->bench));
    }

    public function test_a_set_must_have_reps_weight_or_duration(): void
    {
        $this->record($this->bench, [['reps' => 10, 'weight_kg' => 40], ['completed' => true]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sets.1');
    }

    public function test_values_are_bounded(): void
    {
        $this->record($this->bench, [['reps' => 201]])->assertUnprocessable()->assertJsonValidationErrors('sets.0.reps');
        $this->record($this->bench, [['weight_kg' => 501]])->assertUnprocessable()->assertJsonValidationErrors('sets.0.weight_kg');
        $this->record($this->bench, [['reps' => -1]])->assertUnprocessable()->assertJsonValidationErrors('sets.0.reps');
        $this->record($this->bench, array_fill(0, 51, ['reps' => 5]))->assertUnprocessable()->assertJsonValidationErrors('sets');
        $this->record($this->bench, [])->assertUnprocessable()->assertJsonValidationErrors('sets');
    }

    // ---- Planned vs actual stay separate ---------------------------------------

    public function test_performing_differently_never_rewrites_the_plan(): void
    {
        $this->record($this->bench, [['reps' => 8, 'weight_kg' => 45], ['reps' => 8, 'weight_kg' => 45], ['reps' => 8, 'weight_kg' => 45]])->assertOk();

        $plan = $this->bench->fresh();
        $this->assertSame(3, $plan->planned_sets);
        $this->assertSame(10, $plan->planned_reps);
        $this->assertSame('40.00', $plan->planned_weight_kg);

        $actual = $this->recorded($this->bench);
        $this->assertSame(8, $actual->reps);
        $this->assertSame('45.00', $actual->weight_kg);
    }

    public function test_the_target_is_snapshotted_beside_the_actual_performance(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame(3, $actual->planned_sets);
        $this->assertSame(10, $actual->planned_reps);
        $this->assertSame('40.00', $actual->planned_weight_kg);
    }

    public function test_the_snapshot_keeps_the_original_target_even_if_the_plan_row_changes(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->bench->update(['planned_reps' => 12, 'planned_weight_kg' => 50]);
        $this->record($this->bench, $this->threeSets())->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame(10, $actual->planned_reps);
        $this->assertSame('40.00', $actual->planned_weight_kg);
    }

    // ---- Editing in place -------------------------------------------------------

    public function test_saving_again_updates_the_same_rows_instead_of_duplicating(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();
        $exerciseId = $this->recorded($this->bench)->id;
        $setIds = WorkoutSet::where('workout_exercise_id', $exerciseId)->orderBy('set_number')->pluck('id')->all();

        $this->record($this->bench, [
            ['reps' => 10, 'weight_kg' => 42.5], ['reps' => 10, 'weight_kg' => 42.5], ['reps' => 9, 'weight_kg' => 42.5],
        ])->assertOk();

        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());
        $this->assertSame(1, WorkoutExercise::where('workout_plan_exercise_id', $this->bench->id)->count());
        $this->assertSame($exerciseId, $this->recorded($this->bench)->id);
        $this->assertSame($setIds, WorkoutSet::where('workout_exercise_id', $exerciseId)->orderBy('set_number')->pluck('id')->all());
        $this->assertSame(['42.50', '42.50', '42.50'], WorkoutSet::where('workout_exercise_id', $exerciseId)->orderBy('set_number')->pluck('weight_kg')->all());
    }

    public function test_saving_fewer_sets_removes_the_extra_ones(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();
        $this->record($this->bench, [['reps' => 10, 'weight_kg' => 40]])->assertOk();

        $this->assertSame(1, WorkoutSet::where('workout_exercise_id', $this->recorded($this->bench)->id)->count());
    }

    // ---- One-tap completion is weak evidence -----------------------------------

    public function test_one_tap_completion_is_recorded_as_planned(): void
    {
        $this->setStatus($this->bench, 'completed')->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame('as_planned', $actual->recorded_as);
        $this->assertCount(3, $actual->performedSets);
        $this->assertSame([10, 10, 10], $actual->performedSets->pluck('reps')->all());
        $this->assertFalse($actual->isMeasured());
    }

    public function test_assumed_numbers_are_never_written_as_the_plan_exercises_actuals(): void
    {
        $this->setStatus($this->bench, 'completed')->assertOk();

        $fresh = $this->bench->fresh();
        $this->assertNull($fresh->actual_sets);
        $this->assertNull($fresh->actual_reps);
        $this->assertNull($fresh->actual_weight_kg);
    }

    public function test_one_tap_never_overwrites_numbers_the_user_entered(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->setStatus($this->bench, 'partial')->assertOk();
        $this->setStatus($this->bench, 'completed')->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame('entered', $actual->recorded_as);
        $this->assertSame([10, 10, 8], $actual->performedSets->pluck('reps')->all());
    }

    public function test_a_partial_with_no_numbers_records_the_exercise_but_no_sets(): void
    {
        $this->setStatus($this->bench, 'partial')->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame('as_planned', $actual->recorded_as);
        $this->assertCount(0, $actual->performedSets);
    }

    public function test_numbers_sent_with_a_status_change_are_recorded_as_entered_sets(): void
    {
        $this->setStatus($this->bench, 'completed', ['actual_sets' => 3, 'actual_reps' => 8, 'actual_weight_kg' => 42.5])->assertOk();

        $actual = $this->recorded($this->bench);
        $this->assertSame('entered', $actual->recorded_as);
        $this->assertSame([8, 8, 8], $actual->performedSets->pluck('reps')->all());
        $this->assertSame('42.50', $actual->weight_kg);
        $this->assertSame(8, $this->bench->fresh()->actual_reps);
    }

    // ---- Taking a completion back ----------------------------------------------

    public function test_resetting_an_exercise_retracts_its_recorded_performance(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->setStatus($this->bench, 'pending')->assertOk();

        $this->assertNull($this->recorded($this->bench));
        $this->assertSame(0, WorkoutSet::count());
        $this->assertNull($this->bench->fresh()->actual_reps);
    }

    public function test_skipping_retracts_recorded_performance_and_removes_the_now_empty_session(): void
    {
        $this->setStatus($this->bench, 'completed')->assertOk();
        $this->assertNotNull($this->plan->fresh()->workout_session_id);

        $this->setStatus($this->bench, 'skipped')->assertOk();

        $this->assertNull($this->plan->fresh()->workout_session_id);
        $this->assertSame(0, WorkoutSession::count());
        $this->assertSame(0, ActivityLog::where('user_id', $this->user->id)->count());
    }

    public function test_resetting_one_exercise_keeps_the_others_history(): void
    {
        $row = $this->planExercise('Row', 1);
        $this->record($this->bench, $this->threeSets())->assertOk();
        $this->record($row, $this->threeSets())->assertOk();

        $this->setStatus($row, 'pending')->assertOk();

        $this->assertNotNull($this->recorded($this->bench));
        $this->assertNull($this->recorded($row));
        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());
    }

    // ---- Session and mirrored activity ------------------------------------------

    public function test_the_strength_activity_appears_only_once_the_plan_is_completed_or_partial(): void
    {
        $row = $this->planExercise('Row', 1);

        $this->record($this->bench, $this->threeSets())->assertOk();
        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());
        $this->assertSame(0, ActivityLog::where('user_id', $this->user->id)->where('type', 'strength')->count());

        $this->record($row, $this->threeSets())->assertOk();
        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->where('type', 'strength')->count());
        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());

        // Taking one back means the day is only in progress again: the activity goes, the history stays.
        $this->setStatus($row, 'pending')->assertOk();
        $this->assertSame(0, ActivityLog::where('user_id', $this->user->id)->where('type', 'strength')->count());
        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());
    }

    public function test_the_session_duration_is_not_invented_from_the_plans_target(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $session = WorkoutSession::where('user_id', $this->user->id)->first();
        $this->assertNull($session->duration_minutes);
        $this->assertNull(ActivityLog::where('workout_session_id', $session->id)->value('duration_minutes'));
    }

    public function test_the_session_is_dated_today_when_a_future_plan_is_done_early(): void
    {
        $future = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => now()->addDays(3)->toDateString()]);
        $exercise = WorkoutPlanExercise::factory()->for($future, 'workoutPlan')->create(['planned_sets' => 3, 'planned_reps' => 10]);

        $this->actingAs($this->user, 'sanctum')->putJson(
            "/api/workout-plans/{$future->id}/exercises/{$exercise->id}/performance",
            ['sets' => [['reps' => 10, 'weight_kg' => 20]]],
        )->assertOk();

        $this->assertSame($this->user->localToday(), WorkoutSession::find($future->fresh()->workout_session_id)->logged_date->toDateString());
        $this->assertSame($future->planned_date->toDateString(), $future->fresh()->planned_date->toDateString());
    }

    // ---- A performed day cannot be replaced -------------------------------------

    public function test_a_day_with_recorded_performance_cannot_be_replaced_via_the_api(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/workout-plans', [
            'planned_date' => $this->plan->planned_date->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Replacement',
            'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('planned_date');

        $this->assertSame('Bench Press', $this->plan->exercises()->first()->exercise_name);
        $this->assertNotNull($this->recorded($this->bench));
    }

    public function test_the_ai_plan_tool_cannot_replace_a_performed_day(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        $result = app(ToolRegistry::class)->call('create_workout_plan', [
            'planned_date' => $this->plan->planned_date->toDateString(),
            'activity_type' => 'strength',
            'title' => 'AI replacement',
            'decision_summary' => 'x',
            'reasoning_factors' => ['y'],
            'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 5]],
        ], $this->user);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotNull($this->recorded($this->bench));
    }

    public function test_the_weekly_adaptation_skips_a_day_that_is_only_in_progress_but_already_performed(): void
    {
        $this->planExercise('Row', 1); // stays pending -> the plan is in_progress, not completed/partial
        $this->record($this->bench, $this->threeSets())->assertOk();
        $this->assertSame('in_progress', $this->plan->fresh()->status);

        $result = app(WeeklyPlanService::class)->applyChanges($this->user, [[
            'date' => $this->plan->planned_date->toDateString(),
            'action' => 'replace',
            'activity_type' => 'strength',
            'title' => 'Adapted',
            'reason' => 'test',
        ]]);

        $this->assertSame([], $result['applied']);
        $this->assertSame('already_completed', $result['skipped'][0]['reason']);
        $this->assertNotNull($this->recorded($this->bench));
    }

    public function test_an_unperformed_day_can_still_be_replaced(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/workout-plans', [
            'planned_date' => $this->plan->planned_date->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Replacement',
            'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 5]],
        ])->assertOk();

        $this->assertSame('Replacement', $this->plan->fresh()->title);
    }

    public function test_a_skipped_only_day_has_no_performance_and_can_be_replaced(): void
    {
        $this->setStatus($this->bench, 'skipped')->assertOk();

        $this->assertFalse($this->plan->fresh()->hasRecordedPerformance());
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->plan->planned_date->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Fresh start',
        ]);
        $this->assertSame('Fresh start', $this->plan->fresh()->title);
    }

    public function test_a_blocked_replacement_does_not_leave_a_second_session_when_completed_again(): void
    {
        $this->record($this->bench, $this->threeSets())->assertOk();

        try {
            app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $this->plan->planned_date->toDateString(),
                'activity_type' => 'strength',
                'title' => 'Replacement',
            ]);
            $this->fail('Expected the replacement to be refused.');
        } catch (ValidationException) {
        }

        $this->record($this->bench, $this->threeSets())->assertOk();

        $this->assertSame(1, WorkoutSession::where('user_id', $this->user->id)->count());
        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->where('type', 'strength')->count());
    }

    // ---- Authorization and isolation --------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->putJson("/api/workout-plans/{$this->plan->id}/exercises/{$this->bench->id}/performance", ['sets' => [['reps' => 5]]])
            ->assertUnauthorized();
    }

    public function test_another_users_plan_is_forbidden_and_nothing_is_recorded(): void
    {
        $intruder = User::factory()->create();

        $this->record($this->bench, $this->threeSets(), as: $intruder)->assertForbidden();

        $this->assertNull($this->recorded($this->bench));
        $this->assertSame(0, WorkoutSession::count());
    }

    public function test_an_exercise_from_a_different_plan_is_not_found(): void
    {
        $otherPlan = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => now()->addDay()->toDateString()]);
        $stranger = WorkoutPlanExercise::factory()->for($otherPlan, 'workoutPlan')->create();

        $this->record($stranger, $this->threeSets())->assertNotFound();
    }

    // ---- Existing data without performance --------------------------------------

    public function test_a_plan_with_no_recorded_performance_serializes_with_a_null_performance(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/workout-plans?date='.$this->plan->planned_date->toDateString())
            ->assertOk();

        $this->assertNull($response->json('exercises.0.performance'));
        $this->assertSame(10, $response->json('exercises.0.planned_reps'));
    }

    public function test_the_plan_listing_does_not_query_per_exercise(): void
    {
        foreach (['Row', 'Squat', 'Press', 'Curl'] as $i => $name) {
            $this->record($this->planExercise($name, $i + 1), $this->threeSets())->assertOk();
        }

        \DB::enableQueryLog();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/workout-plans?date='.$this->plan->planned_date->toDateString())->assertOk();
        $performanceQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'workout_sets') || str_contains($q['query'], 'from "workout_exercises"'))->count();

        $this->assertLessThanOrEqual(2, $performanceQueries, 'Recorded performance must be eager-loaded, not queried per exercise.');
    }
}
