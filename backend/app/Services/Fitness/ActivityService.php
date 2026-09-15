<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ActivityService
{
    public const TYPES = [
        ActivityLog::TYPE_STEPS,
        ActivityLog::TYPE_TREADMILL,
        ActivityLog::TYPE_STRENGTH,
        ActivityLog::TYPE_SPORT,
        ActivityLog::TYPE_RECOVERY,
        ActivityLog::TYPE_WEIGHT,
    ];

    /**
     * @return Collection<int, ActivityLog>
     */
    public function recent(User $user, int $days = 7): Collection
    {
        return $this->history($user, Carbon::today()->subDays($days - 1)->toDateString(), Carbon::today()->toDateString());
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function forDate(User $user, string $date): Collection
    {
        return $user->activityLogs()
            ->whereDate('logged_date', $date)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function history(User $user, ?string $startDate = null, ?string $endDate = null, ?string $type = null): Collection
    {
        $query = $user->activityLogs()->orderByDesc('logged_date')->orderByDesc('created_at');

        if ($startDate) {
            $query->whereDate('logged_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('logged_date', '<=', $endDate);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function today(User $user): Collection
    {
        return $this->forDate($user, Carbon::today()->toDateString());
    }

    public function log(User $user, array $data): ActivityLog
    {
        $validated = Validator::make($data, [
            'type' => 'required|string|in:'.implode(',', self::TYPES),
            'logged_date' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'intensity' => 'nullable|string|in:low,moderate,high',
            'notes' => 'nullable|string|max:2000',
            'metadata' => 'nullable|array',
        ])->validate();

        if ($validated['type'] === ActivityLog::TYPE_WEIGHT) {
            throw new InvalidArgumentException('Use WeightService to log weight, not ActivityService::log().');
        }

        return $user->activityLogs()->create([
            'type' => $validated['type'],
            'logged_date' => $validated['logged_date'] ?? Carbon::today()->toDateString(),
            'duration_minutes' => $validated['duration_minutes'] ?? null,
            'intensity' => $validated['intensity'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);
    }

    /**
     * Aggregate the current week (Mon-Sun) of activity for the coach/dashboard.
     *
     * @return array{
     *   week_start: string, week_end: string,
     *   total_sessions: int, total_duration_minutes: int,
     *   by_type: array<string, int>, total_steps: int
     * }
     */
    public function weeklySummary(User $user, ?Carbon $reference = null): array
    {
        $reference ??= Carbon::today();
        $weekStart = $reference->copy()->startOfWeek();
        $weekEnd = $reference->copy()->endOfWeek();

        $logs = $this->history($user, $weekStart->toDateString(), $weekEnd->toDateString());

        $byType = $logs->groupBy('type')->map->count();

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'total_sessions' => $logs->whereNotIn('type', [ActivityLog::TYPE_WEIGHT])->count(),
            'total_duration_minutes' => (int) $logs->sum('duration_minutes'),
            'by_type' => $byType->all(),
            'total_steps' => (int) $logs->where('type', ActivityLog::TYPE_STEPS)->sum(fn (ActivityLog $log) => $log->metadata['steps'] ?? 0),
        ];
    }
}
