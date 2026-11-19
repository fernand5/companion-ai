<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Support\AppLocale;

/**
 * How the coach presents itself to one user: the chosen voice (style and detail, from their fitness
 * profile) and the language they chose (from their account). Always resolves to something valid: a
 * user with no profile row, or a stored value this code no longer recognises, gets the defaults
 * rather than an error, so a preference problem can never stop a coaching reply.
 *
 * All three are presentation only: they change the words and the length, never what the coach may
 * do. The language is independent of the voice, and of the language the user writes in.
 */
final readonly class CoachingPreferences
{
    public function __construct(
        public CoachStyle $style = CoachStyle::DEFAULT,
        public ResponseDetail $detail = ResponseDetail::DEFAULT,
        public AppLocale $locale = AppLocale::DEFAULT,
    ) {}

    public static function forUser(User $user): self
    {
        $profile = $user->fitnessProfile;

        return new self(
            CoachStyle::tryFrom((string) $profile?->coach_style) ?? CoachStyle::DEFAULT,
            ResponseDetail::tryFrom((string) $profile?->response_detail) ?? ResponseDetail::DEFAULT,
            // The language is saved on the account (not the profile), so it resolves for a user with no profile too.
            $user->appLocale(),
        );
    }

    /** Part of the dashboard recommendation's cache key, so changing any of the three shows at once. */
    public function key(): string
    {
        return $this->style->value.'.'.$this->detail->value.'.'.$this->locale->value;
    }
}
