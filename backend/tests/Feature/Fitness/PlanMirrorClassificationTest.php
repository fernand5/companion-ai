<?php

namespace Tests\Feature\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\WorkoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Completing a plan puts it on the activity timeline. What it is recorded as
 * must follow the plan's own type: a recovery/mobility session is not a
 * high-intensity strength session (that would distort any load reasoning).
 */
class PlanMirrorClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** Complete a one-exercise plan of the given type through the API. */
    private function completePlan(string $activityType, array $exercise = [], int $daysAgo = 0): WorkoutPlan
    {
        $date = $this->user->localNow()->subDays($daysAgo)->toDateString();
        $plan = WorkoutPlan::factory()->for($this->user)->create(['activity_type' => $activityType, 'planned_date' => $date]);
        $planExercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create($exercise + ['position' => 0]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$planExercise->id}", ['status' => 'completed'])
            ->assertOk();

        return $plan->fresh();
    }

    private function mirror(WorkoutPlan $plan): ActivityLog
    {
        return ActivityLog::where('workout_session_id', $plan->workout_session_id)->sole();
    }

    public function test_a_strength_plan_is_mirrored_as_high_intensity_strength(): void
    {
        $log = $this->mirror($this->completePlan('strength'));

        $this->assertSame('strength', $log->type);
        $this->assertSame('high', $log->intensity);
    }

    public function test_a_recovery_or_mobility_plan_is_not_mirrored_as_high_intensity_strength(): void
    {
        $plan = $this->completePlan('recovery', ['exercise_name' => 'Gentle Mobility', 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 1200]);

        $log = $this->mirror($plan);

        $this->assertSame('recovery', $log->type);
        $this->assertSame('low', $log->intensity);
        $this->assertSame(0, ActivityLog::where('type', 'strength')->count());
    }

    public function test_a_treadmill_plan_is_mirrored_as_treadmill_with_unknown_intensity(): void
    {
        $plan = $this->completePlan('treadmill', ['exercise_name' => 'Treadmill Walk', 'planned_sets' => null, 'planned_reps' => null, 'planned_weight_kg' => null, 'planned_duration_seconds' => 900]);

        $log = $this->mirror($plan);

        $this->assertSame('treadmill', $log->type);
        $this->assertNull($log->intensity, 'The plan does not say how hard it was, so none is invented.');
        $this->assertNull($log->distance_km);
        $this->assertNull($log->duration_minutes);
    }

    public function test_a_sport_plan_keeps_its_own_type_without_an_invented_intensity(): void
    {
        $log = $this->mirror($this->completePlan('sport'));

        $this->assertSame('sport', $log->type);
        $this->assertNull($log->intensity);
    }

    public function test_the_classification_is_kept_when_the_plan_is_completed_again_after_an_edit(): void
    {
        $plan = $this->completePlan('recovery');
        $exercise = $plan->exercises()->first();

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'pending'])->assertOk();
        $this->assertSame(0, ActivityLog::count(), 'Un-completing removes the mirror.');

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}", ['status' => 'completed'])->assertOk();

        $this->assertSame('recovery', $this->mirror($plan->fresh())->type);
        $this->assertSame(1, ActivityLog::count());
    }

    public function test_a_freeform_logged_workout_is_still_strength(): void
    {
        $session = app(WorkoutService::class)->log($this->user, ['exercises' => [['exercise_name' => 'Bench Press', 'sets' => 3, 'reps' => 10, 'weight_kg' => 40]]]);

        $log = ActivityLog::where('workout_session_id', $session->id)->sole();

        $this->assertSame('strength', $log->type);
        $this->assertSame('high', $log->intensity);
    }

    public function test_recovery_sessions_do_not_count_as_strength_workouts_this_week(): void
    {
        $this->completePlan('recovery');

        $this->assertSame(0, app(ActivityService::class)->weeklySummary($this->user)['by_type']['strength'] ?? 0);
        $this->assertSame(1, app(ActivityService::class)->weeklySummary($this->user)['by_type']['recovery'] ?? 0);
    }

    public function test_the_migration_relabels_existing_non_strength_mirrors_and_nothing_else(): void
    {
        // The state older code left behind: every plan mirrored as high strength.
        $recovery = $this->completePlan('recovery', daysAgo: 1);
        $strength = $this->completePlan('strength', daysAgo: 2);
        $treadmill = $this->completePlan('treadmill', daysAgo: 3);
        $freeform = app(WorkoutService::class)->log($this->user, ['exercises' => [['exercise_name' => 'Squat', 'sets' => 3, 'reps' => 5, 'weight_kg' => 60]]]);

        ActivityLog::query()->update(['type' => 'strength', 'intensity' => 'high', 'duration_minutes' => 33, 'notes' => 'keep me']);

        $migration = require database_path('migrations/2026_10_05_000005_reclassify_plan_mirror_activity_logs.php');
        $migration->up();

        $this->assertSame(['recovery', 'low'], [$this->mirror($recovery)->type, $this->mirror($recovery)->intensity]);
        $this->assertSame(['treadmill', null], [$this->mirror($treadmill)->type, $this->mirror($treadmill)->intensity]);
        $this->assertSame(['strength', 'high'], [$this->mirror($strength)->type, $this->mirror($strength)->intensity]);
        $this->assertSame('strength', ActivityLog::where('workout_session_id', $freeform->id)->sole()->type);

        $this->assertSame(['keep me'], ActivityLog::pluck('notes')->unique()->values()->all(), 'Nothing else on the logs changes.');
        $this->assertSame([33], ActivityLog::pluck('duration_minutes')->unique()->values()->all());

        $before = ActivityLog::orderBy('id')->get(['id', 'type', 'intensity'])->toArray();
        $migration->up();
        $this->assertSame($before, ActivityLog::orderBy('id')->get(['id', 'type', 'intensity'])->toArray(), 'Idempotent.');
    }

    public function test_the_migration_leaves_exercises_sets_and_distances_alone(): void
    {
        $plan = $this->completePlan('recovery');
        ActivityLog::query()->update(['type' => 'strength', 'intensity' => 'high']);

        $counts = fn () => [DB::table('workout_exercises')->count(), DB::table('workout_sets')->count(), DB::table('workout_sessions')->count()];
        $before = $counts();

        (require database_path('migrations/2026_10_05_000005_reclassify_plan_mirror_activity_logs.php'))->up();

        $this->assertSame($before, $counts());
        $this->assertSame('recovery', $this->mirror($plan)->type);
    }
}
