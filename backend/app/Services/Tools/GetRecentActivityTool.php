<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetRecentActivityTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_recent_activity';
    }

    public function description(): string
    {
        return 'Get the activity logged over the last N days (default 7): steps, treadmill, strength, sport, recovery. '
            .'Treadmill/sport entries include distance_km (null = "distance not recorded"), and speed_kmh / pace derived from it. '
            .'interval_implied_distance_km, when present, is only a rough cross-check of the recorded intervals; distance_km is the authoritative distance.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How many days back to include, default 7.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $days = min(max((int) ($arguments['days'] ?? 7), 1), 30);

        return $this->activityService->recent($user, $days)
            ->map(fn (ActivityLog $log) => $this->present($log))
            ->all();
    }

    /**
     * The activity as the coach sees it. Treadmill and sport logs also carry the
     * structured distance: distance_km is the explicit recorded value (null =
     * "distance not recorded", never guessed); speed and pace are derived from
     * it on read. interval_implied_distance_km (only when intervals were
     * recorded) is a rough cross-check and NOT authoritative.
     */
    public static function present(ActivityLog $log): array
    {
        $presented = [
            'type' => $log->type,
            'date' => $log->logged_date->toDateString(),
            'duration_minutes' => $log->duration_minutes,
            'intensity' => $log->intensity,
            'notes' => $log->notes,
            'metadata' => $log->metadata,
        ];

        if (! in_array($log->type, [ActivityLog::TYPE_TREADMILL, ActivityLog::TYPE_SPORT], true)) {
            return $presented;
        }

        $pace = $log->paceSecondsPerKm();

        $presented['distance_km'] = $log->distance_km !== null ? (float) $log->distance_km : null;
        $presented['speed_kmh'] = $log->speedKmh();
        $presented['pace_seconds_per_km'] = $pace;
        $presented['pace'] = $pace !== null ? sprintf('%d:%02d /km', intdiv($pace, 60), $pace % 60) : null;

        if ($log->distance_km === null) {
            $presented['distance_note'] = 'distance not recorded';
        }

        if (($implied = $log->intervalImpliedDistanceKm()) !== null) {
            $presented['interval_implied_distance_km'] = $implied;
        }

        return $presented;
    }
}
