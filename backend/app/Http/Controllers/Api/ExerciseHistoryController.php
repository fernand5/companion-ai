<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fitness\ExerciseHistoryService;
use App\Support\ExerciseSlug;
use Illuminate\Http\Request;

class ExerciseHistoryController extends Controller
{
    public function __construct(private readonly ExerciseHistoryService $history) {}

    /**
     * The authenticated user's own recorded performance for one exercise. The
     * path segment is normalized, so a raw name ("Goblet Squat") works as well
     * as a slug. `recorded_as` tells the caller how much to trust each entry:
     * `as_planned` is an assumption, not a measurement.
     */
    public function show(Request $request, string $exercise)
    {
        $slug = ExerciseSlug::from($exercise);

        abort_if($slug === null, 404);

        $entries = $this->history->recent($request->user(), $slug, (int) $request->query('limit', 5));

        return response()->json([
            'exercise_slug' => $slug,
            'sessions' => $entries->map(fn ($e) => [
                'date' => $e->workoutSession->logged_date->toDateString(),
                'workout_session_id' => $e->workout_session_id,
                'exercise_name' => $e->exercise_name,
                'recorded_as' => $e->recorded_as ?? 'migrated',
                'planned' => $e->planned_sets !== null || $e->planned_reps !== null || $e->planned_weight_kg !== null || $e->planned_duration_seconds !== null ? [
                    'sets' => $e->planned_sets,
                    'reps' => $e->planned_reps,
                    'weight_kg' => $e->planned_weight_kg !== null ? (float) $e->planned_weight_kg : null,
                    'duration_seconds' => $e->planned_duration_seconds,
                ] : null,
                'sets' => $e->performedSets->map(fn ($s) => [
                    'set_number' => $s->set_number,
                    'reps' => $s->reps,
                    'weight_kg' => $s->weight_kg !== null ? (float) $s->weight_kg : null,
                    'duration_seconds' => $s->duration_seconds,
                    'completed' => $s->completed,
                ])->values(),
            ])->values(),
        ]);
    }
}
