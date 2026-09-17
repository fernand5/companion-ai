<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_local_now_uses_the_users_stored_timezone(): void
    {
        // Frozen just after midnight UTC — already "tomorrow" in UTC, but
        // still the evening before in America/Bogota (UTC-5).
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', 'UTC'));

        $user = User::factory()->create(['timezone' => 'America/Bogota']);

        $this->assertSame('2026-09-16', $user->localToday());
        $this->assertSame(16, $user->localNow()->day);
    }

    public function test_local_now_falls_back_to_the_app_timezone_when_unset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', 'UTC'));

        $user = User::factory()->create(['timezone' => null]);

        $this->assertSame(config('app.timezone'), 'UTC');
        $this->assertSame('2026-09-17', $user->localToday());
    }
}
