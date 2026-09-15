<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\AdherenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdherenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_adherence_pct_matches_hand_computed_value_and_excludes_future_dated_exercises(): void
    {
        $user = User::factory()->create();

        // Due plan A (3 days ago): 1 completed (1.0) + 1 skipped (0.0).
        $planA = WorkoutPlan::factory()->for($user)->create(['planned_date' => Carbon::today()->subDays(3)]);
        WorkoutPlanExercise::factory()->for($planA, 'workoutPlan')->create(['status' => 'completed', 'position' => 0]);
        WorkoutPlanExercise::factory()->for($planA, 'workoutPlan')->create(['status' => 'skipped', 'position' => 1]);

        // Due plan B (2 days ago): 1 partial (0.5) + 1 pending (0.0).
        $planB = WorkoutPlan::factory()->for($user)->create(['planned_date' => Carbon::today()->subDays(2)]);
        WorkoutPlanExercise::factory()->for($planB, 'workoutPlan')->create(['status' => 'partial', 'position' => 0]);
        WorkoutPlanExercise::factory()->for($planB, 'workoutPlan')->create(['status' => 'pending', 'position' => 1]);

        // Not-yet-due plan C (tomorrow): must be excluded entirely from the denominator.
        $planC = WorkoutPlan::factory()->for($user)->create(['planned_date' => Carbon::tomorrow()]);
        WorkoutPlanExercise::factory()->for($planC, 'workoutPlan')->create(['status' => 'pending', 'position' => 0]);

        $summary = app(AdherenceService::class)->summary(
            $user,
            Carbon::today()->subDays(10)->toDateString(),
            Carbon::today()->addDays(10)->toDateString(),
        );

        // due_count = 4 (planC's exercise excluded), score = 1.0 + 0 + 0.5 + 0 = 1.5
        // adherence_pct = round(100 * 1.5 / 4) = round(37.5) = 38
        $this->assertSame(4, $summary['due_exercise_count']);
        $this->assertSame(38, $summary['adherence_pct']);
    }

    public function test_adherence_pct_is_null_not_zero_when_nothing_is_due(): void
    {
        $user = User::factory()->create();

        $summary = app(AdherenceService::class)->summary(
            $user,
            Carbon::today()->subDays(7)->toDateString(),
            Carbon::today()->toDateString(),
        );

        $this->assertNull($summary['adherence_pct']);
        $this->assertSame(0, $summary['due_exercise_count']);
    }

    public function test_active_days_counts_a_plan_less_activity_log(): void
    {
        $user = User::factory()->create();

        $user->activityLogs()->create([
            'type' => ActivityLog::TYPE_SPORT,
            'logged_date' => Carbon::today()->subDay()->toDateString(),
            'duration_minutes' => 75,
            'metadata' => ['sport' => 'Football'],
        ]);

        $summary = app(AdherenceService::class)->summary(
            $user,
            Carbon::today()->subDays(7)->toDateString(),
            Carbon::today()->toDateString(),
        );

        $this->assertSame(1, $summary['active_days']);
    }

    public function test_active_days_excludes_weight_and_recovery_logs(): void
    {
        $user = User::factory()->create();

        $user->activityLogs()->create([
            'type' => ActivityLog::TYPE_WEIGHT,
            'logged_date' => Carbon::today()->toDateString(),
            'metadata' => ['weight_kg' => 80],
        ]);
        $user->activityLogs()->create([
            'type' => ActivityLog::TYPE_RECOVERY,
            'logged_date' => Carbon::today()->toDateString(),
            'metadata' => ['energy' => 3, 'soreness' => 2, 'sleep_hours' => 7],
        ]);

        $summary = app(AdherenceService::class)->summary(
            $user,
            Carbon::today()->subDays(7)->toDateString(),
            Carbon::today()->toDateString(),
        );

        $this->assertSame(0, $summary['active_days']);
    }

    public function test_a_skipped_plan_with_a_substitute_activity_still_counts_as_an_active_day(): void
    {
        $user = User::factory()->create();

        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => Carbon::today()]);
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();
        $exercise->update(['status' => 'skipped']);
        $plan->update(['status' => WorkoutPlan::STATUS_SKIPPED]);

        $user->activityLogs()->create([
            'type' => ActivityLog::TYPE_SPORT,
            'logged_date' => Carbon::today()->toDateString(),
            'duration_minutes' => 75,
            'metadata' => ['sport' => 'Football'],
        ]);

        $summary = app(AdherenceService::class)->summary(
            $user,
            Carbon::today()->toDateString(),
            Carbon::today()->toDateString(),
        );

        $this->assertSame(1, $summary['active_days']);
        $this->assertSame(1, $summary['planned_workouts_skipped']);
    }

    public function test_weekly_series_returns_one_entry_per_week(): void
    {
        $user = User::factory()->create();

        $series = app(AdherenceService::class)->weeklySeries($user, 4);

        $this->assertCount(4, $series);
        $this->assertArrayHasKey('week_start', $series[0]);
        $this->assertArrayHasKey('adherence_pct', $series[0]);
    }
}
