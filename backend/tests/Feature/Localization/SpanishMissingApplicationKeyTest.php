<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Fitness\MissingApplicationKeyTest;
use Tests\Support\UsesSpanishLocale;

/** MissingApplicationKeyTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishMissingApplicationKeyTest extends MissingApplicationKeyTest
{
    use UsesSpanishLocale;
}
