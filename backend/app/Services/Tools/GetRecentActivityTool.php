<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetRecentActivityTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_recent_activity';
    }

    public function description(): string
    {
        return 'Get the activity logged over the last N days (default 7): steps, treadmill, strength, sport, recovery.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How many days back to include, default 7.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $days = min(max((int) ($arguments['days'] ?? 7), 1), 30);

        return $this->activityService->recent($user, $days)
            ->map(fn (ActivityLog $log) => $this->present($log))
            ->all();
    }

    public static function present(ActivityLog $log): array
    {
        return [
            'type' => $log->type,
            'date' => $log->logged_date->toDateString(),
            'duration_minutes' => $log->duration_minutes,
            'intensity' => $log->intensity,
            'notes' => $log->notes,
            'metadata' => $log->metadata,
        ];
    }
}
