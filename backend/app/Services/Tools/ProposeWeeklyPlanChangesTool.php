<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\WeeklyPlanService;
use Illuminate\Validation\ValidationException;

class ProposeWeeklyPlanChangesTool implements AiTool
{
    public function __construct(private readonly WeeklyPlanService $weeklyPlanService) {}

    public function name(): string
    {
        return 'propose_weekly_plan_changes';
    }

    public function description(): string
    {
        return "Propose targeted changes to the CURRENT week's structured plan (Monday-Sunday) in response to "
            .'something that affects it — a new commitment, a schedule conflict, fatigue/soreness, or a logged '
            .'activity that changes what should happen next. Only include the smallest set of days that actually '
            .'need to change in `changes` — every day you omit is left exactly as it is. Pass an empty `changes` '
            .'array when nothing material changed, still explaining why in decision_summary. Never rewrite the '
            .'whole week for one input.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decision_summary' => ['type' => 'string', 'description' => 'One sentence: what changed this week and why, grounded in the input.'],
                'reasoning_factors' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '2-4 short, concrete, data-grounded reasons — must trace back to the actual input or context, never invented.',
                ],
                'changes' => [
                    'type' => 'array',
                    'description' => 'The smallest set of days that need to change. Omitted days are left as-is. Empty array is valid when nothing material changed.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, must be within the current week.'],
                            'action' => ['type' => 'string', 'description' => 'One of: replace, clear.'],
                            'activity_type' => ['type' => 'string', 'description' => 'Required when action=replace. One of: steps, treadmill, strength, sport, recovery.'],
                            'title' => ['type' => 'string', 'description' => 'Required when action=replace.'],
                            'duration_minutes' => ['type' => 'integer'],
                            'exercises' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'exercise_name' => ['type' => 'string'],
                                        'planned_sets' => ['type' => 'integer'],
                                        'planned_reps' => ['type' => 'integer'],
                                        'planned_weight_kg' => ['type' => 'number'],
                                        'planned_duration_seconds' => ['type' => 'integer'],
                                    ],
                                    'required' => ['exercise_name'],
                                ],
                            ],
                            'reason' => ['type' => 'string', 'description' => 'Required. Why THIS day changed, grounded in the input.'],
                        ],
                        'required' => ['date', 'action', 'reason'],
                    ],
                ],
            ],
            'required' => ['decision_summary', 'reasoning_factors', 'changes'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        if (empty($arguments['decision_summary']) || empty($arguments['reasoning_factors'])) {
            return ['error' => 'decision_summary and reasoning_factors are required.'];
        }

        if (! array_key_exists('changes', $arguments) || ! is_array($arguments['changes'])) {
            return ['error' => 'changes must be an array (it may be empty).'];
        }

        try {
            $result = $this->weeklyPlanService->applyChanges($user, $arguments['changes'], $arguments['reasoning_factors']);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return array_merge([
            'decision_summary' => $arguments['decision_summary'],
            'reasoning_factors' => $arguments['reasoning_factors'],
        ], $result);
    }
}
