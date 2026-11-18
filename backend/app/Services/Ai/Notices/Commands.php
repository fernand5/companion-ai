<?php

namespace App\Services\Ai\Notices;

use App\Support\AppLocale;

/**
 * The phrases a message names for the user to type, in the language of the message. The parsers read both languages for
 * everyone, so this only chooses which words the user is shown: the phrase set of their own language.
 */
final class Commands
{
    /** @return array<string, string> */
    public static function for(AppLocale $locale): array
    {
        return $locale === AppLocale::Spanish ? SpanishCommands::all() : EnglishCommands::all();
    }
}
