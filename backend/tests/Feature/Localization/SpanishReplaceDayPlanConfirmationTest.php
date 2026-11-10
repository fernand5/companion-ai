<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\ReplaceDayPlanConfirmationTest;
use Tests\Support\UsesSpanishLocale;

/** ReplaceDayPlanConfirmationTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishReplaceDayPlanConfirmationTest extends ReplaceDayPlanConfirmationTest
{
    use UsesSpanishLocale;
}
