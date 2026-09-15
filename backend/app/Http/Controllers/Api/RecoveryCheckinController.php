<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecoveryCheckinRequest;
use App\Http\Resources\RecoveryCheckinResource;
use App\Services\Fitness\RecoveryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RecoveryCheckinController extends Controller
{
    public function __construct(private readonly RecoveryService $recoveryService) {}

    public function index(Request $request)
    {
        $date = $request->query('date', Carbon::today()->toDateString());

        $checkin = $date === Carbon::today()->toDateString()
            ? $this->recoveryService->today($request->user())
            : $request->user()->recoveryCheckins()->whereDate('checkin_date', $date)->first();

        return $checkin ? new RecoveryCheckinResource($checkin) : response()->noContent();
    }

    public function store(StoreRecoveryCheckinRequest $request)
    {
        $checkin = $this->recoveryService->upsert($request->user(), $request->validated());

        return new RecoveryCheckinResource($checkin);
    }
}
