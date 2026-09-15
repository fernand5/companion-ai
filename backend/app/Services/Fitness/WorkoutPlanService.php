<?php

namespace App\Services\Fitness;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The authoritative "what the coach intended" layer. A WorkoutPlan (with its
 * WorkoutPlanExercises) is a separate, structured entity from freeform
 * workout_sessions logging — it exists so completion can be tracked per
 * exercise and adherence can be computed, without ever making the AI's chat
 * text the source of truth for structured data.
 */
class WorkoutPlanService
{
    public function __construct(private readonly WorkoutService $workoutService) {}

    public function forDate(User $user, string $date): ?WorkoutPlan
    {
        return $user->workoutPlans()
            ->with('exercises')
            ->whereDate('planned_date', $date)
            ->first();
    }

    /**
     * @return Collection<int, WorkoutPlan>
     */
    public function forRange(User $user, string $start, string $end): Collection
    {
        return $user->workoutPlans()
            ->with('exercises')
            ->whereDate('planned_date', '>=', $start)
            ->whereDate('planned_date', '<=', $end)
            ->orderBy('planned_date')
            ->get();
    }

    /**
     * Create today's (or a given date's) plan, or wholesale-replace it if one
     * already exists for that date (unique on user_id+planned_date) — e.g. the
     * AI regenerating the day's recommendation. Always resets to a fresh
     * "planned" state since the exercises are being replaced.
     */
    public function createOrReplace(User $user, array $data): WorkoutPlan
    {
        $validated = Validator::make($data, [
            'planned_date' => 'nullable|date',
            'activity_type' => 'required|string|in:'.implode(',', array_diff(ActivityService::TYPES, ['weight'])),
            'title' => 'required|string|max:255',
            'source' => 'nullable|string|in:'.implode(',', WorkoutPlan::SOURCES),
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'reasoning' => 'nullable|string|max:4000',
            'reasoning_factors' => 'nullable|array|max:6',
            'reasoning_factors.*' => 'string|max:255',
            'training_schedule_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            'exercises' => 'nullable|array',
            'exercises.*.exercise_name' => 'required_with:exercises|string|max:255',
            'exercises.*.planned_sets' => 'nullable|integer|min:0|max:50',
            'exercises.*.planned_reps' => 'nullable|integer|min:0|max:200',
            'exercises.*.planned_weight_kg' => 'nullable|numeric|min:0|max:500',
            'exercises.*.planned_duration_seconds' => 'nullable|integer|min:0|max:7200',
        ])->validate();

        $plannedDate = $validated['planned_date'] ?? Carbon::today()->toDateString();

        $trainingScheduleId = null;
        if (! empty($validated['training_schedule_id'])) {
            $trainingScheduleId = $user->trainingSchedules()
                ->whereKey($validated['training_schedule_id'])
                ->value('id');
        }

        return DB::transaction(function () use ($user, $validated, $plannedDate, $trainingScheduleId) {
            $attributes = [
                'activity_type' => $validated['activity_type'],
                'title' => $validated['title'],
                'source' => $validated['source'] ?? WorkoutPlan::SOURCE_AI,
                'status' => WorkoutPlan::STATUS_PLANNED,
                'duration_minutes' => $validated['duration_minutes'] ?? null,
                'reasoning' => $validated['reasoning'] ?? null,
                'reasoning_factors' => $validated['reasoning_factors'] ?? null,
                'training_schedule_id' => $trainingScheduleId,
                // Replacing the plan invalidates any previously mirrored session —
                // that history stays in workout_sessions, it just stops being "this
                // plan's" outcome until new exercises are completed.
                'workout_session_id' => null,
                'notes' => $validated['notes'] ?? null,
            ];

            // Look up by whereDate() rather than updateOrCreate()'s raw WHERE match —
            // the `date` cast can serialize with a time component on save, which a
            // bare `where('planned_date', ...)` lookup wouldn't match against.
            $plan = $user->workoutPlans()->whereDate('planned_date', $plannedDate)->first();

            if ($plan) {
                $plan->update($attributes);
            } else {
                $plan = $user->workoutPlans()->create($attributes + ['planned_date' => $plannedDate]);
            }

            $plan->exercises()->delete();

            foreach (($validated['exercises'] ?? []) as $position => $exercise) {
                $plan->exercises()->create([
                    'exercise_name' => $exercise['exercise_name'],
                    'planned_sets' => $exercise['planned_sets'] ?? null,
                    'planned_reps' => $exercise['planned_reps'] ?? null,
                    'planned_weight_kg' => $exercise['planned_weight_kg'] ?? null,
                    'planned_duration_seconds' => $exercise['planned_duration_seconds'] ?? null,
                    'position' => $position,
                    'status' => WorkoutPlanExercise::STATUS_PENDING,
                ]);
            }

            return $plan->load('exercises');
        });
    }

    /**
     * Case-insensitive lookup, scoped to the user — used by the AI tool so the
     * model can reference an exercise by name as the user typed it in chat.
     */
    public function findExerciseByName(User $user, string $date, string $exerciseName): ?WorkoutPlanExercise
    {
        $plan = $this->forDate($user, $date);

        if (! $plan) {
            return null;
        }

        return $plan->exercises->first(
            fn (WorkoutPlanExercise $exercise) => strcasecmp($exercise->exercise_name, $exerciseName) === 0
        );
    }

    public function updateExerciseStatus(User $user, WorkoutPlanExercise $exercise, array $data): WorkoutPlan
    {
        $plan = $this->assertOwnership($user, $exercise);

        $validated = Validator::make($data, [
            'status' => 'required|string|in:'.implode(',', WorkoutPlanExercise::STATUSES),
            'actual_sets' => 'nullable|integer|min:0|max:50',
            'actual_reps' => 'nullable|integer|min:0|max:200',
            'actual_weight_kg' => 'nullable|numeric|min:0|max:500',
            'actual_duration_seconds' => 'nullable|integer|min:0|max:7200',
            'notes' => 'nullable|string|max:1000',
        ])->validate();

        $isTerminal = in_array($validated['status'], [
            WorkoutPlanExercise::STATUS_COMPLETED,
            WorkoutPlanExercise::STATUS_PARTIAL,
        ], true);

        $exercise->update([
            'status' => $validated['status'],
            'actual_sets' => $validated['actual_sets'] ?? $exercise->actual_sets,
            'actual_reps' => $validated['actual_reps'] ?? $exercise->actual_reps,
            'actual_weight_kg' => $validated['actual_weight_kg'] ?? $exercise->actual_weight_kg,
            'actual_duration_seconds' => $validated['actual_duration_seconds'] ?? $exercise->actual_duration_seconds,
            'notes' => $validated['notes'] ?? $exercise->notes,
            'completed_at' => $isTerminal ? now() : null,
        ]);

        $this->recomputeStatus($user, $plan->fresh());

        return $plan->fresh('exercises');
    }

    private function assertOwnership(User $user, WorkoutPlanExercise $exercise): WorkoutPlan
    {
        $plan = $exercise->workoutPlan;

        abort_unless($plan && $plan->user_id === $user->id, 403);

        return $plan;
    }

    /**
     * Derive the plan's overall status from its exercises' statuses, and — if
     * that lands on completed/partial — sync the actual outcome into
     * workout_sessions (see WorkoutService::syncFromPlan). Plans with no
     * exercises (e.g. a sport-type plan) are left untouched; their status is
     * set directly by the caller instead.
     */
    private function recomputeStatus(User $user, WorkoutPlan $plan): void
    {
        $plan->loadMissing('exercises');
        $exercises = $plan->exercises;

        if ($exercises->isEmpty()) {
            return;
        }

        $statuses = $exercises->pluck('status');

        $status = match (true) {
            $statuses->every(fn ($s) => $s === WorkoutPlanExercise::STATUS_PENDING) => WorkoutPlan::STATUS_PLANNED,
            $statuses->every(fn ($s) => $s === WorkoutPlanExercise::STATUS_COMPLETED) => WorkoutPlan::STATUS_COMPLETED,
            $statuses->every(fn ($s) => $s === WorkoutPlanExercise::STATUS_SKIPPED) => WorkoutPlan::STATUS_SKIPPED,
            $statuses->contains(WorkoutPlanExercise::STATUS_PENDING) => WorkoutPlan::STATUS_IN_PROGRESS,
            default => WorkoutPlan::STATUS_PARTIAL,
        };

        $plan->update(['status' => $status]);

        if (in_array($status, [WorkoutPlan::STATUS_COMPLETED, WorkoutPlan::STATUS_PARTIAL], true)) {
            $this->workoutService->syncFromPlan($user, $plan);
        }
    }
}
