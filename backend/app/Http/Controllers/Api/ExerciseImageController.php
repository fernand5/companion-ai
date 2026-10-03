<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExerciseImage;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\ExerciseImageService;
use Illuminate\Http\Request;

class ExerciseImageController extends Controller
{
    public function __construct(private readonly ExerciseImageService $imageService) {}

    /**
     * Idempotent "make sure this exercise has a demonstration image", also used
     * for polling. POST because it may start generation. The exercise (and so
     * the prompt) is derived server-side from a row the caller owns — clients
     * can never supply prompt text, a slug, or an image.
     */
    public function store(Request $request, WorkoutPlan $plan, WorkoutPlanExercise $exercise)
    {
        abort_unless($plan->user_id === $request->user()->id, 403);
        abort_unless($exercise->workout_plan_id === $plan->id, 404);

        if ($exercise->exercise_slug === null) {
            return response()->json([
                'message' => 'No demonstration is available for this exercise.',
                'image_url' => null,
                'status' => 'unavailable',
            ], 422);
        }

        $image = $this->imageService->request($exercise->exercise_slug, $exercise->exercise_name);

        return match (true) {
            $image->isReady() => response()->json(['image_url' => $image->image_url, 'status' => 'ready']),
            $image->status === ExerciseImage::STATUS_FAILED => response()->json([
                'message' => 'The exercise demonstration is unavailable right now.',
                'image_url' => null,
                'status' => 'failed',
            ], 503),
            default => response()->json(['image_url' => null, 'status' => 'generating'], 202),
        };
    }
}
