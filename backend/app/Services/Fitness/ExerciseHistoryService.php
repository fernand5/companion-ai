<?php

namespace App\Services\Fitness;

use App\Models\User;
use App\Models\WorkoutExercise;
use Illuminate\Support\Collection;

/**
 * Read-only view of what a user actually did for one exercise over time,
 * newest first. Only exercises that have recorded sets count as performance:
 * a row with no sets (e.g. a partial with unknown amount) is not history.
 */
class ExerciseHistoryService
{
    /**
     * @return Collection<int, WorkoutExercise>
     */
    public function recent(User $user, string $slug, int $limit = 5): Collection
    {
        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->where('workout_exercises.exercise_slug', $slug)
            ->whereHas('performedSets')
            ->with(['performedSets', 'workoutSession'])
            ->orderByDesc('workout_sessions.logged_date')
            ->orderByDesc('workout_exercises.id')
            ->limit(min(max($limit, 1), 20))
            ->get();
    }
}
