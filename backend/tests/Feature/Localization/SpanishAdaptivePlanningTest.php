<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Fitness\AdaptivePlanningTest;
use Tests\Support\UsesSpanishLocale;

/** AdaptivePlanningTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishAdaptivePlanningTest extends AdaptivePlanningTest
{
    use UsesSpanishLocale;
}
