<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
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

        if ($capability === ToolCapability::PlanCreate || $capability === ToolCapability::PlanAdapt) {
            $violations = $this->loadGuard->violations($user, $name, $arguments);

            if ($violations !== []) {
                return $this->refuse($name, $capability, 'load_above_limit', 'Not done, nothing was changed: '.implode(' ', $violations));
            }
        }

        return $this->registry->call($name, $arguments, $user);
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

        $violations = $this->loadGuard->violations($user, $name, $arguments);

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
