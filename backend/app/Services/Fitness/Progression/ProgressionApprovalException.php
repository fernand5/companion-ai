<?php

namespace App\Services\Fitness\Progression;

use RuntimeException;

/** Why an approval was refused, as a stable code the client can act on, with the HTTP status to answer with. */
final class ProgressionApprovalException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** The submitted proposal is not one this application issued (altered, truncated, or not a proposal). */
    public static function invalid(): self
    {
        return new self('invalid_proposal', 'That proposal is not valid. Refresh your recommendations and try again.', 422);
    }

    public static function notYours(): self
    {
        return new self('forbidden', 'That proposal does not belong to this account.', 403);
    }

    public static function expired(): self
    {
        return new self('expired', 'That recommendation has expired. Refresh to get a fresh one.', 409);
    }

    /** The plan, the history or the safety picture moved since it was issued. */
    public static function stale(string $detail = 'Your plan or recent history changed since this recommendation was made. Refresh to get a fresh one.'): self
    {
        return new self('stale', $detail, 409);
    }

    public static function alreadyUsed(): self
    {
        return new self('already_used', 'That recommendation was already used. Refresh to see your current plan.', 409);
    }

    /** The user already chose to keep this target for this exact recommendation. */
    public static function dismissed(): self
    {
        return new self('dismissed', 'You chose to keep this target. A new recommendation appears if you log new sets or your plan changes.', 409);
    }

    public static function ineligible(string $reason): self
    {
        return new self('ineligible', 'This recommendation can no longer be applied ('.$reason.'). Refresh to get a fresh one.', 409);
    }
}
