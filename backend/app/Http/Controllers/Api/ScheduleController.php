<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingScheduleRequest;
use App\Http\Requests\UpdateTrainingScheduleRequest;
use App\Http\Resources\TrainingScheduleResource;
use App\Models\TrainingSchedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        return TrainingScheduleResource::collection(
            $request->user()->trainingSchedules()->orderBy('day_of_week')->orderBy('start_time')->get()
        );
    }

    public function store(StoreTrainingScheduleRequest $request)
    {
        $schedule = $request->user()->trainingSchedules()->create($request->validated());

        return new TrainingScheduleResource($schedule);
    }

    public function update(UpdateTrainingScheduleRequest $request, TrainingSchedule $schedule)
    {
        abort_unless($schedule->user_id === $request->user()->id, 403);

        $schedule->update($request->validated());

        return new TrainingScheduleResource($schedule);
    }

    public function destroy(Request $request, TrainingSchedule $schedule)
    {
        abort_unless($schedule->user_id === $request->user()->id, 403);

        $schedule->delete();

        return response()->json(null, 204);
    }
}
