<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetTodayActivityTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_today_activity';
    }

    public function description(): string
    {
        return 'Get everything logged today: steps, workouts, cardio, sport, recovery.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, User $user): mixed
    {
        return $this->activityService->today($user)
            ->map(fn (ActivityLog $log) => GetRecentActivityTool::present($log))
            ->all();
    }
}
