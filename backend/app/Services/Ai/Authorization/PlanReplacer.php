<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\PlanReplacementNotAuthorized;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use App\Support\ExerciseSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a confirmed set of whole-day changes, and only those. The changes are applied by the application from the
 * signed proposal, never from anything the model says, and only if EVERY day is still exactly as it was shown: the same
 * plan, the same content, and still nothing performed (a day shown as empty must still be empty). It is all or none:
 * everything is checked before anything is written, everything is written in one transaction, and what was written is
 * read back inside that transaction, so a week is never half saved and success is never reported for data that is not
 * there. The load rules run again on the way in, so a confirmation cannot be a way around them.
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
                    // A day shown as empty: it must still be empty, whatever was planned there since.
                    if ($change['existing_plan_id'] === 0) {
                        if ($user->workoutPlans()->whereDate('planned_date', $change['date'])->lockForUpdate()->exists()) {
                            return "a plan appeared on {$change['date']} after I asked.";
                        }

                        $writes[] = $change['action'] === PlanReplacementProposal::CLEAR ? [$change, null] : [$change, $this->checked($user, $change)];

                        if (is_string($writes[array_key_last($writes)][1])) {
                            return $writes[array_key_last($writes)][1];
                        }

                        continue;
                    }

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

                    $arguments = $this->checked($user, $change);

                    if (is_string($arguments)) {
                        return $arguments;
                    }

                    $writes[] = [$change, $arguments];
                }

                $written = [];

                foreach ($writes as [$change, $arguments]) {
                    $written[] = $arguments === null
                        ? $this->weekly->clearDay($user, $change['date'], $change['reason'] !== '' ? $change['reason'] : 'Cleared as you confirmed.', [])
                        : $this->plans->createOrReplace($user, $arguments, replaceExisting: $change['action'] !== PlanReplacementProposal::CREATE);
                }

                // Read it back before it is committed: what the user is about to be told is on their plan must be there.
                foreach ($writes as $i => [$change]) {
                    if (($problem = $this->notPersisted($user, $change)) !== null) {
                        throw new PlanNotPersisted($problem);
                    }
                }

                return $written;
            });
        } catch (ValidationException $e) {
            return implode(' ', $e->validator->errors()->all());
        } catch (PlanNotPersisted $e) {
            return $e->getMessage();
        } catch (PlanReplacementNotAuthorized $e) {
            // Another request wrote this day between the check and the write: this one writes nothing at all.
            return "a plan appeared on {$e->date} after I asked.";
        } catch (\Throwable $e) {
            // Whatever went wrong, the transaction has rolled back: nothing was saved on any day.
            report($e);

            return 'something went wrong while saving, so none of the days were saved.';
        }
    }

    /**
     * The arguments one change is written from, after the same rules as any model-written plan (no load without a
     * baseline, none above the limit), or why it cannot be written.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>|string|null null for a change that clears a day
     */
    private function checked(User $user, array $change): array|string|null
    {
        if ($change['action'] === PlanReplacementProposal::CLEAR) {
            return null;
        }

        ['arguments' => $arguments] = $this->loadGuard->withoutUnbaselinedLoads($user, 'create_workout_plan', PlanReplacementProposal::arguments($change) + ['source' => 'ai'], false, []);

        if (($violations = $this->loadGuard->violations($user, 'create_workout_plan', $arguments, false)) !== []) {
            return implode(' ', $violations);
        }

        return $arguments;
    }

    /**
     * Why the day does not hold what the change said it would, or null if it does: a rest day for a clear; for a
     * workout, the title and every exercise, in order.
     *
     * @param  array<string, mixed>  $change
     */
    private function notPersisted(User $user, array $change): ?string
    {
        $plan = $user->workoutPlans()->with('exercises')->whereDate('planned_date', $change['date'])->first();

        if ($plan === null) {
            return "{$change['date']} was not saved.";
        }

        if ($change['action'] === PlanReplacementProposal::CLEAR) {
            return $plan->isRestDay() ? null : "{$change['date']} was not saved as a rest day.";
        }

        $want = array_map(fn (array $e) => ExerciseSlug::from($e['exercise_name']), $change['replacement']['exercises']);
        $have = $plan->exercises->sortBy('position')->map(fn ($e) => $e->exercise_slug)->values()->all();

        if ($want !== $have || $plan->title !== $change['replacement']['title']) {
            return "{$change['date']} does not hold what was shown.";
        }

        return null;
    }
}
