<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\ConfirmedProposalAuthorizationTest;
use Tests\Support\UsesSpanishLocale;

/** ConfirmedProposalAuthorizationTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishConfirmedProposalAuthorizationTest extends ConfirmedProposalAuthorizationTest
{
    use UsesSpanishLocale;
}
