<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\CoachStylePromptTest;
use Tests\Support\UsesSpanishLocale;

/** CoachStylePromptTest unchanged, for a user whose saved language is Spanish: every voice must still reach the model and change no outcome. */
class SpanishCoachStylePromptTest extends CoachStylePromptTest
{
    use UsesSpanishLocale;

    protected function dashboardLength(string $english, string $spanish): string
    {
        return $spanish;
    }
}
