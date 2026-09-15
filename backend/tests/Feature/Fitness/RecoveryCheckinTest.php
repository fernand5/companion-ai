<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Services\Fitness\RecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecoveryCheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_creates_a_checkin_for_today_by_default(): void
    {
        $user = User::factory()->create();

        $checkin = app(RecoveryService::class)->upsert($user, ['energy' => 4, 'soreness' => 2, 'motivation' => 4]);

        $this->assertSame(Carbon::today()->toDateString(), $checkin->checkin_date->toDateString());
        $this->assertSame(1, $user->recoveryCheckins()->count());
    }

    public function test_resubmitting_the_same_date_updates_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $service = app(RecoveryService::class);

        $service->upsert($user, ['energy' => 3, 'soreness' => 3, 'motivation' => 3]);
        $second = $service->upsert($user, ['energy' => 1, 'soreness' => 5, 'motivation' => 1, 'pain_notes' => 'Sore knees']);

        $this->assertSame(1, $user->recoveryCheckins()->count());
        $this->assertSame(1, $second->energy);
        $this->assertSame('Sore knees', $second->pain_notes);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(RecoveryService::class)->upsert($user, ['energy' => 6, 'soreness' => 2, 'motivation' => 3]);
    }

    public function test_latest_returns_the_most_recent_checkin(): void
    {
        $user = User::factory()->create();
        $service = app(RecoveryService::class);

        $service->upsert($user, ['checkin_date' => Carbon::today()->subDays(2)->toDateString(), 'energy' => 2, 'soreness' => 2, 'motivation' => 2]);
        $service->upsert($user, ['checkin_date' => Carbon::today()->toDateString(), 'energy' => 5, 'soreness' => 1, 'motivation' => 5]);

        $latest = $service->latest($user);

        $this->assertSame(5, $latest->energy);
    }
}
