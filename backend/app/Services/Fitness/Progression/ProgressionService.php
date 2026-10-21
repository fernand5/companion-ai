<?php

namespace App\Services\Fitness\Progression;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\ExerciseHistoryService;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Support\ExerciseSlug;
use App\Support\StrengthSessions;
use Illuminate\Support\Facades\DB;

/**
 * Reads what the engine needs and asks it. It reuses the existing history service
 * and record serializer, adds no LLM call, and WRITES NOTHING: a recommendation is
 * returned, never applied. Asking about eight exercises costs the same handful of
 * queries as asking about one.
 */
class ProgressionService
{
    public const MAX_EXERCISES = 12;

    public function __construct(
        private readonly ExerciseHistoryService $history,
        private readonly ProgressionEngine $engine,
        private readonly SafetyContextLoader $safety,
    ) {}

    /** The recommendation for one exact exercise (matched by its slug, never fuzzily). */
    public function recommendFor(User $user, string $exerciseName): ProgressionRecommendation
    {
        return $this->overviewFor($user, $exerciseName)['recommendation'];
    }

    /**
     * A recommendation together with the upcoming plan row its "current target" comes from
     * (null when there is none). An approval needs that row; nothing else should.
     *
     * @return array{recommendation: ProgressionRecommendation, row: ?WorkoutPlanExercise}
     */
    public function overviewFor(User $user, string $exerciseName): array
    {
        $slug = ExerciseSlug::from($exerciseName);

        if ($slug === null) {
            return ['recommendation' => $this->engine->evaluate($exerciseName, null, [], null, $this->safety->for($user), $user->localToday()), 'row' => null];
        }

        return $this->recommendations($user, [$slug => $exerciseName])[0];
    }

    /**
     * One recommendation per exercise done recently in strength work, most recent first.
     *
     * @return array<int, ProgressionRecommendation>
     */
    public function recommendAll(User $user, int $maxExercises = 8): array
    {
        return array_map(fn (array $entry) => $entry['recommendation'], $this->overview($user, $maxExercises));
    }

    /**
     * Like recommendAll(), each with the upcoming plan row its target comes from.
     *
     * @return array<int, array{recommendation: ProgressionRecommendation, row: ?WorkoutPlanExercise}>
     */
    public function overview(User $user, int $maxExercises = 8): array
    {
        $maxExercises = max(1, min($maxExercises, self::MAX_EXERCISES));
        $since = $user->localNow()->subDays(ProgressionPolicy::HISTORY_DAYS - 1)->toDateString();

        $slugs = StrengthSessions::only(WorkoutExercise::query()
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->whereDate('workout_sessions.logged_date', '>=', $since)
            ->whereNotNull('workout_exercises.exercise_slug'))
            ->groupBy('workout_exercises.exercise_slug')
            ->orderByDesc(DB::raw('max(workout_sessions.logged_date)'))
            ->orderBy('workout_exercises.exercise_slug')
            ->limit($maxExercises)
            ->pluck('workout_exercises.exercise_slug')
            ->all();

        return $this->recommendations($user, array_fill_keys($slugs, null));
    }

    /**
     * @param  array<string, ?string>  $names  slug => the name to show when there is no history to take it from
     * @return array<int, array{recommendation: ProgressionRecommendation, row: ?WorkoutPlanExercise}>
     */
    private function recommendations(User $user, array $names): array
    {
        $slugs = array_keys($names);
        $since = $user->localNow()->subDays(ProgressionPolicy::HISTORY_DAYS - 1)->toDateString();

        $rows = $this->history->recentForSlugs($user, $slugs, ProgressionPolicy::HISTORY_SESSIONS, $since, includeUnmeasured: true, strengthSessionsOnly: true);
        $nextRows = $this->nextRows($user, $slugs);
        $safety = $this->safety->for($user);
        $today = $user->localToday();

        $out = [];

        foreach ($slugs as $slug) {
            $entries = $rows[$slug] ?? collect();
            $records = $entries->map(fn (WorkoutExercise $exercise) => ExercisePerformanceRecord::from(
                $exercise,
                $exercise->workoutSession->logged_date->toDateString(),
            ))->all();

            $row = $nextRows[$slug] ?? null;

            $out[] = [
                'recommendation' => $this->engine->evaluate(
                    $entries->first()?->exercise_name ?? $names[$slug] ?? $slug,
                    $slug,
                    $records,
                    $row !== null ? $this->targetOf($row) : null,
                    $safety,
                    $today,
                ),
                'row' => $row,
            ];
        }

        return $out;
    }

    /**
     * The next genuinely upcoming (still pending) planned row for each exact exercise.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, WorkoutPlanExercise> keyed by slug; each carries its plan's date as `plan_date`
     */
    private function nextRows(User $user, array $slugs): array
    {
        $rows = WorkoutPlanExercise::query()
            ->select('workout_plan_exercises.*', 'workout_plans.planned_date as plan_date')
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $user->id)
            ->whereDate('workout_plans.planned_date', '>=', $user->localToday())
            ->whereIn('workout_plan_exercises.exercise_slug', $slugs)
            ->where('workout_plan_exercises.status', WorkoutPlanExercise::STATUS_PENDING)
            ->orderBy('workout_plans.planned_date')
            ->orderBy('workout_plan_exercises.id')
            ->get();

        $next = [];

        foreach ($rows as $row) {
            $next[$row->exercise_slug] ??= $row;
        }

        return $next;
    }

    /** @return array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, source: string} */
    private function targetOf(WorkoutPlanExercise $row): array
    {
        return [
            'sets' => $row->planned_sets,
            'reps' => $row->planned_reps,
            'weight_kg' => $row->planned_weight_kg !== null ? (float) $row->planned_weight_kg : null,
            'duration_seconds' => $row->planned_duration_seconds,
            'source' => 'next_planned',
        ];
    }
}
