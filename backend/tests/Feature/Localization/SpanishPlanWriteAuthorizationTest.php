<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\PlanWriteAuthorizationTest;
use Tests\Support\UsesSpanishLocale;

/** PlanWriteAuthorizationTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishPlanWriteAuthorizationTest extends PlanWriteAuthorizationTest
{
    use UsesSpanishLocale;
}
