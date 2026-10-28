<?php

namespace App\Services\Ai\Authorization;

use App\Models\WorkoutPlan;
use App\Support\ExerciseSlug;

/**
 * What a write to a day that ALREADY has an untouched plan amounts to. A day with nothing on it, and a day that has
 * been started or performed, are decided elsewhere; this is only for the day in between.
 *
 *  - NONE: the plan comes out the same. Nothing is replaced.
 *  - TARGETED: the same exercises in the same order, the same kind of activity; only targets (sets, reps, load,
 *    duration) or the title/length differ. This is an adjustment of the plan, which a request to change the plan
 *    covers ("update my bench to 45 kg"), and the load rules still apply to every number.
 *  - REPLACEMENT: anything else (different, added, removed or reordered exercises, another kind of activity, a rest
 *    day made of a workout). The user's workout is thrown away and another put in its place, so it needs the user's
 *    exact authorization.
 */
final class PlanChangeKind
{
    public const NONE = 'none';

    public const TARGETED = 'targeted';

    public const REPLACEMENT = 'replacement';

    /**
     * @param  array<string, mixed>  $new  create_workout_plan arguments (or one weekly "replace" change)
     */
    public static function of(WorkoutPlan $existing, array $new): string
    {
        $old = $existing->exercises;
        $given = is_array($new['exercises'] ?? null) ? array_values($new['exercises']) : [];

        if (($new['activity_type'] ?? null) !== $existing->activity_type || count($given) !== $old->count()) {
            return self::REPLACEMENT;
        }

        $same = ($new['title'] ?? null) === $existing->title
            && self::minutes($new['duration_minutes'] ?? null) === self::minutes($existing->duration_minutes);

        foreach ($old->values() as $i => $exercise) {
            $candidate = $given[$i] ?? null;

            if (! is_array($candidate) || ExerciseSlug::from(is_string($candidate['exercise_name'] ?? null) ? $candidate['exercise_name'] : null) !== ExerciseSlug::from($exercise->exercise_name)) {
                return self::REPLACEMENT;
            }

            $same = $same
                && self::number($candidate['planned_sets'] ?? null) === self::number($exercise->planned_sets)
                && self::number($candidate['planned_reps'] ?? null) === self::number($exercise->planned_reps)
                && self::number($candidate['planned_weight_kg'] ?? null) === self::number($exercise->planned_weight_kg)
                && self::number($candidate['planned_duration_seconds'] ?? null) === self::number($exercise->planned_duration_seconds);
        }

        return $same ? self::NONE : self::TARGETED;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function minutes(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
