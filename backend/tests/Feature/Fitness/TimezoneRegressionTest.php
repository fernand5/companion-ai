<?php

namespace Tests\Feature\Fitness;

use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reported bug: a user in Medellín, Colombia (America/Bogota, UTC-5) logged
 * an activity that happened today (Wednesday) and it was instead attached to
 * Thursday — the same day as an already-scheduled soccer session. Root
 * cause: every "today" computation used Carbon::today()/now(), which reads
 * config('app.timezone') (UTC). From roughly 7pm to midnight Bogota time,
 * UTC has already rolled over to the next calendar day, so the server's
 * "today" was a full day ahead of the user's actual local day.
 *
 * These tests freeze the clock at exactly that kind of moment — past
 * midnight UTC, still the evening before in Bogota — and confirm every
 * date-sensitive write lands on the user's LOCAL day, not the server's.
 */
class TimezoneRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Wednesday 2026-09-16, 19:30 in Bogota == Thursday 2026-09-17, 00:30 UTC. */
    private function freezeAtBogotaWednesdayEvening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', 'UTC'));
    }

    public function test_logging_an_activity_without_an_explicit_date_uses_the_users_local_today(): void
    {
        $this->freezeAtBogotaWednesdayEvening();
        $user = User::factory()->create(['timezone' => 'America/Bogota']);

        $log = app(ActivityService::class)->log($user, ['type' => 'steps', 'metadata' => ['steps' => 6000]]);

        $this->assertSame('2026-09-16', $log->logged_date->toDateString());
    }

    public function test_creating_a_workout_plan_without_an_explicit_date_uses_the_users_local_today(): void
    {
        $this->freezeAtBogotaWednesdayEvening();
        $user = User::factory()->create(['timezone' => 'America/Bogota']);

        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'activity_type' => 'strength',
            'title' => 'Evening Session',
        ]);

        $this->assertSame('2026-09-16', $plan->planned_date->toDateString());
    }

    /**
     * The exact reported symptom: an activity logged "today" (Wednesday, the
     * user's local day) must never land on the same date as a Thursday
     * soccer session that's actually one full day in the future for the
     * user, even though the server's own UTC clock already reads Thursday.
     */
    public function test_todays_activity_is_never_conflated_with_tomorrows_scheduled_session(): void
    {
        $this->freezeAtBogotaWednesdayEvening();
        $user = User::factory()->create(['timezone' => 'America/Bogota']);

        TrainingSchedule::create([
            'user_id' => $user->id,
            'activity_type' => 'Soccer',
            'day_of_week' => Carbon::parse('2026-09-17')->dayOfWeek, // Thursday
            'start_time' => '18:00',
            'expected_duration_minutes' => 60,
        ]);

        $log = app(ActivityService::class)->log($user, ['type' => 'treadmill', 'duration_minutes' => 30]);

        $this->assertSame('2026-09-16', $log->logged_date->toDateString(), 'Activity must be dated the user\'s local Wednesday.');
        $this->assertNotSame('2026-09-17', $log->logged_date->toDateString(), 'Activity must NOT be dated Thursday, the soccer day.');
    }

    /**
     * A Wednesday-evening freeze doesn't cross a week boundary (Sept 14-20
     * either way), so it can't distinguish correct from buggy behavior here.
     * This freezes right at a week boundary instead: Sunday 2026-09-13,
     * 23:30 in Bogota is already Monday 2026-09-14, 04:30 in UTC — the
     * server's own clock would compute the NEXT week if this weren't
     * timezone-aware.
     */
    public function test_week_range_is_computed_from_the_users_local_now_not_server_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 04:30:00', 'UTC'));
        $user = User::factory()->create(['timezone' => 'America/Bogota']);

        $weekRange = app(WeeklyPlanService::class)->weekRange($user);

        $this->assertSame('2026-09-07', $weekRange['start']);
        $this->assertSame('2026-09-13', $weekRange['end']);
    }

    public function test_a_user_with_no_stored_timezone_still_falls_back_to_utc_unchanged(): void
    {
        $this->freezeAtBogotaWednesdayEvening();
        $user = User::factory()->create(['timezone' => null]);

        $log = app(ActivityService::class)->log($user, ['type' => 'steps', 'metadata' => ['steps' => 1000]]);

        // Unchanged prior behavior for a user we don't know the timezone of.
        $this->assertSame('2026-09-17', $log->logged_date->toDateString());
    }
}
