<?php

namespace App\Services\Ai\Authorization;

/**
 * What a tool can do to the user's data, in the only terms authorization cares
 * about. Reading and recording what the user reports are always allowed; the
 * three plan capabilities change what the user has been told to do, so each
 * needs the user's request for it (see PlanChangeIntentClassifier).
 */
enum ToolCapability: string
{
    /** Looks something up. Changes nothing. */
    case Read = 'read';

    /** Stores a fact the user stated (an activity, a weight, a preference, a profile field). */
    case Record = 'record';

    /** Marks planned exercises done, partial or skipped, and stores the sets reported with them. */
    case PlanProgress = 'plan_progress';

    /** Writes (or replaces) the plan for a day. */
    case PlanCreate = 'plan_create';

    /** Replaces or clears days of the current week's plan. */
    case PlanAdapt = 'plan_adapt';

    /** True for the capabilities that change a plan or its progress; false for reading and recording. */
    public function needsAuthorization(): bool
    {
        return match ($this) {
            self::Read, self::Record => false,
            self::PlanProgress, self::PlanCreate, self::PlanAdapt => true,
        };
    }
}
