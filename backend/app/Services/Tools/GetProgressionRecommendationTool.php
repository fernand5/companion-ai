<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\Progression\ProgressionService;

/**
 * Read-only: returns what the progression engine calculated, so the coach can
 * explain it. It applies nothing and the engine owns every number in the result.
 * Off unless config('ai.progression_tool_enabled') is true (see ToolServiceProvider):
 * it has not been evaluated against the real model.
 */
class GetProgressionRecommendationTool implements AiTool
{
    public function __construct(private readonly ProgressionService $progression) {}

    public function name(): string
    {
        return 'get_progression_recommendation';
    }

    public function description(): string
    {
        return 'Get the calculated progression recommendation (INCREASE, MAINTAIN, DECREASE, INSUFFICIENT_DATA or '
            .'MANUAL_REVIEW) for one exercise, or for the exercises done recently, with its reason codes, supporting sessions and '
            .'safety caveats. Use it only to EXPLAIN a recommendation. Quote proposed_target exactly; never calculate, round or '
            .'invent another target, never turn a decision into a stronger one, and say that nothing has been changed.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'exercise_name' => ['type' => 'string', 'description' => 'The exact exercise name. Omit for the exercises done recently.'],
            ],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $name = isset($arguments['exercise_name']) && is_string($arguments['exercise_name']) ? trim($arguments['exercise_name']) : '';

        $recommendations = $name !== ''
            ? [$this->progression->recommendFor($user, $name)]
            : $this->progression->recommendAll($user, 6);

        return [
            'recommendations' => array_map(fn ($recommendation) => $recommendation->toArray(), $recommendations),
            'note' => 'Calculated by the application. Nothing has been applied or changed.',
        ];
    }
}
