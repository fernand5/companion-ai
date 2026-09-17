<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Validation\ValidationException;

class UpdateExerciseStatusTool implements AiTool
{
    public function __construct(private readonly WorkoutPlanService $workoutPlanService) {}

    public function name(): string
    {
        return 'update_exercise_status';
    }

    public function description(): string
    {
        return "Mark one of today's (or a given date's) planned exercises completed, partial, or skipped, "
            .'based on what the user reports in chat — keeps chat-reported progress consistent with the app.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'exercise_name' => ['type' => 'string', 'description' => 'Must match a planned exercise name for the date.'],
                'status' => ['type' => 'string', 'description' => 'One of: completed, partial, skipped, pending.'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today.'],
                'actual_sets' => ['type' => 'integer'],
                'actual_reps' => ['type' => 'integer'],
                'actual_weight_kg' => ['type' => 'number'],
                'actual_duration_seconds' => ['type' => 'integer'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['exercise_name', 'status'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        if (empty($arguments['exercise_name']) || empty($arguments['status'])) {
            return ['error' => 'exercise_name and status are required.'];
        }

        $date = $arguments['date'] ?? $user->localToday();
        $exercise = $this->workoutPlanService->findExerciseByName($user, $date, $arguments['exercise_name']);

        if (! $exercise) {
            return ['error' => "No planned exercise named \"{$arguments['exercise_name']}\" found for {$date}."];
        }

        try {
            $plan = $this->workoutPlanService->updateExerciseStatus($user, $exercise, $arguments);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return GetTodaysPlanTool::present($plan);
    }
}
