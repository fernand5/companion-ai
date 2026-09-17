<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\FitnessProfile;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI is never trusted with authorization: every tool must scope its
 * query/write to the authenticated $user, ignoring anything in $arguments.
 */
class ToolAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_profile_only_returns_the_authenticated_users_profile(): void
    {
        $owner = User::factory()->has(FitnessProfile::factory()->state(['primary_goal' => 'Owner goal']), 'fitnessProfile')->create();
        $other = User::factory()->has(FitnessProfile::factory()->state(['primary_goal' => 'Other goal']), 'fitnessProfile')->create();

        $result = app(ToolRegistry::class)->call('get_user_profile', [], $other);

        $this->assertSame('Other goal', $result['primary_goal']);
        $this->assertNotSame('Owner goal', $result['primary_goal']);
    }

    public function test_get_today_activity_does_not_leak_another_users_logs(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        ActivityLog::factory()->for($owner)->create(['logged_date' => now()->toDateString(), 'notes' => 'owner secret']);

        $result = app(ToolRegistry::class)->call('get_today_activity', [], $intruder);

        $this->assertEmpty($result);
    }

    public function test_get_upcoming_schedule_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        TrainingSchedule::factory()->for($owner)->create(['day_of_week' => now()->dayOfWeek, 'activity_type' => 'Football']);

        $result = app(ToolRegistry::class)->call('get_upcoming_schedule', [], $intruder);

        $this->assertEmpty($result);
    }

    public function test_log_activity_writes_to_the_authenticated_user_even_if_arguments_claim_otherwise(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        app(ToolRegistry::class)->call('log_activity', [
            'type' => 'steps',
            'metadata' => ['steps' => 5000],
            'user_id' => $otherUser->id, // attempted spoof — must be ignored
        ], $user);

        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'steps']);
        $this->assertDatabaseMissing('activity_logs', ['user_id' => $otherUser->id]);
    }

    public function test_update_weight_only_updates_the_authenticated_users_profile(): void
    {
        $owner = User::factory()->has(FitnessProfile::factory()->state(['weight_kg' => 90]), 'fitnessProfile')->create();
        $intruder = User::factory()->has(FitnessProfile::factory()->state(['weight_kg' => 70]), 'fitnessProfile')->create();

        app(ToolRegistry::class)->call('update_weight', ['weight_kg' => 60], $intruder);

        $this->assertEquals(60, (float) $intruder->fresh()->fitnessProfile->weight_kg);
        $this->assertEquals(90, (float) $owner->fresh()->fitnessProfile->weight_kg);
    }

    public function test_get_todays_plan_does_not_leak_another_users_plan(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        app(WorkoutPlanService::class)->createOrReplace($owner, [
            'activity_type' => 'strength',
            'title' => 'Owner Secret Plan',
            'reasoning_factors' => ['x'],
        ]);

        $result = app(ToolRegistry::class)->call('get_todays_plan', [], $intruder);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_create_workout_plan_only_creates_a_plan_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        app(ToolRegistry::class)->call('create_workout_plan', [
            'activity_type' => 'strength',
            'title' => 'My Plan',
            'decision_summary' => 'reason',
            'reasoning_factors' => ['x'],
        ], $user);

        $this->assertDatabaseHas('workout_plans', ['user_id' => $user->id, 'title' => 'My Plan']);
        $this->assertDatabaseMissing('workout_plans', ['user_id' => $otherUser->id]);
    }

    public function test_update_fitness_profile_only_updates_the_authenticated_users_profile(): void
    {
        $owner = User::factory()->has(FitnessProfile::factory()->state(['primary_goal' => 'Owner goal']), 'fitnessProfile')->create();
        $intruder = User::factory()->create();

        app(ToolRegistry::class)->call('update_fitness_profile', ['primary_goal' => 'Hacked goal'], $intruder);

        $this->assertSame('Owner goal', $owner->fresh()->fitnessProfile->primary_goal);
        $this->assertSame('Hacked goal', $intruder->fresh()->fitnessProfile->primary_goal);
    }

    public function test_add_training_schedule_entry_does_not_leak_into_another_users_schedule(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        app(ToolRegistry::class)->call('add_training_schedule_entry', ['activity_type' => 'Soccer', 'day_of_week' => 'Tuesday'], $intruder);

        $this->assertDatabaseMissing('training_schedules', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('training_schedules', ['user_id' => $intruder->id, 'activity_type' => 'Soccer']);
    }

    public function test_update_exercise_status_cannot_touch_another_users_exercise(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $plan = app(WorkoutPlanService::class)->createOrReplace($owner, [
            'activity_type' => 'strength',
            'title' => 'Owner Plan',
            'exercises' => [['exercise_name' => 'Bench Press']],
        ]);

        $result = app(ToolRegistry::class)->call('update_exercise_status', [
            'exercise_name' => 'Bench Press',
            'status' => 'completed',
        ], $intruder);

        $this->assertArrayHasKey('error', $result);
        $this->assertDatabaseHas('workout_plan_exercises', [
            'workout_plan_id' => $plan->id,
            'status' => 'pending',
        ]);
    }

    public function test_get_adherence_summary_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $plan = app(WorkoutPlanService::class)->createOrReplace($owner, [
            'activity_type' => 'strength',
            'title' => 'Owner Plan',
            'exercises' => [['exercise_name' => 'Squat']],
        ]);
        app(WorkoutPlanService::class)->updateExerciseStatus($owner, $plan->exercises->first(), ['status' => 'completed']);

        $result = app(ToolRegistry::class)->call('get_adherence_summary', ['weeks' => 1], $intruder);

        $this->assertSame(0, $result['due_exercise_count']);
    }

    public function test_propose_weekly_plan_changes_only_ever_touches_the_authenticated_users_week(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $monday = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        app(WorkoutPlanService::class)->createOrReplace($owner, [
            'planned_date' => $monday,
            'activity_type' => 'strength',
            'title' => "Owner's Monday",
        ]);

        // There is no user/plan id field in the schema to spoof — this proves
        // scoping-by-construction (the tool only ever writes through the
        // authenticated $user) rather than testing a rejected spoof value.
        app(ToolRegistry::class)->call('propose_weekly_plan_changes', [
            'decision_summary' => 'Change Monday.',
            'reasoning_factors' => ['x'],
            'changes' => [
                ['date' => $monday, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Recovery', 'reason' => 'x'],
            ],
        ], $intruder);

        $workoutPlanService = app(WorkoutPlanService::class);
        $this->assertSame("Owner's Monday", $workoutPlanService->forDate($owner, $monday)?->title);
        $this->assertDatabaseMissing('workout_plans', ['user_id' => $owner->id, 'title' => 'Recovery']);
        $this->assertSame('Recovery', $workoutPlanService->forDate($intruder, $monday)?->title);
    }

    public function test_unknown_tool_name_returns_an_error_instead_of_crashing(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('delete_all_users', [], $user);

        $this->assertArrayHasKey('error', $result);
    }
}
