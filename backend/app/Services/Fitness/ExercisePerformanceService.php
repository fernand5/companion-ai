<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Facades\DB;

/**
 * Writes what the user ACTUALLY did for a planned exercise, kept strictly apart
 * from the plan: a workout_exercises row (with a snapshot of the target at the
 * moment of logging) plus one workout_sets row per set. The plan is never
 * rewritten by this, and replacing a plan can no longer touch this history.
 *
 * Replaces the old plan->session sync, which copied every plan exercise
 * (including skipped and pending ones) and substituted the plan's targets for
 * anything the user had not entered, so planned numbers were recorded as if
 * performed.
 */
class ExercisePerformanceService
{
    public function __construct(private readonly WorkoutService $workoutService) {}

    /**
     * Record (or update in place) the performance for one planned exercise.
     *
     * @param  array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>  $sets
     */
    public function store(User $user, WorkoutPlan $plan, WorkoutPlanExercise $planExercise, array $sets, string $recordedAs): WorkoutExercise
    {
        return DB::transaction(function () use ($user, $plan, $planExercise, $sets, $recordedAs) {
            $session = $this->sessionFor($user, $plan);

            $exercise = WorkoutExercise::firstOrNew([
                'workout_session_id' => $session->id,
                'workout_plan_exercise_id' => $planExercise->id,
            ]);

            // The target is snapshotted once, when the performance is first logged.
            if (! $exercise->exists) {
                $exercise->fill([
                    'planned_sets' => $planExercise->planned_sets,
                    'planned_reps' => $planExercise->planned_reps,
                    'planned_weight_kg' => $planExercise->planned_weight_kg,
                    'planned_duration_seconds' => $planExercise->planned_duration_seconds,
                ]);
            }

            $summary = PerformanceSets::summarize($sets);

            $exercise->fill([
                'exercise_name' => $planExercise->exercise_name,
                'position' => $planExercise->position,
                'recorded_as' => $recordedAs,
                'sets' => $summary['sets'],
                'reps' => $summary['reps'],
                'weight_kg' => $summary['weight_kg'],
                'duration_seconds' => $summary['duration_seconds'],
                'notes' => $planExercise->notes,
            ])->save();

            $this->replaceSets($exercise, $sets);

            return $exercise->load('performedSets');
        });
    }

    /** The user took back a completion: remove that exercise's recorded performance. */
    public function retract(WorkoutPlan $plan, WorkoutPlanExercise $planExercise): void
    {
        if (! $plan->workout_session_id) {
            return;
        }

        DB::transaction(function () use ($plan, $planExercise) {
            WorkoutExercise::where('workout_session_id', $plan->workout_session_id)
                ->where('workout_plan_exercise_id', $planExercise->id)
                ->get()
                ->each->delete();

            $this->dropSessionIfEmpty($plan);
        });
    }

    /**
     * Keep the mirrored activity_logs row in step with the plan: it exists while
     * the plan is completed or partial (the same rule as before) and is removed
     * when the plan is no longer either, instead of going stale.
     */
    public function syncMirror(User $user, WorkoutPlan $plan): void
    {
        $plan = $plan->fresh();

        if (! $plan || ! $plan->workout_session_id) {
            return;
        }

        $session = WorkoutSession::find($plan->workout_session_id);

        if (! $session) {
            return;
        }

        if (in_array($plan->status, [WorkoutPlan::STATUS_COMPLETED, WorkoutPlan::STATUS_PARTIAL], true)) {
            $this->workoutService->ensureMirrorLog($user, $session);

            return;
        }

        ActivityLog::where('workout_session_id', $session->id)->delete();
    }

    private function sessionFor(User $user, WorkoutPlan $plan): WorkoutSession
    {
        $session = $plan->workout_session_id ? WorkoutSession::find($plan->workout_session_id) : null;

        if ($session) {
            return $session;
        }

        // The activity happened when the user actually did it — never later than
        // today, so completing a future plan early does not push it onto the
        // plan's date. The duration is unknown (the plan's duration is a target),
        // so it is left empty rather than recorded as if measured.
        $session = $user->workoutSessions()->create([
            'logged_date' => min($plan->planned_date->toDateString(), $user->localToday()),
            'duration_minutes' => null,
            'notes' => $plan->title,
        ]);

        $plan->update(['workout_session_id' => $session->id]);

        return $session;
    }

    /**
     * Update the sets in place (stable ids) rather than delete-and-recreate.
     *
     * @param  array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>  $sets
     */
    private function replaceSets(WorkoutExercise $exercise, array $sets): void
    {
        $existing = $exercise->performedSets()->get()->keyBy('set_number');

        foreach (array_values($sets) as $i => $set) {
            $number = $i + 1;
            $attributes = [
                'reps' => $set['reps'],
                'weight_kg' => $set['weight_kg'],
                'duration_seconds' => $set['duration_seconds'],
                'completed' => $set['completed'],
            ];

            if ($row = $existing->get($number)) {
                $row->update($attributes);
            } else {
                $exercise->performedSets()->create($attributes + ['set_number' => $number]);
            }
        }

        $exercise->performedSets()->where('set_number', '>', count($sets))->delete();
    }

    private function dropSessionIfEmpty(WorkoutPlan $plan): void
    {
        $session = WorkoutSession::find($plan->workout_session_id);

        if (! $session || $session->exercises()->exists()) {
            return;
        }

        ActivityLog::where('workout_session_id', $session->id)->delete();
        $session->delete();
        $plan->update(['workout_session_id' => null]);
    }
}
