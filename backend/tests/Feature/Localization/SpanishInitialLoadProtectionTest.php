<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\InitialLoadProtectionTest;
use Tests\Support\UsesSpanishLocale;

/** InitialLoadProtectionTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishInitialLoadProtectionTest extends InitialLoadProtectionTest
{
    use UsesSpanishLocale;
}
