<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\PlanReplacementNotAuthorized;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CreateWorkoutPlanTool implements AiTool
{
    public function __construct(private readonly WorkoutPlanService $workoutPlanService) {}

    public function name(): string
    {
        return 'create_workout_plan';
    }

    public function description(): string
    {
        return 'Author (or replace) the workout plan for a date. Use this whenever telling the user what to do '
            .'next — it is how the recommendation becomes something they can check off, not just chat text. '
            .'Always populate reasoning_factors and decision_summary, grounded in the actual context you were given '
            .'(recent activity, recovery, schedule) — never invent a factor.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'planned_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today.'],
                'activity_type' => ['type' => 'string', 'description' => 'One of: steps, treadmill, strength, sport, recovery.'],
                'title' => ['type' => 'string', 'description' => 'Short title, e.g. "Upper Body Strength".'],
                'duration_minutes' => ['type' => 'integer'],
                'decision_summary' => ['type' => 'string', 'description' => 'One-sentence summary of the recommendation.'],
                'reasoning_factors' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '2-4 short, concrete, data-grounded reasons, e.g. "Played soccer yesterday" or "Recovery check-in showed low energy".',
                ],
                'exercises' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'exercise_name' => ['type' => 'string'],
                            'planned_sets' => ['type' => 'integer'],
                            'planned_reps' => ['type' => 'integer'],
                            'planned_weight_kg' => ['type' => 'number', 'description' => 'Only when the user has a recorded or planned weight for this exact exercise, or gave one themselves. Otherwise omit it: never choose a starting weight.'],
                            'planned_duration_seconds' => ['type' => 'integer'],
                        ],
                        'required' => ['exercise_name'],
                    ],
                ],
            ],
            'required' => ['activity_type', 'title', 'decision_summary', 'reasoning_factors'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        // reasoning_factors is only optional in the shared service (manual/schedule
        // sources don't need it) — for AI-authored plans it's mandatory, so an
        // explanation always exists for the "why this changed" UI.
        if (empty($arguments['reasoning_factors']) || empty($arguments['decision_summary'])) {
            return ['error' => 'reasoning_factors and decision_summary are required when creating a plan.'];
        }

        try {
            $plan = $this->workoutPlanService->createOrReplace($user, [
                'planned_date' => $arguments['planned_date'] ?? null,
                'activity_type' => $arguments['activity_type'] ?? null,
                'title' => $arguments['title'] ?? null,
                'duration_minutes' => $arguments['duration_minutes'] ?? null,
                'reasoning' => $arguments['decision_summary'] ?? null,
                'reasoning_factors' => $arguments['reasoning_factors'] ?? null,
                'exercises' => $arguments['exercises'] ?? null,
                'source' => 'ai',
            ], ReplacementGrant::permits($arguments, $this->dateOf($arguments, $user)));
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        } catch (PlanReplacementNotAuthorized $e) {
            return ['error' => "Nothing was changed: {$e->date} already has a plan and the user has not authorized replacing it."];
        }

        return GetTodaysPlanTool::present($plan);
    }

    private function dateOf(array $arguments, User $user): string
    {
        try {
            return CarbonImmutable::parse($arguments['planned_date'] ?? $user->localToday())->toDateString();
        } catch (\Throwable) {
            return (string) $user->localToday();
        }
    }
}
