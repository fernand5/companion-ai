<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WeekOverviewService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/weekly-plan/overview: the current Monday-Sunday week as persisted. The week and "today" are the
 * user's own; a plan, what was recorded against it, and workouts logged without a plan stay distinct; and a
 * day with nothing is never called a rest day.
 *
 * Fixed clock: Wednesday 2026-10-28 15:00 UTC (10:00 in Bogota), so the week is 2026-10-26 .. 2026-11-01.
 */
class WeekOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-26';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function user(string $timezone = 'America/Bogota'): User
    {
        return User::factory()->create(['timezone' => $timezone]);
    }

    private function date(int $offset): string
    {
        return Carbon::parse(self::MONDAY)->addDays($offset)->toDateString();
    }

    private function plan(User $user, string $date, array $attributes = [], array $exercises = []): WorkoutPlan
    {
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => $date] + $attributes);

        foreach ($exercises as $i => $exercise) {
            WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create($exercise + ['position' => $i]);
        }

        return $plan->load('exercises');
    }

    private function overview(User $user): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/weekly-plan/overview')->assertOk()->json();
    }

    private function day(array $overview, int $offset): array
    {
        return $overview['days'][$offset];
    }

    private function bench(int $sets = 3, int $reps = 10, ?float $kg = 40): array
    {
        return ['exercise_name' => 'Bench Press', 'planned_sets' => $sets, 'planned_reps' => $reps, 'planned_weight_kg' => $kg];
    }

    // ---- the week --------------------------------------------------------------------------------

    public function test_it_returns_seven_days_monday_to_sunday_with_the_users_today(): void
    {
        $overview = $this->overview($this->user());

        $this->assertSame(['start' => self::MONDAY, 'end' => '2026-11-01', 'today' => '2026-10-28', 'timezone' => 'America/Bogota'], $overview['week']);
        $this->assertSame(
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            array_column($overview['days'], 'weekday'),
        );
        $this->assertSame(array_map(fn ($i) => $this->date($i), range(0, 6)), array_column($overview['days'], 'date'));
        $this->assertSame([false, false, true, false, false, false, false], array_column($overview['days'], 'is_today'));
        $this->assertSame([true, true, false, false, false, false, false], array_column($overview['days'], 'is_past'));
    }

    public function test_a_complete_seven_day_plan_comes_back_in_order_with_its_exercises(): void
    {
        $user = $this->user();

        foreach (range(0, 6) as $i) {
            $this->plan($user, $this->date($i), ['title' => "Day {$i}"], [$this->bench()]);
        }

        $overview = $this->overview($user);

        $this->assertSame(array_map(fn ($i) => "Day {$i}", range(0, 6)), array_map(fn ($d) => $d['plan']['title'], $overview['days']));
        foreach ($overview['days'] as $day) {
            $this->assertSame('Bench Press', $day['plan']['exercises'][0]['exercise_name']);
            $this->assertSame(3, $day['plan']['exercises'][0]['planned_sets']);
            $this->assertEquals(40.0, $day['plan']['exercises'][0]['planned_weight_kg']);
        }
    }

    public function test_a_week_with_only_one_or_two_workouts_leaves_the_other_days_empty_not_resting(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(1), ['title' => 'Upper']);
        $this->plan($user, $this->date(4), ['title' => 'Lower']);

        $overview = $this->overview($user);

        $states = array_column($overview['days'], 'state');
        // Mon nothing, Tue (past) never marked done, Wed (today) nothing, Thu nothing, Fri planned, Sat/Sun nothing.
        $this->assertSame(['nothing', 'not_marked_done', 'nothing', 'nothing', 'planned', 'nothing', 'nothing'], $states);
        $this->assertNotContains('rest', $states, 'A day with no plan is not a rest day.');
        $this->assertNull($this->day($overview, 0)['plan']);
        $this->assertSame([], $this->day($overview, 0)['activities']);
    }

    public function test_a_past_day_with_no_plan_and_nothing_logged_is_nothing_not_rest_and_not_missed(): void
    {
        $overview = $this->overview($this->user());

        $monday = $this->day($overview, 0);
        $this->assertTrue($monday['is_past']);
        $this->assertSame('nothing', $monday['state']);
        $this->assertNull($monday['plan']);
    }

    public function test_an_empty_week_is_all_nothing(): void
    {
        $overview = $this->overview($this->user());

        $this->assertSame(array_fill(0, 7, 'nothing'), array_column($overview['days'], 'state'));
    }

    // ---- planned versus completed versus recorded ---------------------------------------------------------------

    public function test_each_plan_status_maps_to_its_own_state(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(0), ['status' => 'completed']);
        $this->plan($user, $this->date(1), ['status' => 'partial']);
        $this->plan($user, $this->date(2), ['status' => 'in_progress']);
        $this->plan($user, $this->date(3), ['status' => 'planned']);
        $this->plan($user, $this->date(4), ['status' => 'skipped', 'title' => 'Legs']);

        $states = array_column($this->overview($user)['days'], 'state');

        $this->assertSame(['completed', 'partial', 'in_progress', 'planned', 'skipped', 'nothing', 'nothing'], $states);
    }

    public function test_a_past_plan_that_is_only_partly_recorded_reads_as_partial_and_a_current_one_as_in_progress(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(0), ['status' => 'in_progress']);
        $this->plan($user, $this->date(2), ['status' => 'in_progress']);

        $overview = $this->overview($user);

        $this->assertSame('partial', $this->day($overview, 0)['state']);
        $this->assertSame('in_progress', $this->day($overview, 2)['state']);
    }

    public function test_a_past_plan_nobody_marked_done_is_not_marked_done_never_missed(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(0), ['status' => 'planned', 'title' => 'Monday plan']);

        $monday = $this->day($this->overview($user), 0);

        $this->assertSame('not_marked_done', $monday['state']);
        $this->assertSame('planned', $monday['plan']['status'], 'The stored status is untouched: the application does not decide it was missed.');
    }

    public function test_todays_and_future_plans_are_simply_planned(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(2), ['status' => 'planned']);
        $this->plan($user, $this->date(5), ['status' => 'planned']);

        $overview = $this->overview($user);

        $this->assertSame('planned', $this->day($overview, 2)['state']);
        $this->assertSame('planned', $this->day($overview, 5)['state']);
    }

    public function test_a_past_plan_with_recorded_performance_is_not_reported_as_unconfirmed(): void
    {
        $user = $this->user();
        $plan = $this->plan($user, $this->date(0), ['status' => 'planned'], [$this->bench()]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}/performance", ['sets' => [
                ['reps' => 10, 'weight_kg' => 40, 'completed' => true], ['reps' => 10, 'weight_kg' => 40, 'completed' => true], ['reps' => 8, 'weight_kg' => 40, 'completed' => true],
            ]])->assertOk();

        $monday = $this->day($this->overview($user), 0);

        $this->assertContains($monday['state'], ['completed', 'partial']);
        $this->assertNotSame('not_marked_done', $monday['state']);
    }

    public function test_recorded_sets_come_back_next_to_the_plan_and_the_plan_targets_are_not_overwritten(): void
    {
        $user = $this->user();
        $plan = $this->plan($user, $this->date(0), [], [$this->bench(3, 10, 40)]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}/performance", ['sets' => [
                ['reps' => 10, 'weight_kg' => 40, 'completed' => true], ['reps' => 9, 'weight_kg' => 40, 'completed' => true], ['reps' => 6, 'weight_kg' => 40, 'completed' => false],
            ]])->assertOk();

        $exercise = $this->day($this->overview($user), 0)['plan']['exercises'][0];

        $this->assertSame([3, 10], [$exercise['planned_sets'], $exercise['planned_reps']]);
        $this->assertEquals(40.0, $exercise['planned_weight_kg']);
        $this->assertSame('entered', $exercise['performance']['recorded_as']);
        $this->assertSame([10, 9, 6], array_column($exercise['performance']['sets'], 'reps'));
        $this->assertSame([true, true, false], array_column($exercise['performance']['sets'], 'completed'));
    }

    public function test_a_one_tap_done_is_flagged_as_not_measured(): void
    {
        $user = $this->user();
        $plan = $this->plan($user, $this->date(0), [], [$this->bench()]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}", ['status' => 'completed'])->assertOk();

        $monday = $this->day($this->overview($user), 0);

        $this->assertSame('completed', $monday['state']);
        $this->assertSame('as_planned', $monday['plan']['exercises'][0]['performance']['recorded_as'], 'Completed as planned is not measured performance.');
    }

    // ---- unplanned activity ------------------------------------------------------------------------------------

    public function test_soccer_without_a_plan_still_appears_on_its_date(): void
    {
        $user = $this->user();
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => $this->date(1), 'duration_minutes' => 90, 'metadata' => ['sport' => 'Soccer']]);

        $tuesday = $this->day($this->overview($user), 1);

        $this->assertSame('activity_only', $tuesday['state']);
        $this->assertNull($tuesday['plan']);
        $this->assertCount(1, $tuesday['activities']);
        $this->assertSame('sport', $tuesday['activities'][0]['type']);
        $this->assertSame(90, $tuesday['activities'][0]['duration_minutes']);
        $this->assertSame('Soccer', $tuesday['activities'][0]['metadata']['sport']);
    }

    public function test_unplanned_cardio_appears_and_does_not_change_other_days(): void
    {
        $user = $this->user();
        ActivityLog::factory()->for($user)->treadmill()->create(['logged_date' => $this->date(0), 'duration_minutes' => 25]);

        $overview = $this->overview($user);

        $this->assertSame('activity_only', $this->day($overview, 0)['state']);
        $this->assertSame('treadmill', $this->day($overview, 0)['activities'][0]['type']);
        $this->assertSame(array_fill(0, 6, 'nothing'), array_slice(array_column($overview['days'], 'state'), 1));
    }

    public function test_activity_on_a_planned_day_is_listed_beside_the_plan_and_does_not_complete_it(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(1), ['status' => 'planned', 'title' => 'Upper body'], [$this->bench()]);
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => $this->date(1), 'metadata' => ['sport' => 'Soccer']]);

        $tuesday = $this->day($this->overview($user), 1);

        $this->assertSame('not_marked_done', $tuesday['state'], 'Soccer is not the planned upper body session.');
        $this->assertSame('Upper body', $tuesday['plan']['title']);
        $this->assertSame('sport', $tuesday['activities'][0]['type']);
    }

    public function test_the_row_a_completed_plan_mirrors_onto_the_timeline_is_not_shown_as_a_second_activity(): void
    {
        $user = $this->user();
        $plan = $this->plan($user, $this->date(0), [], [$this->bench()]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}", ['status' => 'completed'])->assertOk();

        $this->assertSame(1, ActivityLog::where('user_id', $user->id)->count(), 'Precondition: the plan was mirrored onto the timeline.');

        $monday = $this->day($this->overview($user), 0);

        $this->assertSame('completed', $monday['state']);
        $this->assertSame([], $monday['activities']);
    }

    public function test_a_freeform_logged_workout_comes_back_with_its_recorded_exercises(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum')->postJson('/api/workouts', [
            'logged_date' => $this->date(0), 'duration_minutes' => 40,
            'exercises' => [['exercise_name' => 'Squat', 'set_details' => [['reps' => 8, 'weight_kg' => 60, 'completed' => true], ['reps' => 8, 'weight_kg' => 60, 'completed' => true]]]],
        ])->assertSuccessful();

        $monday = $this->day($this->overview($user), 0);

        $this->assertSame('activity_only', $monday['state']);
        $this->assertSame('Squat', $monday['activities'][0]['session']['exercises'][0]['exercise_name']);
        $this->assertSame([8, 8], array_column($monday['activities'][0]['session']['exercises'][0]['performed_sets'], 'reps'));
    }

    public function test_steps_and_weigh_ins_are_not_workouts(): void
    {
        $user = $this->user();
        ActivityLog::factory()->for($user)->create(['type' => 'steps', 'logged_date' => $this->date(0), 'metadata' => ['steps' => 9000]]);
        ActivityLog::factory()->for($user)->create(['type' => 'weight', 'logged_date' => $this->date(0), 'metadata' => ['weight_kg' => 80]]);

        $monday = $this->day($this->overview($user), 0);

        $this->assertSame('nothing', $monday['state']);
        $this->assertSame([], $monday['activities']);
    }

    // ---- rest days -----------------------------------------------------------------------------------------------

    public function test_a_day_the_plan_cleared_for_rest_is_a_rest_day(): void
    {
        $user = $this->user();
        app(WeeklyPlanService::class)->applyChanges($user, [['date' => $this->date(3), 'action' => 'clear', 'reason' => 'Heavy legs yesterday']], ['Recovery']);

        $thursday = $this->day($this->overview($user), 3);

        $this->assertSame('rest', $thursday['state']);
        $this->assertSame('Rest', $thursday['plan']['title']);
    }

    public function test_a_skipped_workout_is_skipped_not_rest(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(3), ['status' => 'skipped', 'activity_type' => 'recovery', 'title' => 'Mobility'], [['exercise_name' => 'Hip opener']]);
        $this->plan($user, $this->date(4), ['status' => 'skipped', 'activity_type' => 'strength', 'title' => 'Rest']);

        $overview = $this->overview($user);

        $this->assertSame('skipped', $this->day($overview, 3)['state']);
        $this->assertSame('skipped', $this->day($overview, 4)['state'], 'Only a recovery day the plan itself cleared is a rest day.');
    }

    // ---- week boundaries and timezones ----------------------------------------------------------------------------

    public function test_sunday_night_in_bogota_is_still_that_week_even_though_utc_is_already_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 03:30:00', 'UTC')); // Sunday 2026-11-01 22:30 in Bogota
        $user = $this->user('America/Bogota');
        $this->plan($user, '2026-11-01', ['title' => 'Sunday long run']);

        $overview = $this->overview($user);

        $this->assertSame(['start' => '2026-10-26', 'end' => '2026-11-01', 'today' => '2026-11-01'], array_intersect_key($overview['week'], array_flip(['start', 'end', 'today'])));
        $this->assertTrue($this->day($overview, 6)['is_today']);
        $this->assertSame('Sunday long run', $this->day($overview, 6)['plan']['title']);
    }

    public function test_the_same_instant_is_already_next_week_in_auckland(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 20:00:00', 'UTC')); // Monday 2026-11-02 09:00 in Auckland
        $bogota = $this->user('America/Bogota');
        $auckland = $this->user('Pacific/Auckland');
        $this->plan($auckland, '2026-11-02', ['title' => 'Auckland Monday']);

        $this->assertSame('2026-10-26', $this->overview($bogota)['week']['start']);

        $nz = $this->overview($auckland);
        $this->assertSame(['2026-11-02', '2026-11-08', '2026-11-02'], [$nz['week']['start'], $nz['week']['end'], $nz['week']['today']]);
        $this->assertTrue($this->day($nz, 0)['is_today']);
        $this->assertSame('Auckland Monday', $this->day($nz, 0)['plan']['title']);
    }

    public function test_the_first_minute_of_monday_starts_a_new_week_and_the_last_of_sunday_does_not(): void
    {
        $user = $this->user('America/Bogota');

        Carbon::setTestNow(Carbon::parse('2026-11-02 04:59:00', 'UTC')); // Sunday 23:59 Bogota
        $this->assertSame('2026-10-26', $this->overview($user)['week']['start']);

        Carbon::setTestNow(Carbon::parse('2026-11-02 05:01:00', 'UTC')); // Monday 00:01 Bogota
        $this->assertSame('2026-11-02', $this->overview($user)['week']['start']);
    }

    public function test_a_user_without_a_timezone_uses_the_application_default(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->assertSame(config('app.timezone'), $this->overview($user)['week']['timezone']);
    }

    public function test_plans_and_activity_just_outside_the_week_are_left_out(): void
    {
        $user = $this->user();
        $this->plan($user, '2026-10-25', ['title' => 'Last Sunday']);
        $this->plan($user, '2026-11-02', ['title' => 'Next Monday']);
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => '2026-10-25']);
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => '2026-11-02']);

        $overview = $this->overview($user);

        $this->assertSame(array_fill(0, 7, 'nothing'), array_column($overview['days'], 'state'));
        $this->assertSame([], array_filter(array_column($overview['days'], 'plan')));
    }

    // ---- isolation, auth, cost ------------------------------------------------------------------------------------

    public function test_another_users_plans_and_activity_never_appear(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->plan($bob, $this->date(1), ['title' => 'Bob only']);
        ActivityLog::factory()->for($bob)->sport()->create(['logged_date' => $this->date(2)]);

        $overview = $this->overview($alice);

        $this->assertSame(array_fill(0, 7, 'nothing'), array_column($overview['days'], 'state'));
        $this->assertStringNotContainsString('Bob only', json_encode($overview));
    }

    public function test_it_requires_a_signed_in_user(): void
    {
        $this->getJson('/api/weekly-plan/overview')->assertUnauthorized();
    }

    public function test_it_changes_nothing(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(0), ['status' => 'planned'], [$this->bench()]);
        ActivityLog::factory()->for($user)->sport()->create(['logged_date' => $this->date(1)]);
        $before = [DB::table('workout_plans')->get()->toJson(), DB::table('workout_plan_exercises')->get()->toJson(), DB::table('activity_logs')->get()->toJson()];

        $this->overview($user);
        $this->overview($user);

        $this->assertSame($before, [DB::table('workout_plans')->get()->toJson(), DB::table('workout_plan_exercises')->get()->toJson(), DB::table('activity_logs')->get()->toJson()]);
    }

    public function test_a_full_week_is_read_in_a_bounded_number_of_queries(): void
    {
        $user = $this->user();
        foreach (range(0, 6) as $i) {
            $this->plan($user, $this->date($i), [], [$this->bench(), ['exercise_name' => 'Row'], ['exercise_name' => 'Curl']]);
            ActivityLog::factory()->for($user)->sport()->create(['logged_date' => $this->date($i)]);
        }

        $this->actingAs($user, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/weekly-plan/overview')->assertOk();
        $queries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(14, $queries, "{$queries} queries for a 7-day week: something is loading per row.");
    }

    public function test_the_service_and_the_endpoint_agree(): void
    {
        $user = $this->user();
        $this->plan($user, $this->date(0), ['status' => 'completed']);

        $service = app(WeekOverviewService::class)->forCurrentWeek($user);

        $this->assertSame(array_column($service['days'], 'state'), array_column($this->overview($user)['days'], 'state'));
        $this->assertSame(app(WorkoutPlanService::class)->forRange($user, self::MONDAY, '2026-11-01')->count(), 1);
    }
}
