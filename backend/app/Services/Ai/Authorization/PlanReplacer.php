<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a confirmed set of whole-day replacements, and only those. The changes are applied by the application from the
 * signed proposal, never from anything the model says, and only if EVERY day is still exactly as it was shown: the same
 * plan, the same content, and still nothing performed. It is all or none. The load rules run again on the way in, so a
 * confirmation cannot be a way around them.
 */
class PlanReplacer
{
    public function __construct(
        private readonly WorkoutPlanService $plans,
        private readonly WeeklyPlanService $weekly,
        private readonly PlanLoadGuard $loadGuard,
    ) {}

    /**
     * @return array<int, WorkoutPlan>|string the plans as written, or why nothing was replaced (nothing is changed then)
     */
    public function apply(User $user, PlanReplacementProposal $proposal): array|string
    {
        try {
            return DB::transaction(function () use ($user, $proposal) {
                $writes = [];

                // First look at every day. Nothing is written unless all of them are still as they were shown.
                foreach ($proposal->changes as $change) {
                    $existing = $user->workoutPlans()->whereKey($change['existing_plan_id'])->lockForUpdate()->first();

                    if ($existing === null || $existing->planned_date->toDateString() !== $change['date']) {
                        return "the plan I asked about for {$change['date']} is no longer there.";
                    }

                    if ($existing->revision() !== $change['existing_revision']) {
                        return "the plan for {$change['date']} changed after I asked.";
                    }

                    if (! $existing->isReplaceable()) {
                        return "{$change['date']} already has recorded workout performance, so its plan can no longer be replaced.";
                    }

                    if ($change['action'] === PlanReplacementProposal::CLEAR) {
                        $writes[] = [$change, null];

                        continue;
                    }

                    // The same rules as any model-written plan: no load without a baseline, none above the limit.
                    ['arguments' => $arguments] = $this->loadGuard->withoutUnbaselinedLoads($user, 'create_workout_plan', PlanReplacementProposal::arguments($change) + ['source' => 'ai'], false, []);

                    if (($violations = $this->loadGuard->violations($user, 'create_workout_plan', $arguments, false)) !== []) {
                        return implode(' ', $violations);
                    }

                    $writes[] = [$change, $arguments];
                }

                $written = [];

                foreach ($writes as [$change, $arguments]) {
                    $written[] = $arguments === null
                        ? $this->weekly->clearDay($user, $change['date'], $change['reason'] !== '' ? $change['reason'] : 'Cleared as you confirmed.', [])
                        : $this->plans->createOrReplace($user, $arguments, replaceExisting: true);
                }

                return $written;
            });
        } catch (ValidationException $e) {
            return implode(' ', $e->validator->errors()->all());
        }
    }
}
