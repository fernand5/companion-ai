<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The four date concepts must stay independent:
 *   ACTUAL ACTIVITY (logged_date) | PLAN (planned_date) | SCHEDULE | ADAPTATION.
 *
 * Fixture: user in America/Bogota, Wednesday 2026-09-16, 19:30 local — which
 * is already Thursday 2026-09-17, 00:30 in UTC. Every assertion below is made
 * at that boundary, the one moment a UTC-based "today" gives the wrong day.
 */
class ActivityPlanDateSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bogotaUser(): User
    {
        return User::factory()->create(['timezone' => 'America/Bogota']);
    }

    private function activityDates(User $user): array
    {
        return ActivityLog::where('user_id', $user->id)
            ->orderBy('id')->get()
            ->map(fn (ActivityLog $log) => $log->logged_date->toDateString())
            ->all();
    }

    public function test_the_utc_boundary_resolves_to_the_users_local_wednesday(): void
    {
        $user = $this->bogotaUser();

        $this->assertSame('2026-09-16', $user->localToday());
        $this->assertSame('Wednesday', $user->localNow()->format('l'));
        $this->assertSame('2026-09-17', Carbon::now('UTC')->toDateString());
    }

    public function test_today_over_http_logs_on_the_local_date(): void
    {
        $user = $this->bogotaUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/activity', ['type' => 'sport', 'metadata' => ['sport' => 'Soccer']])
            ->assertCreated();

        $this->assertSame(['2026-09-16'], $this->activityDates($user));
    }

    public function test_yesterday_and_a_named_past_weekday_are_logged_on_their_own_dates(): void
    {
        $user = $this->bogotaUser();
        $service = app(ActivityService::class);

        // "I played soccer yesterday" / "...on Tuesday" — both resolve to Tue 2026-09-15.
        $service->log($user, ['type' => 'sport', 'logged_date' => '2026-09-15']);
        // "...on Monday"
        $service->log($user, ['type' => 'sport', 'logged_date' => '2026-09-14']);

        $this->assertSame(['2026-09-15', '2026-09-14'], $this->activityDates($user));
    }

    public function test_a_future_dated_activity_is_rejected_because_it_has_not_happened_yet(): void
    {
        $user = $this->bogotaUser();

        try {
            // "I have soccer tomorrow" must never become a completed activity.
            app(ActivityService::class)->log($user, ['type' => 'sport', 'logged_date' => '2026-09-17']);
            $this->fail('A future-dated activity must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('logged_date', $e->errors());
        }

        $this->assertSame([], $this->activityDates($user));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/activity', ['type' => 'sport', 'logged_date' => '2026-09-17'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logged_date');
    }

    public function test_an_upcoming_session_is_a_plan_not_an_activity(): void
    {
        $user = $this->bogotaUser();

        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => '2026-09-17',
            'activity_type' => 'sport',
            'title' => 'Soccer',
        ]);

        $this->assertSame('2026-09-17', $plan->planned_date->toDateString());
        $this->assertSame([], $this->activityDates($user));
    }

    public function test_wednesday_activity_and_thursday_plan_stay_separate(): void
    {
        $user = $this->bogotaUser();
        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => '2026-09-17',
            'activity_type' => 'sport',
            'title' => 'Soccer',
        ]);

        app(ActivityService::class)->log($user, ['type' => 'treadmill', 'duration_minutes' => 13]);

        $this->assertSame(['2026-09-16'], $this->activityDates($user));
        $plan = WorkoutPlan::where('user_id', $user->id)->sole();
        $this->assertSame('2026-09-17', $plan->planned_date->toDateString());
        $this->assertSame(WorkoutPlan::STATUS_PLANNED, $plan->status);
    }

    public function test_completing_a_future_plan_early_logs_the_activity_today_not_on_the_plan_date(): void
    {
        $user = $this->bogotaUser();
        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => '2026-09-17',
            'activity_type' => 'strength',
            'title' => 'Thursday Strength',
            'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 5]],
        ]);

        app(WorkoutPlanService::class)->updateExerciseStatus($user, $plan->exercises->first(), ['status' => 'completed']);

        $this->assertSame(['2026-09-16'], $this->activityDates($user), 'Done on Wednesday, so it happened on Wednesday.');
        $this->assertSame('2026-09-17', $plan->fresh()->planned_date->toDateString(), 'The plan keeps its own date.');
    }

    public function test_completing_todays_or_a_past_plan_still_logs_on_the_plan_date(): void
    {
        $user = $this->bogotaUser();
        $past = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => '2026-09-14',
            'activity_type' => 'strength',
            'title' => 'Monday Strength',
            'exercises' => [['exercise_name' => 'Row', 'planned_sets' => 3, 'planned_reps' => 8]],
        ]);

        app(WorkoutPlanService::class)->updateExerciseStatus($user, $past->exercises->first(), ['status' => 'completed']);

        $this->assertSame(['2026-09-14'], $this->activityDates($user), 'Backfilling a past plan keeps its date.');
    }
}
