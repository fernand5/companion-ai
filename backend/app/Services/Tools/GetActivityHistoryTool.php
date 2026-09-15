<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetActivityHistoryTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_activity_history';
    }

    public function description(): string
    {
        return 'Get activity logs within a date range, optionally filtered by type (steps, treadmill, strength, sport, recovery, weight).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                'type' => ['type' => 'string', 'description' => 'Optional activity type filter.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        return $this->activityService->history(
            $user,
            $arguments['start_date'] ?? null,
            $arguments['end_date'] ?? null,
            $arguments['type'] ?? null,
        )->map(fn ($log) => GetRecentActivityTool::present($log))->all();
    }
}
