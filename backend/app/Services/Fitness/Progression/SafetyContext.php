<?php

namespace App\Services\Fitness\Progression;

/**
 * What the record says about the person's recovery and limits, kept to facts the
 * engine can act on. It deliberately holds no free text: a pain note's words are
 * never copied into a recommendation, only the fact that one exists.
 *
 * Unknown stays unknown. No recovery information is not "recovered".
 */
final class SafetyContext
{
    public function __construct(
        public readonly bool $painReportedRecently = false,
        public readonly ?int $latestSoreness = null,
        public readonly ?int $daysSinceLatestCheckin = null,
        public readonly bool $restrictionOnRecord = false,
        public readonly ?string $fitnessLevel = null,
    ) {}

    public function highSoreness(): bool
    {
        return $this->latestSoreness !== null
            && $this->latestSoreness >= ProgressionPolicy::HIGH_SORENESS
            && $this->daysSinceLatestCheckin !== null
            && $this->daysSinceLatestCheckin <= ProgressionPolicy::RECOVERY_CURRENT_DAYS;
    }

    public function recoveryInformationIsCurrent(): bool
    {
        return $this->daysSinceLatestCheckin !== null && $this->daysSinceLatestCheckin <= ProgressionPolicy::RECOVERY_CURRENT_DAYS;
    }
}
