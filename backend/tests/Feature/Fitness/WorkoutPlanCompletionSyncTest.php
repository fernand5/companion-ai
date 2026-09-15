<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutPlanCompletionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_a_plan_creates_exactly_one_session_and_mirrored_activity_log(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => 'Squat',
            'planned_sets' => 3,
            'planned_reps' => 10,
        ]);

        app(WorkoutPlanService::class)->updateExerciseStatus($user, $exercise, [
            'status' => 'completed',
            'actual_sets' => 3,
            'actual_reps' => 8,
        ]);

        $this->assertSame(1, WorkoutSession::where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::where('user_id', $user->id)->where('type', 'strength')->count());

        $session = WorkoutSession::where('user_id', $user->id)->first();
        $this->assertSame(8, $session->exercises->first()->reps);
    }

    public function test_repeated_edits_update_the_same_session_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $a = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 0]);
        $b = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 1]);

        $service = app(WorkoutPlanService::class);

        // First transition: mark A completed, B still pending -> in_progress, no sync yet.
        $service->updateExerciseStatus($user, $a, ['status' => 'completed']);
        $this->assertSame(0, WorkoutSession::where('user_id', $user->id)->count());

        // Second transition: mark B completed too -> plan completes, first sync happens.
        $service->updateExerciseStatus($user, $b, ['status' => 'completed']);
        $this->assertSame(1, WorkoutSession::where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::where('user_id', $user->id)->where('type', 'strength')->count());
        $sessionId = WorkoutSession::where('user_id', $user->id)->value('id');

        // Third transition: revise B to partial -> plan recomputes to partial, sync
        // must update the SAME session, not create a second one.
        $service->updateExerciseStatus($user, $b, ['status' => 'partial', 'actual_reps' => 5]);

        $this->assertSame(1, WorkoutSession::where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::where('user_id', $user->id)->where('type', 'strength')->count());
        $this->assertSame($sessionId, WorkoutSession::where('user_id', $user->id)->value('id'));
    }

    public function test_a_skipped_plan_never_creates_a_session(): void
    {
        $user = User::factory()->create();
        $plan = WorkoutPlan::factory()->for($user)->create();
        $a = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 0]);
        $b = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['position' => 1]);

        $service = app(WorkoutPlanService::class);
        $service->updateExerciseStatus($user, $a, ['status' => 'skipped']);
        $service->updateExerciseStatus($user, $b, ['status' => 'skipped']);

        $this->assertSame(0, WorkoutSession::where('user_id', $user->id)->count());
        $this->assertSame(0, ActivityLog::where('user_id', $user->id)->where('type', 'strength')->count());
    }
}
