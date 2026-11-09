<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * A user's chosen coach voice, read from their fitness profile. Always resolves to something
 * valid: a user with no profile row, or a stored value this code no longer recognises, gets the
 * defaults rather than an error, so a preference problem can never stop a coaching reply.
 */
final readonly class CoachingPreferences
{
    public function __construct(
        public CoachStyle $style = CoachStyle::DEFAULT,
        public ResponseDetail $detail = ResponseDetail::DEFAULT,
    ) {}

    public static function forUser(User $user): self
    {
        $profile = $user->fitnessProfile;

        return new self(
            CoachStyle::tryFrom((string) $profile?->coach_style) ?? CoachStyle::DEFAULT,
            ResponseDetail::tryFrom((string) $profile?->response_detail) ?? ResponseDetail::DEFAULT,
        );
    }

    /** Part of the dashboard recommendation's cache key, so changing either setting shows at once. */
    public function key(): string
    {
        return $this->style->value.'.'.$this->detail->value;
    }
}
