<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\WorkoutService;
use Illuminate\Validation\ValidationException;

class LogWorkoutTool implements AiTool
{
    public function __construct(private readonly WorkoutService $workoutService) {}

    public function name(): string
    {
        return 'log_workout';
    }

    public function description(): string
    {
        return 'Log a completed strength workout session with its individual exercises (sets, reps, weight).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'logged_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD of when it actually happened. Defaults to today; must not be in the future.'],
                'duration_minutes' => ['type' => 'integer'],
                'notes' => ['type' => 'string'],
                'exercises' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'exercise_name' => ['type' => 'string'],
                            'sets' => ['type' => 'integer'],
                            'reps' => ['type' => 'integer'],
                            'weight_kg' => ['type' => 'number'],
                            'duration_seconds' => ['type' => 'integer'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['exercise_name'],
                    ],
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        try {
            $session = $this->workoutService->log($user, $arguments);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return [
            'date' => $session->logged_date->toDateString(),
            'duration_minutes' => $session->duration_minutes,
            'exercises' => $session->exercises->map(fn ($e) => [
                'exercise_name' => $e->exercise_name,
                'sets' => $e->sets,
                'reps' => $e->reps,
                'weight_kg' => $e->weight_kg !== null ? (float) $e->weight_kg : null,
            ])->all(),
        ];
    }
}
