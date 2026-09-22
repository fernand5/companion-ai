<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ActivityService;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LogActivityTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'log_activity';
    }

    public function description(): string
    {
        return 'Log a completed activity: steps, treadmill/cardio, sport, or recovery check-in. '
            .'For strength workouts with individual exercises, use log_workout instead. For body weight, use update_weight.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'One of: steps, treadmill, sport, recovery.'],
                'logged_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD of when it actually happened. Defaults to today; must not be in the future.'],
                'duration_minutes' => ['type' => 'integer'],
                'intensity' => ['type' => 'string', 'description' => 'low, moderate, or high.'],
                'notes' => ['type' => 'string'],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Type-specific details, e.g. {"steps": 6200} or {"sport": "Football", "format": "match"} '
                        .'or {"distance_km": 3.2, "intervals": [{"minutes":12,"speed_kmh":9}]} or {"energy":4,"soreness":2,"sleep_hours":7.5}.',
                ],
            ],
            'required' => ['type'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        try {
            $log = $this->activityService->log($user, $arguments);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        return GetRecentActivityTool::present($log);
    }
}
