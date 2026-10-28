<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a confirmed whole-day replacement, and only that one. The change is applied by the application from the
 * signed proposal, never from anything the model says, and only if the day is still exactly as it was shown:
 * the same plan, the same content, and still nothing performed. The load rules run again on the way in, so a
 * confirmation cannot be a way around them.
 */
class PlanReplacer
{
    public function __construct(
        private readonly WorkoutPlanService $plans,
        private readonly PlanLoadGuard $loadGuard,
    ) {}

    /**
     * @return WorkoutPlan|string the new plan, or why the replacement was not made (nothing is changed then)
     */
    public function apply(User $user, PlanReplacementProposal $proposal): WorkoutPlan|string
    {
        try {
            return DB::transaction(function () use ($user, $proposal) {
                $existing = $user->workoutPlans()->whereKey($proposal->existingPlanId)->lockForUpdate()->first();

                if ($existing === null || $existing->planned_date->toDateString() !== $proposal->date) {
                    return 'the plan I asked about is no longer there.';
                }

                if ($existing->revision() !== $proposal->existingRevision) {
                    return 'that day\'s plan changed after I asked.';
                }

                if (! $existing->isReplaceable()) {
                    return 'that day already has recorded workout performance, so its plan can no longer be replaced.';
                }

                // The same rules as any model-written plan: no load without a baseline, none above the limit.
                $arguments = ['planned_date' => $proposal->date, 'source' => 'ai'] + $proposal->arguments();
                ['arguments' => $arguments] = $this->loadGuard->withoutUnbaselinedLoads($user, 'create_workout_plan', $arguments, false, []);

                $violations = $this->loadGuard->violations($user, 'create_workout_plan', $arguments, false);

                if ($violations !== []) {
                    return implode(' ', $violations);
                }

                return $this->plans->createOrReplace($user, $arguments);
            });
        } catch (ValidationException $e) {
            return implode(' ', $e->validator->errors()->all());
        }
    }
}
