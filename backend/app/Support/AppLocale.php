<?php

namespace App\Support;

/**
 * The languages the application is offered in. A language only chooses the words the user reads; it
 * is never an input to authorization, plan dates, exercise identity or any workout decision.
 *
 * English is the default and the fallback: a user who never chose, a stored value this code no longer
 * recognises and a request that names an unsupported language all resolve to English.
 */
enum AppLocale: string
{
    case English = 'en';
    case Spanish = 'es';

    public const DEFAULT = self::English;

    /** The request header a client uses to name its UI language when there is no signed-in user. */
    public const HEADER = 'X-App-Locale';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A supported language from a value the caller supplied, or null. Deliberately strict: exactly
     * "en" or "es". Nothing is guessed from case, region tags ("es-CO") or Accept-Language.
     */
    public static function fromSupplied(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** A stored value as a language, falling back to English if it is missing or unknown. */
    public static function fromStored(mixed $value): self
    {
        return self::fromSupplied($value) ?? self::DEFAULT;
    }
}
