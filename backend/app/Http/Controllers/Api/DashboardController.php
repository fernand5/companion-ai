<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\FitnessProfileResource;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\AiProviderException;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\ProgressService;
use App\Services\Fitness\ScheduleService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ActivityService $activityService,
        private readonly ScheduleService $scheduleService,
        private readonly ProgressService $progressService,
        private readonly AiCoachService $coachService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $recommendation = null;
        $recommendationUnavailableReason = config('ai.api_key') ? null : 'not_configured';

        if (config('ai.api_key')) {
            try {
                $recommendation = $this->coachService->dashboardRecommendation($user);
            } catch (AiProviderException $e) {
                $recommendationUnavailableReason = $e->userFacingReason();
            }
        }

        return response()->json([
            'profile' => $user->fitnessProfile ? new FitnessProfileResource($user->fitnessProfile) : null,
            'today_activity' => ActivityLogResource::collection($this->activityService->today($user)),
            'upcoming_schedule' => $this->scheduleService->upcoming($user, 3)->map(fn (array $o) => [
                'date' => $o['date'],
                'activity_type' => $o['schedule']->activity_type,
                'start_time' => substr((string) $o['schedule']->start_time, 0, 5),
                'intensity' => $o['schedule']->intensity,
            ])->values(),
            'progress' => $this->progressService->summary($user),
            'coach_recommendation' => $recommendation,
            'coach_recommendation_unavailable_reason' => $recommendationUnavailableReason,
        ]);
    }
}
