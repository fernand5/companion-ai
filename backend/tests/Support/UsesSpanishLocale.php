<?php

namespace Tests\Support;

use App\Models\User;

/**
 * Makes a PerformanceEvalCase suite run with a user who chose Spanish. The language changes only the
 * words the model is asked to write in (and the dashboard request's wording), never an authorization,
 * parser or workout decision, so a suite must give the same outcomes for this user; a subclass that
 * uses this trait re-runs the parent's tests against that user.
 */
trait UsesSpanishLocale
{
    public function newUser(): User
    {
        return User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
    }

    public function test_the_user_under_test_chose_spanish(): void
    {
        $this->assertSame('es', $this->newUser()->locale);
    }
}
