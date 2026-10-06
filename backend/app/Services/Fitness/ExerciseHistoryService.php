<?php

namespace App\Services\Fitness;

use App\Models\User;
use App\Models\WorkoutExercise;
use Illuminate\Support\Collection;

/**
 * Read-only view of what a user actually did for one exercise over time,
 * newest first. Matching is by EXACT exercise slug: "Dumbbell Row" and
 * "Dumbbell Rows" are different identities here on purpose (see ExerciseSlug).
 *
 * By default only exercises that have recorded sets count as performance: a row
 * with no sets (e.g. a partial with unknown amount) is not history. Callers that
 * need to know an exercise was skipped or left unmeasured can opt in to those rows.
 */
class ExerciseHistoryService
{
    /**
     * @return Collection<int, WorkoutExercise>
     */
    public function recent(User $user, string $slug, int $limit = 5, bool $includeUnmeasured = false): Collection
    {
        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->where('workout_exercises.exercise_slug', $slug)
            ->when(! $includeUnmeasured, fn ($query) => $query->whereHas('performedSets'))
            ->with(['performedSets', 'workoutSession', 'planExercise'])
            ->orderByDesc('workout_sessions.logged_date')
            ->orderByDesc('workout_exercises.id')
            ->limit(min(max($limit, 1), 20))
            ->get();
    }
}
