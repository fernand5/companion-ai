<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ScheduleService;

class GetUpcomingScheduleTool implements AiTool
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function name(): string
    {
        return 'get_upcoming_schedule';
    }

    public function description(): string
    {
        return 'Get the recurring schedule (e.g. football) expanded into concrete upcoming dates over the next N days (default 7).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How many days ahead to include, default 7.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $days = min(max((int) ($arguments['days'] ?? 7), 1), 14);

        return $this->scheduleService->upcoming($user, $days)
            ->map(fn (array $occurrence) => [
                'date' => $occurrence['date'],
                'activity_type' => $occurrence['schedule']->activity_type,
                'start_time' => $occurrence['schedule']->start_time,
                'expected_duration_minutes' => $occurrence['schedule']->expected_duration_minutes,
                'intensity' => $occurrence['schedule']->intensity,
                'notes' => $occurrence['schedule']->notes,
            ])
            ->all();
    }
}
