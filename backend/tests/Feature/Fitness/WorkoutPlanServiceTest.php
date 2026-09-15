<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pending_exercises_yield_planned_status(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

        $updated = app(WorkoutPlanService::class)->updateExerciseStatus($user, $exercise, ['status' => 'pending']);

        $this->assertSame(WorkoutPlan::STATUS_PLANNED, $updated->status);
    }

    public function test_all_completed_exercises_yield_completed_status_and_sync_actual(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $a = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 0]);
        $b = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 1]);

        $service = app(WorkoutPlanService::class);
        $service->updateExerciseStatus($user, $a, ['status' => 'completed']);
        $updated = $service->updateExerciseStatus($user, $b, ['status' => 'completed']);

        $this->assertSame(WorkoutPlan::STATUS_COMPLETED, $updated->status);
        $this->assertNotNull($updated->workout_session_id);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'type' => 'strength',
            'workout_session_id' => $updated->workout_session_id,
        ]);
    }

    public function test_all_skipped_exercises_yield_skipped_status_and_do_not_create_a_session(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

        $updated = app(WorkoutPlanService::class)->updateExerciseStatus($user, $exercise, ['status' => 'skipped']);

        $this->assertSame(WorkoutPlan::STATUS_SKIPPED, $updated->status);
        $this->assertNull($updated->workout_session_id);
        $this->assertDatabaseMissing('workout_sessions', ['user_id' => $user->id]);
    }

    public function test_some_pending_yields_in_progress_status(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $a = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 0]);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 1]);

        $updated = app(WorkoutPlanService::class)->updateExerciseStatus($user, $a, ['status' => 'completed']);

        $this->assertSame(WorkoutPlan::STATUS_IN_PROGRESS, $updated->status);
    }

    public function test_mixed_completed_and_skipped_with_no_pending_yields_partial_status(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $a = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 0]);
        $b = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 1]);

        $service = app(WorkoutPlanService::class);
        $service->updateExerciseStatus($user, $a, ['status' => 'completed']);
        $updated = $service->updateExerciseStatus($user, $b, ['status' => 'skipped']);

        $this->assertSame(WorkoutPlan::STATUS_PARTIAL, $updated->status);
        // Partial still syncs an actual session (something real happened).
        $this->assertNotNull($updated->workout_session_id);
    }

    public function test_create_or_replace_upserts_on_date_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $service = app(WorkoutPlanService::class);

        $first = $service->createOrReplace($user, [
            'planned_date' => '2026-09-15',
            'activity_type' => 'strength',
            'title' => 'Original Plan',
            'exercises' => [['exercise_name' => 'Squat']],
        ]);

        $second = $service->createOrReplace($user, [
            'planned_date' => '2026-09-15',
            'activity_type' => 'strength',
            'title' => 'Replaced Plan',
            'exercises' => [['exercise_name' => 'Bench Press'], ['exercise_name' => 'Row']],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkoutPlan::where('user_id', $user->id)->count());
        $this->assertSame('Replaced Plan', $second->title);
        $this->assertCount(2, $second->exercises);
        $this->assertSame(WorkoutPlan::STATUS_PLANNED, $second->status);
    }

    public function test_find_exercise_by_name_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => '2026-09-15']);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => 'Bench Press']);

        $found = app(WorkoutPlanService::class)->findExerciseByName($user, '2026-09-15', 'bench press');

        $this->assertNotNull($found);
        $this->assertSame('Bench Press', $found->exercise_name);
    }

    public function test_a_plan_with_no_exercises_keeps_its_status_as_set(): void
    {
        $user = User::factory()->create();
        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => '2026-09-15',
            'activity_type' => 'sport',
            'title' => 'Football',
        ]);

        $this->assertSame(WorkoutPlan::STATUS_PLANNED, $plan->status);
        $this->assertCount(0, $plan->exercises);
    }
}
