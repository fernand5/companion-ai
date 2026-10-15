<?php

namespace App\Services\Fitness\Progression;

/**
 * Every number the progression engine (and the plan-load guard) decides with, in
 * one place, with the reason for it. Changing a value here is a change of policy
 * and bumps VERSION, which every recommendation carries.
 *
 * Assumptions (also in docs/progression-policy.md):
 *
 *  - Progressive overload is the established principle: load, repetitions or sets
 *    rise gradually once the current demand is met. The ACSM position stand on
 *    resistance training (2009) suggests a 2-10% load increase once the individual
 *    can do the current work for 1-2 repetitions over the goal on two consecutive
 *    sessions. That is a population guideline for supervised, healthy adults, not
 *    a rule that is safe for everyone: it says nothing about pain, injury,
 *    technique, equipment granularity or a person's history.
 *  - So this policy is stricter than the guideline. It needs three consecutive
 *    measured sessions (the data is self-entered, exercise identity is free text,
 *    and Phase 4C showed that two sessions get read as a trend), it uses the
 *    smallest practical step rather than a percentage, and it never exceeds the
 *    10% top of the guideline range.
 *  - "Target reached" is the exact comparison already used for the coach
 *    (ExercisePerformanceRecord::targetAttainment): every planned set done at or
 *    above the planned repetitions and load. Effort (RPE), repetitions in reserve,
 *    technique and recovery are NOT recorded, so a reached target is necessary for
 *    a progression, never sufficient evidence that one is safe.
 *  - Nothing here is medical advice, and nothing infers readiness or clearance
 *    from a workout log.
 */
final class ProgressionPolicy
{
    public const VERSION = 'progression-policy-1';

    /** Consecutive measured sessions at the same load and target that must all reach it before a load increase. */
    public const SESSIONS_REQUIRED = 3;

    /** Consecutive measured sessions at the same load and target that all miss it before a load decrease is suggested. */
    public const MISSES_FOR_DECREASE = 3;

    /** The largest single load increase, as a fraction of the load being progressed from (top of the 2-10% guideline range). */
    public const MAX_INCREASE_FRACTION = 0.10;

    /** Plans are written in the loads people can actually set; a ceiling is floored to this. */
    public const LOAD_QUANTUM_KG = 0.5;

    /** The latest measured session must be this recent, or the history says nothing about today. */
    public const STALE_AFTER_DAYS = 21;

    /** How far back sessions are considered at all. */
    public const HISTORY_DAYS = 90;

    /** How many recent sessions of one exercise are read (measured and unmeasured). */
    public const HISTORY_SESSIONS = 8;

    /** A pain note in a recovery check-in within this many days blocks automatic increases. */
    public const PAIN_WINDOW_DAYS = 14;

    /** Soreness (1-5) at or above this in the latest recent check-in blocks automatic increases. */
    public const HIGH_SORENESS = 4;

    /** A recovery check-in newer than this counts as current recovery information. */
    public const RECOVERY_CURRENT_DAYS = 7;

    /** The heaviest recorded load, over this many days, is the demonstrated load a plan may be raised from. */
    public const DEMONSTRATED_LOAD_DAYS = 180;

    /**
     * The smallest load change commonly available for a load of this size, used
     * only when the user's own history does not show a smaller step. Dumbbells and
     * plates differ by gym; the user's observed steps always take precedence.
     */
    public static function fallbackStepKg(float $loadKg): float
    {
        return match (true) {
            $loadKg < 10.0 => 1.0,
            $loadKg < 25.0 => 2.0,
            default => 2.5,
        };
    }

    /** The heaviest load a plan may carry for an exercise whose heaviest recorded load is $demonstratedKg. */
    public static function ceilingKg(float $demonstratedKg): float
    {
        $ceiling = $demonstratedKg * (1 + self::MAX_INCREASE_FRACTION);

        return floor($ceiling / self::LOAD_QUANTUM_KG) * self::LOAD_QUANTUM_KG;
    }
}
