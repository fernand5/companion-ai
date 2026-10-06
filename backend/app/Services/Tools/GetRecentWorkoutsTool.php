<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Fitness\ExercisePerformanceRecord;
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
        return 'Get recent workout sessions (default last 14 days) with each exercise\'s record. For every exercise: '
            .'planned_* is the target that was set at that time; performed_sets lists the sets actually done, one entry per set, '
            .'and performed_summary is the same in one line (e.g. "8,8,7 @ 45 kg"). '
            .'recorded_as says how reliable the numbers are: entered = typed by the user, migrated = from the user\'s older freeform log, '
            .'as_planned = the user only tapped done, so NO performance numbers are given (performance_measured=false) and the planned targets must not be treated as what was done. '
            .'outcome tells whether it was completed, completed_as_planned, partial, skipped, pending or no_measurable_performance. '
            .'Omitted keys in a set mean not recorded. Use get_exercise_history for one exercise across sessions.';
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
                'workout_session_id' => $session->id,
                'duration_minutes' => $session->duration_minutes,
                'notes' => $session->notes,
                'exercises' => $session->exercises
                    ->map(fn ($exercise) => ExercisePerformanceRecord::from($exercise)->toArray(includeSession: false))
                    ->all(),
            ])
            ->all();
    }
}
