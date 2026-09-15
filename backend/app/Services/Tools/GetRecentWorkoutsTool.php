<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Fitness\WorkoutService;

class GetRecentWorkoutsTool implements AiTool
{
    public function __construct(private readonly WorkoutService $workoutService) {}

    public function name(): string
    {
        return 'get_recent_workouts';
    }

    public function description(): string
    {
        return 'Get recent strength workout sessions with their exercises (sets, reps, weight), default last 14 days.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How many days back to include, default 14.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $days = min(max((int) ($arguments['days'] ?? 14), 1), 60);

        return $this->workoutService->recent($user, $days)
            ->map(fn (WorkoutSession $session) => [
                'date' => $session->logged_date->toDateString(),
                'duration_minutes' => $session->duration_minutes,
                'notes' => $session->notes,
                'exercises' => $session->exercises->map(fn ($e) => [
                    'exercise_name' => $e->exercise_name,
                    'sets' => $e->sets,
                    'reps' => $e->reps,
                    'weight_kg' => $e->weight_kg !== null ? (float) $e->weight_kg : null,
                ])->all(),
            ])
            ->all();
    }
}
