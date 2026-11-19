<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\CoachStyleProductionSafeguardsTest;
use Tests\Support\UsesSpanishLocale;

/** CoachStyleProductionSafeguardsTest unchanged, for a user whose saved language is Spanish: every voice must still reach the model and change no outcome. */
class SpanishCoachStyleProductionSafeguardsTest extends CoachStyleProductionSafeguardsTest
{
    use UsesSpanishLocale;
}
