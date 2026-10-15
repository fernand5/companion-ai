<?php

namespace App\Services\Fitness;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Support\StrengthSessions;
use Illuminate\Support\Collection;

/**
 * Read-only view of what a user actually did for one exercise over time,
 * newest first. Matching is by EXACT exercise slug: "Dumbbell Row" and
 * "Dumbbell Rows" are different identities here on purpose (see ExerciseSlug).
 *
 * By default only exercises that have recorded sets count as performance: a row
 * with no sets (e.g. a partial with unknown amount) is not history. Callers that
 * need to know an exercise was skipped or left unmeasured can opt in to those rows.
 * Callers that want strength work only (not recovery/treadmill/sport plans) can opt
 * in to that too; see StrengthSessions for how, and for its limits.
 */
class ExerciseHistoryService
{
    /**
     * @return Collection<int, WorkoutExercise>
     */
    public function recent(User $user, string $slug, int $limit = 5, bool $includeUnmeasured = false, bool $strengthSessionsOnly = false): Collection
    {
        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->where('workout_exercises.exercise_slug', $slug)
            ->when($strengthSessionsOnly, fn ($query) => StrengthSessions::only($query))
            ->when(! $includeUnmeasured, fn ($query) => $query->whereHas('performedSets'))
            ->with(['performedSets', 'workoutSession', 'planExercise'])
            ->orderByDesc('workout_sessions.logged_date')
            ->orderByDesc('workout_exercises.id')
            ->limit(min(max($limit, 1), 20))
            ->get();
    }

    /**
     * The same history for several exact exercises in one query (plus the usual
     * eager loads), newest first within each: the cost of asking about 8 exercises
     * is the cost of asking about 1. Bounded by the date window, then cut to
     * $perExercise in memory.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, Collection<int, WorkoutExercise>> keyed by slug; a slug with no history is absent
     */
    public function recentForSlugs(User $user, array $slugs, int $perExercise, string $since, bool $includeUnmeasured = false, bool $strengthSessionsOnly = false): array
    {
        if ($slugs === []) {
            return [];
        }

        $perExercise = min(max($perExercise, 1), 20);

        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->whereIn('workout_exercises.exercise_slug', $slugs)
            ->whereDate('workout_sessions.logged_date', '>=', $since)
            ->when($strengthSessionsOnly, fn ($query) => StrengthSessions::only($query))
            ->when(! $includeUnmeasured, fn ($query) => $query->whereHas('performedSets'))
            ->with(['performedSets', 'workoutSession', 'planExercise'])
            ->orderByDesc('workout_sessions.logged_date')
            ->orderByDesc('workout_exercises.id')
            ->get()
            ->groupBy('exercise_slug')
            ->map(fn (Collection $rows) => $rows->take($perExercise)->values())
            ->all();
    }
}
