<?php

namespace Tests\Support;

use App\Models\User;

/**
 * Makes a PerformanceEvalCase suite run with a user who chose Spanish. In M0 the language changes no
 * prompt, parser or authorization rule, so a suite must give exactly the same results for this user;
 * a subclass that uses this trait re-runs the parent's tests unchanged against that user.
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
