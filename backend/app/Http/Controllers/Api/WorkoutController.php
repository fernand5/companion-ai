<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkoutSessionRequest;
use App\Http\Resources\WorkoutSessionResource;
use App\Services\Fitness\WorkoutService;
use Illuminate\Http\Request;

class WorkoutController extends Controller
{
    public function __construct(private readonly WorkoutService $workoutService) {}

    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);

        return WorkoutSessionResource::collection($this->workoutService->recent($request->user(), $days));
    }

    public function store(StoreWorkoutSessionRequest $request)
    {
        $session = $this->workoutService->log($request->user(), $request->validated());

        return new WorkoutSessionResource($session);
    }
}
