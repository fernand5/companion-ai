<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\NoUnapprovedLoadRaisesTest;
use Tests\Support\UsesSpanishLocale;

/** NoUnapprovedLoadRaisesTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishNoUnapprovedLoadRaisesTest extends NoUnapprovedLoadRaisesTest
{
    use UsesSpanishLocale;
}
