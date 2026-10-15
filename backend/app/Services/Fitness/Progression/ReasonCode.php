<?php

namespace App\Services\Fitness\Progression;

/**
 * Why the engine decided what it did, as stable codes (for tests, logs and for
 * the coach to explain from) with a plain-language meaning. The explanation is
 * a lookup, never generated, so it can only say what the code says.
 */
enum ReasonCode: string
{
    case NoMeasuredHistory = 'NO_MEASURED_HISTORY';
    case OnlyAssumedCompletions = 'ONLY_ASSUMED_COMPLETIONS';
    case NeedMoreSessions = 'NEED_MORE_SESSIONS';
    case StaleHistory = 'STALE_HISTORY';
    case LatestSessionNotMeasured = 'LATEST_SESSION_NOT_MEASURED';
    case MixedExerciseIdentities = 'MIXED_EXERCISE_IDENTITIES';
    case MigratedEvidence = 'MIGRATED_EVIDENCE';
    case NoTargetRecorded = 'NO_TARGET_RECORDED';
    case TimedExerciseUnsupported = 'TIMED_EXERCISE_UNSUPPORTED';
    case NoLoadRecorded = 'NO_LOAD_RECORDED';
    case LoadPartiallyRecorded = 'LOAD_PARTIALLY_RECORDED';
    case TargetsReachedConsecutively = 'TARGETS_REACHED_CONSECUTIVELY';
    case TargetNotReached = 'TARGET_NOT_REACHED';
    case RepeatedMisses = 'REPEATED_MISSES';
    case MissesAtSameLoad = 'MISSES_AT_SAME_LOAD';
    case IncompleteSession = 'INCOMPLETE_SESSION';
    case InconsistentResults = 'INCONSISTENT_RESULTS';
    case ConsolidateAtCurrentLoad = 'CONSOLIDATE_AT_CURRENT_LOAD';
    case LoadChangedRecently = 'LOAD_CHANGED_RECENTLY';
    case SessionsOnTooFewDays = 'SESSIONS_ON_TOO_FEW_DAYS';
    case TargetAlreadyAtProposed = 'TARGET_ALREADY_AT_PROPOSED';
    case CurrentTargetExceedsMaximumChange = 'CURRENT_TARGET_EXCEEDS_MAX_CHANGE';
    case LoadStepExceedsLimit = 'LOAD_STEP_EXCEEDS_LIMIT';
    case PainReportedRecently = 'PAIN_REPORTED_RECENTLY';
    case HighSoreness = 'HIGH_SORENESS';
    case RestrictionOnRecord = 'RESTRICTION_ON_RECORD';

    public function meaning(): string
    {
        return match ($this) {
            self::NoMeasuredHistory => 'No session of this exercise has recorded sets.',
            self::OnlyAssumedCompletions => 'This exercise was only marked done with one tap, which records the plan, not what was lifted.',
            self::NeedMoreSessions => 'Fewer than '.ProgressionPolicy::SESSIONS_REQUIRED.' measured sessions of this exercise.',
            self::StaleHistory => 'The latest measured session is more than '.ProgressionPolicy::STALE_AFTER_DAYS.' days old.',
            self::LatestSessionNotMeasured => 'The most recent session of this exercise has no recorded sets (skipped, pending or marked done with one tap).',
            self::MixedExerciseIdentities => 'The history mixes different exercises, which are never combined.',
            self::MigratedEvidence => 'The decision would rest on migrated history, which may be incomplete.',
            self::NoTargetRecorded => 'No target was recorded for these sessions, so whether they were met is unknown.',
            self::TimedExerciseUnsupported => 'This is a duration-based exercise; the policy does not progress those automatically.',
            self::NoLoadRecorded => 'No load was recorded, so a bodyweight exercise cannot be told from a missing value.',
            self::LoadPartiallyRecorded => 'The load was recorded for some completed sets and not others.',
            self::TargetsReachedConsecutively => 'The target was reached in each of the last '.ProgressionPolicy::SESSIONS_REQUIRED.' sessions at the same load.',
            self::TargetNotReached => 'The latest session did not reach its target.',
            self::RepeatedMisses => 'The target was missed in consecutive sessions at the same load.',
            self::MissesAtSameLoad => 'The target was missed in each of the last '.ProgressionPolicy::MISSES_FOR_DECREASE.' sessions at the same load.',
            self::IncompleteSession => 'The latest session was incomplete.',
            self::InconsistentResults => 'The target was reached in some recent sessions at this load and missed in others.',
            self::ConsolidateAtCurrentLoad => 'Fewer than '.ProgressionPolicy::SESSIONS_REQUIRED.' consecutive sessions at the current load and target.',
            self::LoadChangedRecently => 'The load changed within the last '.ProgressionPolicy::SESSIONS_REQUIRED.' sessions.',
            self::SessionsOnTooFewDays => 'The sessions were not on '.ProgressionPolicy::SESSIONS_REQUIRED.' different days.',
            self::TargetAlreadyAtProposed => 'The current target is already at or above the load the record supports.',
            self::CurrentTargetExceedsMaximumChange => 'The current target is already above the maximum permitted change from the heaviest recorded load (a cap, not a recommended load).',
            self::LoadStepExceedsLimit => 'The smallest load change available for this exercise is more than '.(int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100).'% of the load.',
            self::PainReportedRecently => 'A recovery check-in in the last '.ProgressionPolicy::PAIN_WINDOW_DAYS.' days mentions pain.',
            self::HighSoreness => 'The latest recovery check-in reports high soreness.',
            self::RestrictionOnRecord => 'The user has a saved injury, restriction or limitation that cannot be matched to an exercise.',
        };
    }
}
