<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkoutPlanRequest;
use App\Http\Requests\UpdateWorkoutPlanExerciseRequest;
use App\Http\Resources\WorkoutPlanResource;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WorkoutPlanController extends Controller
{
    public function __construct(private readonly WorkoutPlanService $workoutPlanService) {}

    public function index(Request $request)
    {
        if ($request->filled('from') || $request->filled('to')) {
            $plans = $this->workoutPlanService->forRange(
                $request->user(),
                $request->query('from', Carbon::today()->subDays(6)->toDateString()),
                $request->query('to', Carbon::today()->toDateString()),
            );

            return WorkoutPlanResource::collection($plans);
        }

        $date = $request->query('date', Carbon::today()->toDateString());
        $plan = $this->workoutPlanService->forDate($request->user(), $date);

        return $plan ? new WorkoutPlanResource($plan) : response()->noContent();
    }

    public function store(StoreWorkoutPlanRequest $request)
    {
        $plan = $this->workoutPlanService->createOrReplace($request->user(), [
            ...$request->validated(),
            'source' => $request->validated('source') ?? WorkoutPlan::SOURCE_MANUAL,
        ]);

        return new WorkoutPlanResource($plan);
    }

    public function updateExercise(UpdateWorkoutPlanExerciseRequest $request, WorkoutPlan $plan, WorkoutPlanExercise $exercise)
    {
        abort_unless($plan->user_id === $request->user()->id, 403);
        abort_unless($exercise->workout_plan_id === $plan->id, 404);

        $this->workoutPlanService->updateExerciseStatus($request->user(), $exercise, $request->validated());

        return new WorkoutPlanResource($plan->fresh('exercises'));
    }
}
