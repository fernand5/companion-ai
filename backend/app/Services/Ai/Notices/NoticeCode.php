<?php

namespace App\Services\Ai\Notices;

/**
 * Stable, language-independent identifiers for the text the application itself writes into an assistant
 * message (see ReplyText). A code names WHAT the application said, never how it is worded, so renaming or
 * translating a notice cannot change what the code means.
 *
 * Codes are stored in messages.meta. A code is only ever a label: nothing reads one to decide what a user
 * may change.
 */
final class NoticeCode
{
    // The application cannot ask, or the change did not happen.
    public const CANNOT_ASK_CONFIRMATION = 'cannot_ask_confirmation';

    public const SAVE_FAILED = 'save_failed';

    public const SAVE_DAY_PERFORMED = 'save_day_performed';

    public const CONFIRMED_CHANGE_FAILED = 'confirmed_change_failed';

    public const PROPOSAL_NO_LONGER_VALID = 'proposal_no_longer_valid';

    public const NOTHING_PENDING = 'nothing_pending';

    public const EXPLICIT_REQUEST_NEEDED = 'explicit_request_needed';

    public const CHANGE_PROPOSED = 'change_proposed';

    public const ABOVE_MAXIMUM_CHANGE = 'above_maximum_change';

    public const CHANGE_NOT_SPECIFIC = 'change_not_specific';

    // What was done, or is waiting for the user's confirmation.
    public const REPLACEMENT_QUESTION = 'replacement_question';

    public const REPLACED_AS_ASKED = 'replaced_as_asked';

    public const RANGE_NOTE = 'range_note';

    public const LOAD_LEFT_UNSET = 'load_left_unset';

    public const REPLACEMENT_APPLIED = 'replacement_applied';

    public const REPLACEMENT_REFUSED = 'replacement_refused';

    public const REPLACEMENT_INVALID = 'replacement_invalid';

    public const WEEK_SAVED = 'week_saved';

    public const WEEK_BLOCKED = 'week_blocked';

    public const WEEK_ALREADY = 'week_already';

    public const WEEK_NOT_SAVED = 'week_not_saved';

    // Corrections of what the model claimed (see PlanClaims).
    public const CLAIM_ACCESS_CORRECTED = 'claim_access_corrected';

    public const CLAIM_SAVED_CORRECTED = 'claim_saved_corrected';

    public const CLAIM_PARTIAL_CORRECTED = 'claim_partial_corrected';

    /** A question the application asks about a save, one per follow-up kind: "save_question_which_day". */
    public const SAVE_QUESTION_PREFIX = 'save_question_';

    /** The codes that say, among other things, that the plan was NOT changed by this reply. */
    public const SAYS_NOTHING_CHANGED = [
        self::CANNOT_ASK_CONFIRMATION,
        self::SAVE_FAILED,
        self::SAVE_DAY_PERFORMED,
        self::EXPLICIT_REQUEST_NEEDED,
        self::CHANGE_PROPOSED,
        self::ABOVE_MAXIMUM_CHANGE,
        self::CHANGE_NOT_SPECIFIC,
        self::REPLACEMENT_QUESTION,
        self::CLAIM_ACCESS_CORRECTED,
        self::CLAIM_SAVED_CORRECTED,
    ];

    /** A code as it may be stored and read back: lower case, digits, underscores, bounded. */
    public static function isWellFormed(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) === 1;
    }
}
