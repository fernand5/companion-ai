<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The current Monday-to-Sunday week as the user sees it: for each day what was PLANNED, what the user
 * RECORDED, and what they did that was NOT planned. Read-only, and built only from rows that exist
 * (workout_plans, their recorded performance, and the user's own activity_logs). Nothing is inferred
 * beyond the plain rules below, and nothing is stored.
 *
 * The week and "today" come from the user's own calendar (User::localNow()), so the server, not the
 * browser's clock, decides which day is today.
 *
 * What a day's state means, and what it deliberately does not:
 *  - completed / partial / in_progress / skipped: the plan's own status (an in-progress plan on a past day
 *    reads as partial: nothing more is coming).
 *  - planned: a plan for today or a later day that has not been started.
 *  - not_marked_done: a PAST plan that was never marked done and has no recorded performance. It is
 *    not "missed": the user may have trained and not logged it, so it is shown as unconfirmed.
 *  - rest: a day the plan deliberately cleared for rest (WorkoutPlan::isRestDay()).
 *  - activity_only: no plan, but the user logged a workout that day (e.g. soccer).
 *  - nothing: no plan and no logged workout. This is NOT a rest day and is never called one.
 */
class WeekOverviewService
{
    public const COMPLETED = 'completed';

    public const PARTIAL = 'partial';

    public const IN_PROGRESS = 'in_progress';

    public const PLANNED = 'planned';

    public const NOT_MARKED_DONE = 'not_marked_done';

    public const SKIPPED = 'skipped';

    public const REST = 'rest';

    public const ACTIVITY_ONLY = 'activity_only';

    public const NOTHING = 'nothing';

    /**
     * What counts as a workout the user did. Steps and weigh-ins are daily measurements, not sessions, so they
     * do not appear as a day's activity.
     */
    public const WORKOUT_TYPES = [ActivityLog::TYPE_TREADMILL, ActivityLog::TYPE_STRENGTH, ActivityLog::TYPE_SPORT, ActivityLog::TYPE_RECOVERY];

    public function __construct(
        private readonly WeeklyPlanService $weeklyPlanService,
        private readonly WorkoutPlanService $workoutPlanService,
    ) {}

    /**
     * @return array{
     *   week: array{start: string, end: string, today: string, timezone: string},
     *   days: array<int, array{date: string, weekday: string, is_today: bool, is_past: bool, state: string, plan: ?WorkoutPlan, activities: Collection<int, ActivityLog>}>
     * }
     */
    public function forCurrentWeek(User $user): array
    {
        $now = $user->localNow();
        $today = $now->toDateString();
        $range = $this->weeklyPlanService->weekRange($user, $now);

        $plans = $this->workoutPlanService->forRange($user, $range['start'], $range['end'])
            ->keyBy(fn (WorkoutPlan $plan) => $plan->planned_date->toDateString());

        // The user's own entries only: the row a completed plan mirrors onto the timeline is the plan's
        // outcome, already shown on the plan, not a second activity.
        $activities = $user->activityLogs()
            ->with('workoutSession.exercises.performedSets')
            ->whereDate('logged_date', '>=', $range['start'])
            ->whereDate('logged_date', '<=', $range['end'])
            ->whereIn('type', self::WORKOUT_TYPES)
            ->whereDoesntHave('completedPlan')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (ActivityLog $log) => $log->logged_date->toDateString());

        $days = [];
        $cursor = Carbon::parse($range['start']);

        for ($i = 0; $i < 7; $i++) {
            $date = $cursor->copy()->addDays($i)->toDateString();
            $plan = $plans->get($date);
            $dayActivities = $activities->get($date, collect());

            $days[] = [
                'date' => $date,
                'weekday' => Carbon::parse($date)->englishDayOfWeek,
                'is_today' => $date === $today,
                'is_past' => $date < $today,
                'state' => $this->state($plan, $dayActivities, $date < $today),
                'plan' => $plan,
                'activities' => $dayActivities->values(),
            ];
        }

        return [
            'week' => $range + ['today' => $today, 'timezone' => $user->timezone ?: config('app.timezone')],
            'days' => $days,
        ];
    }

    /** @param Collection<int, ActivityLog> $activities */
    private function state(?WorkoutPlan $plan, Collection $activities, bool $isPast): string
    {
        if ($plan === null) {
            return $activities->isNotEmpty() ? self::ACTIVITY_ONLY : self::NOTHING;
        }

        if ($plan->isRestDay()) {
            return self::REST;
        }

        return match ($plan->status) {
            WorkoutPlan::STATUS_COMPLETED => self::COMPLETED,
            WorkoutPlan::STATUS_PARTIAL => self::PARTIAL,
            // Some exercises recorded and the rest not: on a past day nothing more is coming, so it reads as partly done.
            WorkoutPlan::STATUS_IN_PROGRESS => $isPast ? self::PARTIAL : self::IN_PROGRESS,
            WorkoutPlan::STATUS_SKIPPED => self::SKIPPED,
            default => $isPast && ! $plan->hasRecordedPerformance() ? self::NOT_MARKED_DONE : self::PLANNED,
        };
    }
}
