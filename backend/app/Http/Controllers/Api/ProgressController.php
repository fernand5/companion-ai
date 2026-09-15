<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fitness\ProgressService;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(private readonly ProgressService $progressService) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'summary' => $this->progressService->summary($user),
            'weight_series' => $this->progressService->weightSeries($user),
            'steps_series' => $this->progressService->stepsSeries($user),
            'weekly_workouts' => $this->progressService->weeklyWorkoutSeries($user),
        ]);
    }
}
