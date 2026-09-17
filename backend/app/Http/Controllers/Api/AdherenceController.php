<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fitness\AdherenceService;
use Illuminate\Http\Request;

class AdherenceController extends Controller
{
    public function __construct(private readonly AdherenceService $adherenceService) {}

    public function index(Request $request)
    {
        $weeks = min(max((int) $request->query('weeks', 8), 1), 26);
        $user = $request->user();
        $today = $user->localNow();

        return response()->json([
            'series' => $this->adherenceService->weeklySeries($user, $weeks),
            'summary' => $this->adherenceService->summary(
                $user,
                $today->copy()->subDays(6)->toDateString(),
                $today->toDateString(),
            ),
        ]);
    }
}
