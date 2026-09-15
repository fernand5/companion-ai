<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\WeeklySummaryService;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    public function __construct(private readonly WeeklySummaryService $weeklySummaryService) {}

    public function weeklySummary(Request $request)
    {
        try {
            $summary = $this->weeklySummaryService->getForCurrentWeek($request->user());
        } catch (AiProviderException $e) {
            return response()->json(['message' => $e->userFacingMessage()], 503);
        }

        return response()->json([
            'week_start' => $summary->week_start->toDateString(),
            'stats' => $summary->stats,
            'coach_insight' => $summary->coach_insight,
            'next_week_focus' => $summary->next_week_focus,
            'generated_at' => $summary->generated_at->toIso8601String(),
        ]);
    }
}
