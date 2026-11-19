<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Fitness\ProgressionApprovalTest;
use Tests\Support\UsesSpanishLocale;

/** ProgressionApprovalTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishProgressionApprovalTest extends ProgressionApprovalTest
{
    use UsesSpanishLocale;
}
