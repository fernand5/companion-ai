<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdaptWeeklyPlanRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\WeeklyPlanCoachService;
use App\Services\Fitness\WeeklyPlanService;
use Illuminate\Http\Request;

class WeeklyPlanController extends Controller
{
    public function __construct(
        private readonly WeeklyPlanCoachService $weeklyPlanCoachService,
        private readonly WeeklyPlanService $weeklyPlanService,
    ) {}

    /**
     * Fill in the current week where it's missing something — either a fully
     * empty week (initial generation from profile/goals) or specific days
     * that have a recurring TrainingSchedule commitment but no plan yet (a
     * targeted fill, e.g. a plan created ad hoc via Coach chat for one day
     * must not block the other recurring days from ever being generated).
     * A week that's already fully covered is a fast no-op — no AI call — so
     * this is safe to call on every page load. Retrieval itself is handled
     * by the existing GET /workout-plans?from=&to= endpoint; this only ever
     * writes.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        $weekRange = $this->weeklyPlanService->weekRange($user);

        $missingScheduledDays = $this->weeklyPlanService->scheduledDaysMissingPlans($user);

        $weekHasAnyPlan = $user->workoutPlans()
            ->whereDate('planned_date', '>=', $weekRange['start'])
            ->whereDate('planned_date', '<=', $weekRange['end'])
            ->exists();

        if ($missingScheduledDays === [] && $weekHasAnyPlan) {
            return response()->json(['applied' => false, 'explanation' => 'This week already reflects your recurring schedule.']);
        }

        $instruction = $missingScheduledDays !== []
            ? 'Generate plans for these specific days, which have a recurring schedule commitment but currently '
                .'have no plan: '.implode(', ', $missingScheduledDays).'. Leave every other day exactly as it is.'
            : 'Generate an initial plan for this week.';

        try {
            $result = $this->weeklyPlanCoachService->run($user, $instruction);
        } catch (AiProviderException $e) {
            return response()->json(['message' => $e->userFacingMessage()], 503);
        }

        return response()->json($result);
    }

    public function adapt(AdaptWeeklyPlanRequest $request)
    {
        try {
            $result = $this->weeklyPlanCoachService->run($request->user(), $request->string('input')->toString());
        } catch (AiProviderException $e) {
            return response()->json(['message' => $e->userFacingMessage()], 503);
        }

        return response()->json($result);
    }
}
