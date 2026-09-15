<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;

class GetTodaysPlanTool implements AiTool
{
    public function __construct(private readonly WorkoutPlanService $workoutPlanService) {}

    public function name(): string
    {
        return 'get_todays_plan';
    }

    public function description(): string
    {
        return "Get the user's workout plan for today (or a given date), including each exercise's completion status.";
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $date = $arguments['date'] ?? Carbon::today()->toDateString();
        $plan = $this->workoutPlanService->forDate($user, $date);

        if (! $plan) {
            return ['error' => "No plan exists for {$date}."];
        }

        return self::present($plan);
    }

    public static function present(WorkoutPlan $plan): array
    {
        return [
            'planned_date' => $plan->planned_date->toDateString(),
            'activity_type' => $plan->activity_type,
            'title' => $plan->title,
            'source' => $plan->source,
            'status' => $plan->status,
            'duration_minutes' => $plan->duration_minutes,
            'reasoning' => $plan->reasoning,
            'reasoning_factors' => $plan->reasoning_factors,
            'notes' => $plan->notes,
            'exercises' => $plan->exercises->map(fn ($exercise) => [
                'exercise_name' => $exercise->exercise_name,
                'planned_sets' => $exercise->planned_sets,
                'planned_reps' => $exercise->planned_reps,
                'planned_weight_kg' => $exercise->planned_weight_kg !== null ? (float) $exercise->planned_weight_kg : null,
                'planned_duration_seconds' => $exercise->planned_duration_seconds,
                'status' => $exercise->status,
                'actual_sets' => $exercise->actual_sets,
                'actual_reps' => $exercise->actual_reps,
                'actual_weight_kg' => $exercise->actual_weight_kg !== null ? (float) $exercise->actual_weight_kg : null,
            ])->all(),
        ];
    }
}
