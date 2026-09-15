<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Carbon\Carbon;

/**
 * Computes real adherence from workout_plans/workout_plan_exercises — never
 * estimated, never hardcoded. Formula (documented here, referenced everywhere
 * this number is surfaced — dashboard, chart, weekly summary):
 *
 *   An exercise is "due" once its plan's planned_date <= today. Future-dated
 *   exercises are excluded from the denominator entirely (not late, not due).
 *
 *   For due exercises: completed=1.0, partial=0.5, skipped=0.0, pending=0.0
 *   (a due-but-untouched exercise counts as a miss for the percentage, but its
 *   stored status stays "pending" — it is never silently rewritten to "skipped").
 *
 *   adherence_pct = due_count > 0 ? round(100 * sum(weight) / due_count) : null
 *   (null, not 0, when nothing was ever planned in the period — a 0% would
 *   look like a real (bad) number instead of "nothing to measure yet").
 *
 *   active_days = distinct dates in the period where EITHER a workout_plans
 *   row is completed/partial OR an activity_logs row has
 *   type IN (steps, treadmill, strength, sport) — deliberately excluding
 *   weight/recovery logs, since neither represents being active. This is the
 *   mechanism that makes an unplanned activity (e.g. soccer instead of a
 *   skipped gym session) count as a real active day.
 */
class AdherenceService
{
    private const EXERCISE_WEIGHTS = [
        WorkoutPlanExercise::STATUS_COMPLETED => 1.0,
        WorkoutPlanExercise::STATUS_PARTIAL => 0.5,
        WorkoutPlanExercise::STATUS_SKIPPED => 0.0,
        WorkoutPlanExercise::STATUS_PENDING => 0.0,
    ];

    private const ACTIVE_ACTIVITY_TYPES = [
        ActivityLog::TYPE_STEPS,
        ActivityLog::TYPE_TREADMILL,
        ActivityLog::TYPE_STRENGTH,
        ActivityLog::TYPE_SPORT,
    ];

    /**
     * @return array{
     *   start_date: string, end_date: string, adherence_pct: ?int, due_exercise_count: int,
     *   planned_workouts_completed: int, planned_workouts_partial: int, planned_workouts_skipped: int,
     *   active_days: int,
     * }
     */
    public function summary(User $user, string $startDate, string $endDate): array
    {
        $today = Carbon::today()->toDateString();

        $duePlansQuery = fn () => $user->workoutPlans()
            ->whereDate('planned_date', '>=', $startDate)
            ->whereDate('planned_date', '<=', $endDate)
            ->whereDate('planned_date', '<=', $today);

        $dueExercises = WorkoutPlanExercise::whereHas('workoutPlan', function ($query) use ($user, $startDate, $endDate, $today) {
            $query->where('user_id', $user->id)
                ->whereDate('planned_date', '>=', $startDate)
                ->whereDate('planned_date', '<=', $endDate)
                ->whereDate('planned_date', '<=', $today);
        })->get();

        $dueCount = $dueExercises->count();
        $scoreSum = $dueExercises->sum(fn (WorkoutPlanExercise $exercise) => self::EXERCISE_WEIGHTS[$exercise->status] ?? 0.0);
        $adherencePct = $dueCount > 0 ? (int) round(100 * $scoreSum / $dueCount) : null;

        $duePlans = $duePlansQuery()->get();

        // toBase() downgrades from Eloquent\Collection to a plain Support\Collection —
        // otherwise merge() below assumes model items and calls ->getKey() on what are
        // now plain date strings after map(), throwing.
        $planActiveDates = $duePlans
            ->whereIn('status', [WorkoutPlan::STATUS_COMPLETED, WorkoutPlan::STATUS_PARTIAL])
            ->map(fn (WorkoutPlan $plan) => $plan->planned_date->toDateString())
            ->toBase();

        $activityActiveDates = ActivityLog::where('user_id', $user->id)
            ->whereIn('type', self::ACTIVE_ACTIVITY_TYPES)
            ->whereDate('logged_date', '>=', $startDate)
            ->whereDate('logged_date', '<=', $endDate)
            ->pluck('logged_date')
            ->map(fn ($date) => $date->toDateString());

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'adherence_pct' => $adherencePct,
            'due_exercise_count' => $dueCount,
            'planned_workouts_completed' => $duePlans->where('status', WorkoutPlan::STATUS_COMPLETED)->count(),
            'planned_workouts_partial' => $duePlans->where('status', WorkoutPlan::STATUS_PARTIAL)->count(),
            'planned_workouts_skipped' => $duePlans->where('status', WorkoutPlan::STATUS_SKIPPED)->count(),
            'active_days' => $planActiveDates->merge($activityActiveDates)->unique()->count(),
        ];
    }

    /**
     * @return array<int, array{week_start: string, week_end: string, adherence_pct: ?int}>
     */
    public function weeklySeries(User $user, int $weeks = 8): array
    {
        $series = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $reference = Carbon::today()->subWeeks($i);
            $weekStart = $reference->copy()->startOfWeek();
            $weekEnd = $reference->copy()->endOfWeek();

            $summary = $this->summary($user, $weekStart->toDateString(), $weekEnd->toDateString());

            $series[] = [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'adherence_pct' => $summary['adherence_pct'],
            ];
        }

        return $series;
    }
}
