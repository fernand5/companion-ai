<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActivityLogRequest;
use App\Http\Requests\UpdateActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\Fitness\ActivityService;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function index(Request $request)
    {
        $logs = $this->activityService->history(
            $request->user(),
            $request->query('start_date'),
            $request->query('end_date'),
            $request->query('type'),
        );

        return ActivityLogResource::collection($logs);
    }

    public function store(StoreActivityLogRequest $request)
    {
        $log = $this->activityService->log($request->user(), $request->validated());

        return new ActivityLogResource($log);
    }

    public function show(Request $request, ActivityLog $activity)
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        return new ActivityLogResource($activity);
    }

    public function update(UpdateActivityLogRequest $request, ActivityLog $activity)
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->update($request->validated());

        return new ActivityLogResource($activity);
    }

    public function destroy(Request $request, ActivityLog $activity)
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->delete();

        return response()->json(null, 204);
    }
}
