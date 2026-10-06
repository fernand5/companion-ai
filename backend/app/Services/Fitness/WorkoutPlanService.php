<?php

namespace App\Services\Fitness;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The authoritative "what the coach intended" layer. A WorkoutPlan (with its
 * WorkoutPlanExercises) is a separate, structured entity from freeform
 * workout_sessions logging — it exists so completion can be tracked per
 * exercise and adherence can be computed, without ever making the AI's chat
 * text the source of truth for structured data.
 */
class WorkoutPlanService
{
    public function __construct(private readonly ExercisePerformanceService $performanceService) {}

    public function forDate(User $user, string $date): ?WorkoutPlan
    {
        return $user->workoutPlans()
            ->with(['exercises.exerciseImage', 'exercises.performance.performedSets'])
            ->whereDate('planned_date', $date)
            ->first();
    }

    /**
     * @return Collection<int, WorkoutPlan>
     */
    public function forRange(User $user, string $start, string $end): Collection
    {
        return $user->workoutPlans()
            ->with(['exercises.exerciseImage', 'exercises.performance.performedSets'])
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

        $plannedDate = $validated['planned_date'] ?? $user->localToday();

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

            // Replacing a plan deletes its exercises. Once any performance has been
            // recorded that would orphan it and double-count the day on the next
            // completion, so a performed day is never replaced.
            if ($plan?->hasRecordedPerformance()) {
                throw ValidationException::withMessages([
                    'planned_date' => 'This day already has recorded workout performance, so its plan can no longer be replaced.',
                ]);
            }

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

            return $plan->load(['exercises.exerciseImage', 'exercises.performance.performedSets']);
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

    /**
     * Change one exercise's status. This is also the one-tap "done" path, so what
     * gets recorded depends on what the caller actually knows:
     *
     *  - numbers supplied (actual_*): recorded as `entered` sets;
     *  - completed with no numbers: `as_planned` — the plan's targets, assumed,
     *    which is weak evidence and never exposed as measured performance;
     *  - partial with no numbers: the exercise is recorded with no sets, since
     *    how much was done is unknown;
     *  - pending/skipped: any recorded performance is retracted.
     *
     * An existing `entered` record is never replaced by an assumption.
     */
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

        DB::transaction(function () use ($user, $plan, $exercise, $validated) {
            $status = $validated['status'];
            $isTerminal = in_array($status, [WorkoutPlanExercise::STATUS_COMPLETED, WorkoutPlanExercise::STATUS_PARTIAL], true);

            $attributes = [
                'status' => $status,
                'notes' => $validated['notes'] ?? $exercise->notes,
                'completed_at' => $isTerminal ? now() : null,
            ];

            if ($isTerminal) {
                $attributes += $this->recordFromStatus($user, $plan, $exercise, $validated);
            } else {
                $this->performanceService->retract($plan, $exercise);

                // The derived summary describes performance that no longer exists.
                $attributes += [
                    'actual_sets' => null,
                    'actual_reps' => null,
                    'actual_weight_kg' => null,
                    'actual_duration_seconds' => null,
                ];
            }

            $exercise->update($attributes);

            $this->recomputeStatus($user, $plan->fresh());
        });

        return $plan->fresh(['exercises.exerciseImage', 'exercises.performance.performedSets']);
    }

    /**
     * Explicit per-set entry: what the user really did, set by set.
     *
     * @param  array<int, array<string, mixed>>  $rawSets
     */
    public function recordPerformance(User $user, WorkoutPlanExercise $exercise, array $rawSets, ?string $notes = null): WorkoutPlan
    {
        $plan = $this->assertOwnership($user, $exercise);
        $sets = PerformanceSets::normalize($rawSets);
        $completed = count(array_filter($sets, fn ($set) => $set['completed']));

        if ($completed === 0) {
            throw ValidationException::withMessages([
                'sets' => 'Record at least one completed set, or skip the exercise instead.',
            ]);
        }

        DB::transaction(function () use ($user, $plan, $exercise, $sets, $completed, $notes) {
            $this->performanceService->store($user, $plan, $exercise, $sets, WorkoutExercise::RECORDED_ENTERED);

            // Fewer completed sets than planned means the exercise was only partly done.
            $isComplete = $completed === count($sets)
                && ($exercise->planned_sets === null || $completed >= $exercise->planned_sets);

            $exercise->update([
                'status' => $isComplete ? WorkoutPlanExercise::STATUS_COMPLETED : WorkoutPlanExercise::STATUS_PARTIAL,
                'notes' => $notes ?? $exercise->notes,
                'completed_at' => now(),
            ] + $this->actualSummary($sets));

            $this->recomputeStatus($user, $plan->fresh());
        });

        return $plan->fresh(['exercises.exerciseImage', 'exercises.performance.performedSets']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed> plan-exercise columns to update alongside the status
     */
    private function recordFromStatus(User $user, WorkoutPlan $plan, WorkoutPlanExercise $exercise, array $validated): array
    {
        $given = PerformanceSets::expand(
            $validated['actual_sets'] ?? null,
            $validated['actual_reps'] ?? null,
            isset($validated['actual_weight_kg']) ? (float) $validated['actual_weight_kg'] : null,
            $validated['actual_duration_seconds'] ?? null,
            assumeOneSet: true,
        );

        if ($given !== []) {
            $this->performanceService->store($user, $plan, $exercise, $given, WorkoutExercise::RECORDED_ENTERED);

            return $this->actualSummary($given);
        }

        $existing = $exercise->performance;

        // Re-tapping "done" must not overwrite numbers the user already entered.
        if ($existing && $existing->recorded_as === WorkoutExercise::RECORDED_ENTERED) {
            return [];
        }

        $assumed = $validated['status'] === WorkoutPlanExercise::STATUS_COMPLETED
            ? PerformanceSets::expand(
                $exercise->planned_sets,
                $exercise->planned_reps,
                $exercise->planned_weight_kg !== null ? (float) $exercise->planned_weight_kg : null,
                $exercise->planned_duration_seconds,
                assumeOneSet: true,
            )
            : [];

        $this->performanceService->store($user, $plan, $exercise, $assumed, WorkoutExercise::RECORDED_AS_PLANNED);

        // Assumed numbers are never written as the plan exercise's `actual_*`:
        // those columns are read back (including by the AI) as real performance.
        return [];
    }

    /**
     * The plan exercise's actual_* columns are only a summary derived from
     * entered sets — kept for backward compatibility, not a second source of truth.
     *
     * @param  array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>  $sets
     * @return array{actual_sets: ?int, actual_reps: ?int, actual_weight_kg: ?float, actual_duration_seconds: ?int}
     */
    private function actualSummary(array $sets): array
    {
        $summary = PerformanceSets::summarize($sets);

        return [
            'actual_sets' => $summary['sets'],
            'actual_reps' => $summary['reps'],
            'actual_weight_kg' => $summary['weight_kg'],
            'actual_duration_seconds' => $summary['duration_seconds'],
        ];
    }

    private function assertOwnership(User $user, WorkoutPlanExercise $exercise): WorkoutPlan
    {
        $plan = $exercise->workoutPlan;

        abort_unless($plan && $plan->user_id === $user->id, 403);

        return $plan;
    }

    /**
     * Derive the plan's overall status from its exercises' statuses and keep the
     * mirrored activity log in step with it (see ExercisePerformanceService).
     * Plans with no exercises (e.g. a sport-type plan) are left untouched; their
     * status is set directly by the caller instead.
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

        $this->performanceService->syncMirror($user, $plan);
    }
}
