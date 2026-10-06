<?php

namespace App\Support;

use App\Models\ActivityLog;

/**
 * How a completed plan is represented on the unified activity timeline.
 *
 * The plan's own activity_type (the existing taxonomy: steps, treadmill,
 * strength, sport, recovery) is the source of truth. Only genuine strength work
 * is "high" intensity; a recovery/mobility session is low, and for the types
 * whose effort the plan does not tell us (treadmill, sport, steps) intensity is
 * left unknown rather than invented. A session with no plan (freeform strength
 * logging) is strength, as before.
 */
final class PlanMirrorClassification
{
    /**
     * @return array{type: string, intensity: ?string}
     */
    public static function for(?string $planActivityType): array
    {
        return match ($planActivityType) {
            ActivityLog::TYPE_RECOVERY => ['type' => ActivityLog::TYPE_RECOVERY, 'intensity' => 'low'],
            ActivityLog::TYPE_TREADMILL => ['type' => ActivityLog::TYPE_TREADMILL, 'intensity' => null],
            ActivityLog::TYPE_SPORT => ['type' => ActivityLog::TYPE_SPORT, 'intensity' => null],
            ActivityLog::TYPE_STEPS => ['type' => ActivityLog::TYPE_STEPS, 'intensity' => null],
            default => ['type' => ActivityLog::TYPE_STRENGTH, 'intensity' => 'high'],
        };
    }
}
