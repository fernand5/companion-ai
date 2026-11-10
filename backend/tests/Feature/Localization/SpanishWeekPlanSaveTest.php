<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\WeekPlanSaveTest;
use Tests\Support\UsesSpanishLocale;

/** WeekPlanSaveTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishWeekPlanSaveTest extends WeekPlanSaveTest
{
    use UsesSpanishLocale;
}
