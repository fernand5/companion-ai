<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWeightRequest;
use App\Http\Resources\ActivityLogResource;
use App\Services\Fitness\WeightService;
use Illuminate\Http\Request;

class WeightController extends Controller
{
    public function __construct(private readonly WeightService $weightService) {}

    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 30);

        return ActivityLogResource::collection($this->weightService->history($request->user(), $limit));
    }

    public function store(UpdateWeightRequest $request)
    {
        $log = $this->weightService->update($request->user(), $request->validated());

        return new ActivityLogResource($log);
    }
}
