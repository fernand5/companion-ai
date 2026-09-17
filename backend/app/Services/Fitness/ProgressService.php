<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;

class ProgressService
{
    public function __construct(
        private readonly ActivityService $activityService,
        private readonly WeightService $weightService,
    ) {}

    /**
     * @return array{
     *   current_weight_kg: ?float, starting_weight_kg: ?float, weight_change_kg: ?float,
     *   workouts_this_week: int, average_steps: float
     * }
     */
    public function summary(User $user, int $windowDays = 30): array
    {
        $weightHistory = $this->weightService->history($user, 100)->sortBy('logged_date')->values();
        $current = $weightHistory->last()?->metadata['weight_kg'] ?? $user->fitnessProfile?->weight_kg;
        $starting = $weightHistory->first()?->metadata['weight_kg'] ?? $current;

        $week = $this->activityService->weeklySummary($user);

        $today = $user->localNow();
        $recentSteps = $this->activityService->history(
            $user,
            $today->copy()->subDays($windowDays - 1)->toDateString(),
            $today->toDateString(),
            ActivityLog::TYPE_STEPS,
        );

        return [
            'current_weight_kg' => $current !== null ? (float) $current : null,
            'starting_weight_kg' => $starting !== null ? (float) $starting : null,
            'weight_change_kg' => ($current !== null && $starting !== null) ? round((float) $current - (float) $starting, 1) : null,
            'workouts_this_week' => $week['by_type'][ActivityLog::TYPE_STRENGTH] ?? 0,
            'average_steps' => $recentSteps->isEmpty()
                ? 0.0
                : round($recentSteps->avg(fn (ActivityLog $log) => $log->metadata['steps'] ?? 0), 0),
        ];
    }

    /**
     * @return array<int, array{date: string, weight_kg: float}>
     */
    public function weightSeries(User $user, int $limit = 60): array
    {
        return $this->weightService->history($user, $limit)
            ->sortBy('logged_date')
            ->map(fn (ActivityLog $log) => [
                'date' => $log->logged_date->toDateString(),
                'weight_kg' => (float) $log->metadata['weight_kg'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{date: string, steps: int}>
     */
    public function stepsSeries(User $user, int $days = 30): array
    {
        $today = $user->localNow();

        return $this->activityService
            ->history($user, $today->copy()->subDays($days - 1)->toDateString(), $today->toDateString(), ActivityLog::TYPE_STEPS)
            ->sortBy('logged_date')
            ->map(fn (ActivityLog $log) => [
                'date' => $log->logged_date->toDateString(),
                'steps' => (int) ($log->metadata['steps'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{week_start: string, workouts: int}>
     */
    public function weeklyWorkoutSeries(User $user, int $weeks = 8): array
    {
        $series = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $reference = $user->localNow()->subWeeks($i);
            $week = $this->activityService->weeklySummary($user, $reference);

            $series[] = [
                'week_start' => $week['week_start'],
                'workouts' => $week['by_type'][ActivityLog::TYPE_STRENGTH] ?? 0,
            ];
        }

        return $series;
    }
}
