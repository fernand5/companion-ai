<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetActivityForDateTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_activity_for_date';
    }

    public function description(): string
    {
        return 'Get all activity logged on a specific date (YYYY-MM-DD).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
            ],
            'required' => ['date'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        if (empty($arguments['date'])) {
            return ['error' => 'date is required.'];
        }

        return $this->activityService->forDate($user, $arguments['date'])
            ->map(fn ($log) => GetRecentActivityTool::present($log))
            ->all();
    }
}
