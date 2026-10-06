<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ExerciseHistoryService;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Support\ExerciseSlug;

/**
 * Read-only. One exercise's recorded history for the authenticated user, newest
 * first, through the same service the history endpoint uses. The exercise is
 * matched by its exact normalized name; nothing here can query anything else.
 */
class GetExerciseHistoryTool implements AiTool
{
    public const DEFAULT_LIMIT = 3;

    public const MAX_LIMIT = 5;

    public function __construct(private readonly ExerciseHistoryService $history) {}

    public function name(): string
    {
        return 'get_exercise_history';
    }

    public function description(): string
    {
        return 'Get the user\'s recorded history for ONE exercise, newest first (default 3 sessions, max 5). '
            .'Each session has the date, the planned target at that time (planned_*), the sets actually performed (performed_sets / performed_summary), '
            .'recorded_as (entered = typed by the user, migrated = from an older freeform log, as_planned = only a one-tap "done" with NO performance numbers, performance_measured=false) '
            .'and outcome (completed, completed_as_planned, partial, skipped, pending, no_measurable_performance). '
            .'The exercise is matched by its exact name only: "Dumbbell Row" and "Dumbbell Rows" (or "Lunges" and "Dumbbell Lunges") are separate exercises and are NOT merged, '
            .'so an empty result does not mean a similar exercise was never done.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'The exercise name as the user knows it (e.g. "Bench Press") or its slug (e.g. "bench-press").'],
                'limit' => ['type' => 'integer', 'description' => 'How many most recent sessions, default '.self::DEFAULT_LIMIT.', max '.self::MAX_LIMIT.'.'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $slug = ExerciseSlug::from(is_string($arguments['exercise'] ?? null) ? $arguments['exercise'] : '');

        if ($slug === null) {
            return ['error' => 'exercise is required: the name of one exercise, e.g. "Bench Press".'];
        }

        $limit = min(max((int) ($arguments['limit'] ?? self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);

        $sessions = $this->history->recent($user, $slug, $limit, includeUnmeasured: true)
            ->map(fn ($exercise) => ExercisePerformanceRecord::from($exercise, $exercise->workoutSession->logged_date->toDateString())->toArray())
            ->values()
            ->all();

        $result = ['exercise_slug' => $slug, 'sessions' => $sessions];

        if ($sessions === []) {
            $result['note'] = 'No recorded sessions under this exact exercise name. Similar names are separate exercises and are not merged.';
        }

        return $result;
    }
}
