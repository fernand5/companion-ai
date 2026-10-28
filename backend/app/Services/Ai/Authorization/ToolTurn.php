<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * The tools one turn may use, under one fixed authorization.
 *
 *  - declarations() offers the model only what the turn may do, so an
 *    unauthorized plan tool is not even visible to it;
 *  - call() re-checks at dispatch, so a call to a tool that was never offered (a
 *    hallucinated one, or one chained after a permitted tool) is refused all the same;
 *  - the authorization is read-only here: nothing a tool returns, and nothing the
 *    model says, can widen it.
 *
 * A refusal is a normal tool result the model can read; it never throws, and
 * nothing is written. Each one is remembered for the turn's log and metadata.
 */
final class ToolTurn
{
    /** @var array<int, array{tool: string, capability: string, reason: string}> */
    private array $blocked = [];

    /** A confirmed change can be made once; this turn has made it. */
    private bool $confirmedChangeMade = false;

    /** @var array<int, array{exercise: string, date: string, requested_kg: float}> loads written without a baseline and left unset */
    private array $loadsLeftUnset = [];

    /** "Add this workout to my dashboard" is handled once per turn: written, held for confirmation, or refused. */
    private bool $saveHandled = false;

    /** A replacement of an existing plan that the application will ask the user to confirm. */
    private ?PlanReplacementProposal $pendingReplacement = null;

    /** @var array{date: string, was: string}|null a plan the user explicitly asked to replace, and which was replaced */
    private ?array $replaced = null;

    /** True once a workout was written to the user's plan under the save scope. */
    private bool $workoutSaved = false;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly PlanLoadGuard $loadGuard,
        private readonly ProposalMatcher $matcher,
        public readonly ToolAuthorization $authorization,
    ) {}

    /**
     * @param  array<int, string>|null  $only  restrict further to these tool names (a flow's own allow-list)
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function declarations(?array $only = null): array
    {
        return array_values(array_filter(
            $this->registry->declarations(),
            fn (array $tool) => ($only === null || in_array($tool['name'], $only, true))
                && $this->authorization->permits(ToolCapabilities::for($tool['name']))
                // Under a confirmed change the only plan tool is the one a confirmation can cover.
                && ($this->authorization->scopedProposal() === null || ! ToolCapabilities::for($tool['name'])->needsAuthorization() || $tool['name'] === 'create_workout_plan'),
        ));
    }

    /** Names of the registered tools this turn may NOT use. */
    public function withheldTools(): array
    {
        $offered = array_column($this->declarations(), 'name');

        return array_values(array_filter(
            array_column($this->registry->declarations(), 'name'),
            fn (string $name) => ! in_array($name, $offered, true),
        ));
    }

    public function call(string $name, array $arguments, User $user): mixed
    {
        $capability = ToolCapabilities::for($name);

        if (! $this->authorization->permits($capability)) {
            return $this->refuse($name, $capability, 'not_authorized', sprintf(
                'Not done: the user has not asked to change their plan, so %s was not run and nothing was changed. '
                .'Do not retry it and do not tell the user it happened. Answer in text; if a plan change would help, say what you would suggest and that they can ask you to update their plan.',
                $name,
            ));
        }

        if ($this->authorization->scopedProposal() !== null && $capability->needsAuthorization()) {
            return $this->callConfirmed($name, $capability, $arguments, $user);
        }

        if ($this->authorization->saveScope !== null && $name === 'create_workout_plan' && $capability === ToolCapability::PlanCreate) {
            return $this->callSave($name, $capability, $arguments, $user);
        }

        $unset = [];

        if ($capability === ToolCapability::PlanCreate || $capability === ToolCapability::PlanAdapt) {
            // A load with nothing real behind it is left unset, not trusted (see PlanLoadGuard).
            ['arguments' => $arguments, 'unset' => $unset] = $this->loadGuard->withoutUnbaselinedLoads(
                $user, $name, $arguments, $this->authorization->mayRaiseLoads, $this->authorization->statedLoadsKg,
            );

            $violations = $this->loadGuard->violations($user, $name, $arguments, $this->authorization->mayRaiseLoads);

            if ($violations !== []) {
                return $this->refuse($name, $capability, 'load_above_limit', 'Not done, nothing was changed: '.implode(' ', $violations));
            }
        }

        $result = $this->registry->call($name, $arguments, $user);

        if ($unset !== [] && is_array($result) && ! array_key_exists('error', $result)) {
            $this->loadsLeftUnset = [...$this->loadsLeftUnset, ...$unset];
            $names = implode(', ', array_values(array_unique(array_column($unset, 'exercise'))));

            $result['load_not_set'] = [
                'exercises' => array_values(array_unique(array_column($unset, 'exercise'))),
                'instruction' => "The starting load for {$names} was left unset: there is no recorded or planned load to base one on. Tell the user to choose a weight they can lift comfortably for the target reps and record it. Do not state or suggest a specific weight.",
            ];
        }

        return $result;
    }

    /**
     * A write under a scoped authorization: the one tool a confirmation covers, once, and only
     * if the arguments are exactly the confirmed change. The change itself is then made by the
     * application from the signed proposal, not from the arguments.
     */
    private function callConfirmed(string $name, ToolCapability $capability, array $arguments, User $user): mixed
    {
        $proposal = $this->authorization->scopedProposal();

        if ($name !== 'create_workout_plan' || $capability !== ToolCapability::PlanCreate) {
            return $this->refuse($name, $capability, 'outside_confirmed_change', 'Not done: the user confirmed one specific change ('.$proposal->describe().') and this is not it. Nothing was changed.');
        }

        if ($this->confirmedChangeMade) {
            return $this->refuse($name, $capability, 'confirmation_already_used', 'Not done: the confirmed change has already been made. Nothing else was changed.');
        }

        $mismatch = $this->matcher->mismatch($user, $proposal, $arguments);

        if ($mismatch !== null) {
            $expected = $this->matcher->expectedArguments($user, $proposal);

            return $this->refuse($name, $capability, 'does_not_match_confirmed_change', 'Not done, nothing was changed: the user confirmed exactly one change ('.$proposal->describe().'), and this call is not exactly that: '.$mismatch
                .(is_array($expected) ? ' To make the confirmed change, call create_workout_plan with exactly these planned_date, activity_type, title, duration_minutes and exercises: '.json_encode($expected).' (add your own decision_summary and reasoning_factors).' : ''));
        }

        $violations = $this->loadGuard->violations($user, $name, $arguments, mayRaiseLoads: true);

        if ($violations !== []) {
            return $this->refuse($name, $capability, 'load_above_limit', 'Not done, nothing was changed: '.implode(' ', $violations));
        }

        $result = $this->matcher->apply($user, $proposal);

        if (is_string($result)) {
            return $this->refuse($name, $capability, 'confirmed_change_not_applicable', 'Not done, nothing was changed: '.$result);
        }

        $this->confirmedChangeMade = true;

        return $result;
    }

    /**
     * A write under "add this workout to my dashboard": exactly the workout and the day the user asked for, once.
     * A day with nothing planned is written. A day with a planned, untouched workout is only replaced if the user
     * said to replace it; otherwise the replacement is held for their confirmation (see pendingReplacement()) and
     * nothing is written. A day with anything performed is never replaced.
     */
    private function callSave(string $name, ToolCapability $capability, array $arguments, User $user): mixed
    {
        $scope = $this->authorization->saveScope;

        if ($this->saveHandled) {
            return $this->refuse($name, $capability, 'save_already_handled', 'Not done: the one workout the user asked to save has already been handled this turn. Nothing else was changed.');
        }

        $mismatch = $scope->mismatch($arguments);

        if ($mismatch !== null) {
            return $this->refuse($name, $capability, 'outside_requested_workout', 'Not done, nothing was changed: the user asked to save one specific workout on '.$scope->date.', and this call is not exactly that: '.$mismatch
                .' To save it, call create_workout_plan with planned_date '.$scope->date.' and exactly these exercises, each as written: '.$scope->describeItems().'.');
        }

        if (empty($arguments['reasoning_factors']) || empty($arguments['decision_summary'])) {
            return ['error' => 'reasoning_factors and decision_summary are required when creating a plan.'];
        }

        // The day is the application's, whatever the call said (or left out).
        $arguments['planned_date'] = $scope->date;

        ['arguments' => $arguments, 'unset' => $unset] = $this->loadGuard->withoutUnbaselinedLoads($user, $name, $arguments, false, []);

        $violations = $this->loadGuard->violations($user, $name, $arguments, false);

        if ($violations !== []) {
            return $this->refuse($name, $capability, 'load_above_limit', 'Not done, nothing was changed: '.implode(' ', $violations));
        }

        $existing = $user->workoutPlans()->with('exercises')->whereDate('planned_date', $scope->date)->first();

        if ($existing !== null && ! $existing->isReplaceable()) {
            $this->saveHandled = true;

            return $this->refuse($name, $capability, 'day_already_performed', 'Not done, nothing was changed: '.$scope->date.' already has recorded workout performance, so its plan cannot be replaced. Tell the user, and that they can choose another day.');
        }

        if ($existing !== null && ! $scope->replacementRequested) {
            try {
                $this->pendingReplacement = PlanReplacementProposal::create(
                    $user->id, $scope->conversationId, $scope->date, $existing->id, $existing->revision(), $this->describeExisting($existing), $arguments,
                );
            } catch (\LogicException) {
                return $this->refuse($name, $capability, 'cannot_ask_for_confirmation', 'Not done, nothing was changed: replacing that day needs the user\'s confirmation and it cannot be asked for right now.');
            }

            $this->saveHandled = true;
            $this->loadsLeftUnset = [...$this->loadsLeftUnset, ...$unset];

            return [
                'error' => 'Nothing was changed yet: '.$scope->date.' already has a planned workout ('.$this->describeExisting($existing).'). The application is now asking the user to confirm replacing it. '
                    .'Do not say it was saved or replaced; say that nothing has changed until the user confirms.',
                'pending_confirmation' => true,
            ];
        }

        $result = $this->registry->call($name, $arguments, $user);

        if (is_array($result) && ! array_key_exists('error', $result)) {
            $this->saveHandled = true;
            $this->workoutSaved = true;

            if ($existing !== null) {
                $this->replaced = ['date' => $scope->date, 'was' => $this->describeExisting($existing)];
            }

            if ($unset !== []) {
                $this->loadsLeftUnset = [...$this->loadsLeftUnset, ...$unset];
                $names = implode(', ', array_values(array_unique(array_column($unset, 'exercise'))));

                $result['load_not_set'] = [
                    'exercises' => array_values(array_unique(array_column($unset, 'exercise'))),
                    'instruction' => "The starting load for {$names} was left unset: there is no recorded or planned load to base one on. Tell the user to choose a weight they can lift comfortably for the target reps and record it. Do not state or suggest a specific weight.",
                ];
            }
        }

        return $result;
    }

    /** "“Upper body” (Push-ups 3×12, Plank 30 s)", or "a rest day". */
    private function describeExisting(WorkoutPlan $plan): string
    {
        if ($plan->isRestDay()) {
            return 'a rest day';
        }

        return PlanReplacementProposal::summarise([
            'title' => $plan->title,
            'exercises' => $plan->exercises->map(fn ($e) => [
                'exercise_name' => $e->exercise_name, 'planned_sets' => $e->planned_sets, 'planned_reps' => $e->planned_reps,
                'planned_duration_seconds' => $e->planned_duration_seconds,
            ])->all(),
        ]);
    }

    /** The replacement the application must now ask the user to confirm, if this turn found one. */
    public function pendingReplacement(): ?PlanReplacementProposal
    {
        return $this->pendingReplacement;
    }

    /** @return array{date: string, was: string}|null the plan an explicit replacement request just replaced */
    public function replaced(): ?array
    {
        return $this->replaced;
    }

    /** True when a workout was written under the save scope this turn. */
    public function workoutSaved(): bool
    {
        return $this->workoutSaved;
    }

    /**
     * What the application tells the user about loads it left unset this turn, in its own words (no number,
     * no suggested weight), or null when there were none.
     */
    public function loadsLeftUnsetNotice(): ?string
    {
        if ($this->loadsLeftUnset === []) {
            return null;
        }

        $names = array_values(array_unique(array_column($this->loadsLeftUnset, 'exercise')));

        return 'I left the starting weight for '.implode(', ', $names).' unset: there is no recorded or planned weight to base one on. '
            .'Choose a weight you can lift comfortably for the target reps, and record it when you log your sets.';
    }

    /** True once the confirmed change has been made in this turn. */
    public function confirmedChangeMade(): bool
    {
        return $this->confirmedChangeMade;
    }

    /** @return array<int, array{tool: string, capability: string, reason: string}> */
    public function blocked(): array
    {
        return $this->blocked;
    }

    private function refuse(string $name, ToolCapability $capability, string $reason, string $message): array
    {
        $this->blocked[] = ['tool' => $name, 'capability' => $capability->value, 'reason' => $reason];

        // Structured metadata only: never the arguments, which can carry the user's data.
        Log::warning('ai_coach.tool_blocked', [
            'tool' => $name,
            'capability' => $capability->value,
            'reason' => $reason,
            'authorization' => $this->authorization->basis,
        ]);

        return ['error' => $message, 'blocked' => true, 'reason' => $reason];
    }
}
