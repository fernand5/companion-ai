<?php

/*
 * The words the progression endpoints show a person: why the engine decided what it did, its safety caveats, and why
 * an approval was refused.
 *
 * These are PRESENTATION only. What the engine decides, the reason codes and every load come from the calculation,
 * which is not touched. English here is word for word what the engine and ReasonCode::meaning() produce (a test
 * compares them); the model-facing tool keeps reading ReasonCode::meaning() directly, in English.
 *
 * Numbers that are policy (":sessions", ":stale_days" …) are filled in from ProgressionPolicy at presentation time,
 * never typed here, so a policy change cannot leave a translation quoting an old number.
 */
return [
    // Keyed by ReasonCode->value.
    'reasons' => [
        'NO_MEASURED_HISTORY' => 'No session of this exercise has recorded sets.',
        'ONLY_ASSUMED_COMPLETIONS' => 'This exercise was only marked done with one tap, which records the plan, not what was lifted.',
        'NEED_MORE_SESSIONS' => 'Fewer than :sessions measured sessions of this exercise.',
        'STALE_HISTORY' => 'The latest measured session is more than :stale_days days old.',
        'LATEST_SESSION_NOT_MEASURED' => 'The most recent session of this exercise has no recorded sets (skipped, pending or marked done with one tap).',
        'MIXED_EXERCISE_IDENTITIES' => 'The history mixes different exercises, which are never combined.',
        'MIGRATED_EVIDENCE' => 'The decision would rest on migrated history, which may be incomplete.',
        'NO_TARGET_RECORDED' => 'No target was recorded for these sessions, so whether they were met is unknown.',
        'TIMED_EXERCISE_UNSUPPORTED' => 'This is a duration-based exercise; the policy does not progress those automatically.',
        'NO_LOAD_RECORDED' => 'No load was recorded, so a bodyweight exercise cannot be told from a missing value.',
        'LOAD_PARTIALLY_RECORDED' => 'The load was recorded for some completed sets and not others.',
        'TARGETS_REACHED_CONSECUTIVELY' => 'The target was reached in each of the last :sessions sessions at the same load.',
        'TARGET_NOT_REACHED' => 'The latest session did not reach its target.',
        'REPEATED_MISSES' => 'The target was missed in consecutive sessions at the same load.',
        'MISSES_AT_SAME_LOAD' => 'The target was missed in each of the last :misses sessions at the same load.',
        'INCOMPLETE_SESSION' => 'The latest session was incomplete.',
        'INCONSISTENT_RESULTS' => 'The target was reached in some recent sessions at this load and missed in others.',
        'CONSOLIDATE_AT_CURRENT_LOAD' => 'Fewer than :sessions consecutive sessions at the current load and target.',
        'LOAD_CHANGED_RECENTLY' => 'The load changed within the last :sessions sessions.',
        'SESSIONS_ON_TOO_FEW_DAYS' => 'The sessions were not on :sessions different days.',
        'TARGET_ALREADY_AT_PROPOSED' => 'The current target is already at or above the load the record supports.',
        'CURRENT_TARGET_EXCEEDS_MAX_CHANGE' => 'The current target is already above the maximum permitted change from the heaviest recorded load (a cap, not a recommended load).',
        'LOAD_STEP_EXCEEDS_LIMIT' => 'The smallest load change available for this exercise is more than :percent% of the load.',
        'PAIN_REPORTED_RECENTLY' => 'A recovery check-in in the last :pain_days days mentions pain.',
        'HIGH_SORENESS' => 'The latest recovery check-in reports high soreness.',
        'RESTRICTION_ON_RECORD' => 'The user has a saved injury, restriction or limitation that cannot be matched to an exercise.',
    ],

    // Each caveat has a stable identity (the key), returned as `safety_caveat_codes` beside the text.
    'caveats' => [
        'effort_not_recorded' => 'Effort (RPE), repetitions in reserve and technique are not recorded: a reached target does not show the load felt manageable.',
        'not_medical_clearance' => 'This is a calculation from a training log, not medical clearance or a readiness assessment.',
        'recovery_unknown' => 'No recovery check-in in the last :recovery_days days, so recovery is unknown.',
        'pain_reported' => 'A recent recovery check-in mentions pain. Do not add load without checking with a qualified professional if it persists.',
        'high_soreness' => 'The latest recovery check-in reports high soreness.',
        'restriction_unmatched' => 'A saved injury, restriction or limitation could not be matched to a specific exercise, so a person should check it applies or not.',
    ],

    // Keyed by ProgressionApprovalException->reason (the stable `code` of the response).
    'approval' => [
        'invalid_proposal' => 'That proposal is not valid. Refresh your recommendations and try again.',
        'forbidden' => 'That proposal does not belong to this account.',
        'expired' => 'That recommendation has expired. Refresh to get a fresh one.',
        'stale' => 'Your plan or recent history changed since this recommendation was made. Refresh to get a fresh one.',
        'already_used' => 'That recommendation was already used. Refresh to see your current plan.',
        'dismissed' => 'You chose to keep this target. A new recommendation appears if you log new sets or your plan changes.',
        'ineligible' => 'This recommendation can no longer be applied (:detail). Refresh to get a fresh one.',
        // Used when an `ineligible` refusal names a detail this list does not know.
        'ineligible_unknown' => 'This recommendation can no longer be applied. Refresh to get a fresh one.',
    ],

    // Why a recommendation could not be applied (the closed set the approval service names). English keeps the code
    // itself, as the API has always returned it; Spanish says it in words.
    'ineligible' => [
        'not_a_change' => 'not_a_change',
        'no_upcoming_session' => 'no_upcoming_session',
        'no_load_in_plan' => 'no_load_in_plan',
        'workout_already_started' => 'workout_already_started',
        'plan_already_at_or_above' => 'plan_already_at_or_above',
        'above_maximum_change' => 'above_maximum_change',
        'plan_already_at_or_below' => 'plan_already_at_or_below',
    ],
];
