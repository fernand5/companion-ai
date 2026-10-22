<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlanExercise;
use App\Support\StrengthSessions;
use Illuminate\Support\Facades\DB;

/**
 * Renders a user's recent performance as a small block of plain text: per
 * exercise, the last few sessions oldest to newest (what was done, the target at
 * the time, how far to trust it), the next planned target, then recent treadmill
 * sessions. It only reads, through the same history service and record
 * serializer the AI tools use, and nothing here interprets the numbers.
 *
 * Bounded: every size argument is clamped to the MAX_* limits below, so the
 * output cannot grow beyond a fixed ceiling whatever a caller asks for.
 *
 * Strength history only covers strength work. Sessions linked to a recovery,
 * treadmill, sport or steps plan are left out of the exercise blocks (cardio
 * stays in its own Treadmill section), nothing is deleted, and the limits of
 * that classification are documented on StrengthSessions.
 *
 * Within strength work, an exercise described only by a duration (no reps, no
 * load; e.g. a treadmill warm-up, mobility flow or plank hold) is headed
 * "(timed)": a duration, not resistance performance. This comes from the shape
 * of the data, never from the exercise's name. Where it is not reliable it is
 * not applied (see StrengthSessions for the per-plan limit).
 *
 * Comparisons are computed here, from the exact recorded sets, by
 * PerformanceComparison: a "Change" line per exercise with two comparable measured
 * sessions (what changed, and when it is not like-for-like), a note when there is
 * only one measured session, and optionally one treadmill comparison. They state
 * facts; they never say "better" or recommend anything.
 *
 * "Next planned" is only a genuinely upcoming target: an exercise that is still
 * pending. One that is already started (partial) is not upcoming, and it is
 * already visible in its own history line, so it is not repeated.
 *
 * NOT wired into any prompt or tool; it exists so the compact shape can be
 * produced from real data and measured before anything consumes it.
 */
class PerformanceContext
{
    public const DEFAULT_SESSIONS_PER_EXERCISE = 3;

    public const DEFAULT_DAYS = 60;

    public const DEFAULT_MAX_EXERCISES = 8;

    public const DEFAULT_TREADMILL_SESSIONS = 3;

    public const MAX_SESSIONS_PER_EXERCISE = 5;

    public const MAX_DAYS = 180;

    public const MAX_EXERCISES = 12;

    public const MAX_TREADMILL_SESSIONS = 10;

    public function __construct(
        private readonly ExerciseHistoryService $history,
        private readonly ActivityService $activity,
    ) {}

    public function render(
        User $user,
        int $sessionsPerExercise = self::DEFAULT_SESSIONS_PER_EXERCISE,
        int $days = self::DEFAULT_DAYS,
        int $maxExercises = self::DEFAULT_MAX_EXERCISES,
        int $treadmillSessions = self::DEFAULT_TREADMILL_SESSIONS,
        bool $includeTreadmill = true,
        bool $includeTreadmillComparison = false,
    ): string {
        $sessionsPerExercise = max(1, min($sessionsPerExercise, self::MAX_SESSIONS_PER_EXERCISE));
        $days = max(1, min($days, self::MAX_DAYS));
        $maxExercises = max(1, min($maxExercises, self::MAX_EXERCISES));
        $treadmillSessions = max(1, min($treadmillSessions, self::MAX_TREADMILL_SESSIONS));

        $blocks = array_filter([
            ...$this->exerciseBlocks($user, $sessionsPerExercise, $days, $maxExercises),
            $includeTreadmill ? $this->treadmillBlock($user, $treadmillSessions, $days) : null,
            $includeTreadmillComparison ? $this->treadmillComparisonBlock($user, $days) : null,
        ]);

        return implode("\n\n", $blocks);
    }

    /**
     * @return array<int, string>
     */
    private function exerciseBlocks(User $user, int $sessions, int $days, int $maxExercises): array
    {
        $since = $user->localNow()->subDays($days - 1)->toDateString();

        $slugs = StrengthSessions::only(WorkoutExercise::query()
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->whereDate('workout_sessions.logged_date', '>=', $since)
            ->whereNotNull('workout_exercises.exercise_slug'))
            ->groupBy('workout_exercises.exercise_slug')
            ->orderByDesc(DB::raw('max(workout_sessions.logged_date)'))
            ->orderBy('workout_exercises.exercise_slug')
            ->limit($maxExercises)
            ->pluck('workout_exercises.exercise_slug');

        $blocks = [];

        foreach ($slugs as $slug) {
            $entries = $this->history->recent($user, $slug, $sessions, includeUnmeasured: true, strengthSessionsOnly: true);

            if ($entries->isEmpty()) {
                continue;
            }

            $records = $entries->reverse()->map(fn (WorkoutExercise $exercise) => ExercisePerformanceRecord::from(
                $exercise,
                $exercise->workoutSession->logged_date->toDateString(),
            ));

            $timed = $records->every(fn (ExercisePerformanceRecord $record) => $record->isTimedOnly());

            $block = $entries->first()->exercise_name.($timed ? ' (timed)' : '')."\n"
                .$records->map(fn (ExercisePerformanceRecord $record) => '- '.$record->toCompactLine())->implode("\n");

            $block .= $this->comparisonLine(PerformanceComparison::series($records->reverse()->values()->all()));

            if ($next = $this->nextTarget($user, $slug)) {
                $block .= "\n".$next;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /** What changed between the latest two measured sessions, or why nothing can be said. */
    private function comparisonLine(array $series): string
    {
        if ($series['measured_sessions'] === 1) {
            return "\nOnly 1 measured session: no comparison or trend.";
        }

        if ($series['comparison'] === null) {
            return '';
        }

        $c = $series['comparison'];

        return "\nChange {$c['previous']['date']} → {$c['latest']['date']}: ".$series['summary'].' | trend: '.$series['trend'];
    }

    /** The next genuinely upcoming (still pending) plan for this exact exercise, if any. */
    private function nextTarget(User $user, string $slug): ?string
    {
        $next = WorkoutPlanExercise::query()
            ->select('workout_plan_exercises.*', 'workout_plans.planned_date as plan_date')
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $user->id)
            ->whereDate('workout_plans.planned_date', '>=', $user->localToday())
            ->where('workout_plan_exercises.exercise_slug', $slug)
            ->where('workout_plan_exercises.status', WorkoutPlanExercise::STATUS_PENDING)
            ->orderBy('workout_plans.planned_date')
            ->first();

        $target = $next ? ExercisePerformanceRecord::describePlanned([
            'sets' => $next->planned_sets,
            'reps' => $next->planned_reps,
            'weight_kg' => $next->planned_weight_kg !== null ? (float) $next->planned_weight_kg : null,
            'duration_seconds' => $next->planned_duration_seconds,
        ]) : null;

        return $target !== null ? 'Next planned ('.substr((string) $next->plan_date, 0, 10).'): '.$target : null;
    }

    private function treadmillBlock(User $user, int $sessions, int $days): ?string
    {
        $today = $user->localNow();

        $logs = $this->activity
            ->history($user, $today->copy()->subDays($days - 1)->toDateString(), $today->toDateString(), ActivityLog::TYPE_TREADMILL)
            ->take($sessions)
            ->reverse();

        if ($logs->isEmpty()) {
            return null;
        }

        return "Treadmill\n".$logs->map(fn (ActivityLog $log) => '- '.$log->logged_date->toDateString().': '.$this->treadmillLine($log))->implode("\n");
    }

    /**
     * How the latest two logged runs compare. Only runs the user logged with a
     * distance and a duration are comparable; with fewer than two, it says so
     * rather than guessing. Nothing is shown for a user with no treadmill logs.
     */
    private function treadmillComparisonBlock(User $user, int $days): ?string
    {
        $today = $user->localNow();

        $logs = $this->activity
            ->history($user, $today->copy()->subDays($days - 1)->toDateString(), $today->toDateString(), ActivityLog::TYPE_TREADMILL);

        if ($logs->isEmpty()) {
            return null;
        }

        $runs = $logs->filter(fn (ActivityLog $log) => ! $log->isPlanCompletion() && $log->distance_km !== null && $log->duration_minutes)->values();

        if ($runs->count() < 2) {
            return "Treadmill (comparison)\n- Fewer than two logged runs with a distance and a duration: no comparison possible.";
        }

        $comparison = PerformanceComparison::treadmill($runs[1], $runs[0]);

        return "Treadmill (comparison)\n- {$comparison['previous']['date']} → {$comparison['latest']['date']}: ".PerformanceComparison::describeTreadmill($comparison);
    }

    private function treadmillLine(ActivityLog $log): string
    {
        // A plan-completion mirror acknowledges the plan, it is not a measured run.
        if ($log->isPlanCompletion()) {
            return 'completed as planned | duration and distance not measured';
        }

        $parts = [];

        if ($log->distance_km !== null) {
            $parts[] = number_format((float) $log->distance_km, 1).' km';
        }

        if ($log->duration_minutes !== null) {
            $parts[] = $log->duration_minutes.' min';
        }

        if (($speed = $log->speedKmh()) !== null) {
            $parts[] = number_format($speed, 1).' km/h';
        }

        if (($pace = $log->paceSecondsPerKm()) !== null) {
            $parts[] = sprintf('%d:%02d/km', intdiv($pace, 60), $pace % 60);
        }

        if ($log->distance_km === null) {
            array_unshift($parts, 'distance not recorded');
        }

        return implode(' | ', $parts);
    }
}
