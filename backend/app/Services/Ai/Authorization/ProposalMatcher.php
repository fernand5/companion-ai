<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\GetTodaysPlanTool;
use Illuminate\Validation\ValidationException;

/**
 * Decides, at the moment a tool is dispatched, whether a write is EXACTLY the
 * change the user confirmed, and applies exactly that change.
 *
 * A write matches only if, against the plan as it stands now, the only difference is
 * the confirmed exercise's load (and its sets/reps only if the proposal names them):
 * same plan, same day, same activity type, same duration, the same exercises in the
 * same order with every other target unchanged. Anything else (another exercise, a
 * different day, 47.5 kg instead of 45, an extra or missing exercise) does not match.
 *
 * The model's arguments are only compared, never trusted: the change itself is applied
 * by the application from the signed proposal.
 */
class ProposalMatcher
{
    public function __construct(private readonly WorkoutPlanService $plans) {}

    /**
     * The exact arguments a matching create_workout_plan call carries, or a string saying
     * why the confirmed change can no longer be made (the plan changed since it was shown).
     *
     * @return array<string, mixed>|string
     */
    public function expectedArguments(User $user, PlanChangeProposal $proposal): array|string
    {
        $resolved = $this->resolve($user, $proposal);

        if (is_string($resolved)) {
            return $resolved;
        }

        [$plan, $target] = $resolved;

        return [
            'planned_date' => $proposal->date,
            'activity_type' => $plan->activity_type,
            'title' => $plan->title,
            'duration_minutes' => $plan->duration_minutes,
            'exercises' => $plan->exercises->map(fn (WorkoutPlanExercise $e) => $this->exerciseArguments($e, $e->is($target) ? $proposal : null))->values()->all(),
        ];
    }

    /** Null when $arguments is exactly the confirmed change; otherwise what differs. */
    public function mismatch(User $user, PlanChangeProposal $proposal, array $arguments): ?string
    {
        $expected = $this->expectedArguments($user, $proposal);

        if (is_string($expected)) {
            return $expected;
        }

        if (($arguments['planned_date'] ?? null) !== $expected['planned_date']) {
            return 'planned_date must be exactly '.$expected['planned_date'].'.';
        }

        if (($arguments['activity_type'] ?? null) !== $expected['activity_type']) {
            return 'activity_type must be exactly '.$expected['activity_type'].'.';
        }

        if ($this->number($arguments['duration_minutes'] ?? null) !== $this->number($expected['duration_minutes'])) {
            return 'duration_minutes must be left as it is ('.json_encode($expected['duration_minutes']).').';
        }

        $given = $arguments['exercises'] ?? null;

        if (! is_array($given) || count($given) !== count($expected['exercises'])) {
            return 'exercises must list exactly the '.count($expected['exercises']).' exercises already in that day, in the same order.';
        }

        foreach ($expected['exercises'] as $i => $want) {
            $have = $given[$i] ?? null;

            if (! is_array($have)) {
                return "exercise {$i} is malformed.";
            }

            if (trim((string) ($have['exercise_name'] ?? '')) !== $want['exercise_name']) {
                return "exercise {$i} must be \"{$want['exercise_name']}\".";
            }

            foreach (['planned_sets', 'planned_reps', 'planned_weight_kg', 'planned_duration_seconds'] as $field) {
                if ($this->number($have[$field] ?? null) !== $this->number($want[$field])) {
                    return "{$want['exercise_name']}: {$field} must be ".json_encode($want[$field]).'.';
                }
            }
        }

        return null;
    }

    /**
     * Make the confirmed change, and only it.
     *
     * @return array<string, mixed>|string the plan as the tool presents it, or why it could not be made
     */
    public function apply(User $user, PlanChangeProposal $proposal): array|string
    {
        $resolved = $this->resolve($user, $proposal);

        if (is_string($resolved)) {
            return $resolved;
        }

        $changes = ['weight_kg' => $proposal->plannedWeightKg];

        if ($proposal->plannedSets !== null) {
            $changes['sets'] = $proposal->plannedSets;
        }

        if ($proposal->plannedReps !== null) {
            $changes['reps'] = $proposal->plannedReps;
        }

        try {
            return GetTodaysPlanTool::present($this->plans->applyExerciseTarget($user, $resolved[1], $changes));
        } catch (ValidationException $e) {
            return implode(' ', $e->validator->errors()->all());
        }
    }

    /**
     * The live plan and exercise the proposal was about: same rows, same day, still pending.
     *
     * @return array{0: WorkoutPlan, 1: WorkoutPlanExercise}|string
     */
    private function resolve(User $user, PlanChangeProposal $proposal): array|string
    {
        $plan = $user->workoutPlans()->with('exercises')->whereKey($proposal->planId)->first();

        if ($plan === null || $plan->planned_date->toDateString() !== $proposal->date) {
            return 'the plan the user confirmed no longer exists on that day.';
        }

        $target = $plan->exercises->first(fn (WorkoutPlanExercise $e) => $e->id === $proposal->planExerciseId);

        if ($target === null || $target->exercise_slug !== $proposal->exerciseSlug) {
            return 'the exercise the user confirmed is no longer in that plan.';
        }

        if ($target->status !== WorkoutPlanExercise::STATUS_PENDING || $plan->hasRecordedPerformance()) {
            return 'that day already has recorded performance, so its targets can no longer be changed.';
        }

        return [$plan, $target];
    }

    /** @return array<string, mixed> */
    private function exerciseArguments(WorkoutPlanExercise $exercise, ?PlanChangeProposal $change): array
    {
        return [
            'exercise_name' => $exercise->exercise_name,
            'planned_sets' => $change?->plannedSets ?? $exercise->planned_sets,
            'planned_reps' => $change?->plannedReps ?? $exercise->planned_reps,
            'planned_weight_kg' => $change !== null ? $change->plannedWeightKg : ($exercise->planned_weight_kg !== null ? (float) $exercise->planned_weight_kg : null),
            'planned_duration_seconds' => $exercise->planned_duration_seconds,
        ];
    }

    /** A comparable form: null stays null, numbers compare by value (45 = 45.0), anything else cannot match a number. */
    private function number(mixed $value): string|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 4) : 'not-a-number:'.json_encode($value);
    }
}
