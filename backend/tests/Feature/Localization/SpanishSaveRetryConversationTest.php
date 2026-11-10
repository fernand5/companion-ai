<?php

namespace Tests\Feature\Localization;

use Tests\Feature\Ai\SaveRetryConversationTest;
use Tests\Support\UsesSpanishLocale;

/** SaveRetryConversationTest unchanged, for a user whose saved language is Spanish: the language must not change any outcome. */
class SpanishSaveRetryConversationTest extends SaveRetryConversationTest
{
    use UsesSpanishLocale;
}
