<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\SaveWorkoutToDashboardTest;
use Tests\Support\UsesSpanishLocale;

/** SaveWorkoutToDashboardTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishSaveWorkoutToDashboardTest extends SaveWorkoutToDashboardTest
{
    use UsesSpanishLocale;
}
