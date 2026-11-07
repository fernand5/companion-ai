<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Tools\ReplacementGrant;
use App\Services\Tools\ToolRegistry;
use App\Support\ApplicationKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    /**
     * Replacements of days that already have an untouched plan, held for the user's confirmation instead of being
     * written (by date). The application turns them into ONE signed proposal and asks (see pendingReplacement()).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $held = [];

    private ?PlanReplacementProposal $proposal = null;

    private ?int $heldFor = null;

    /** @var array{date: string, was: string}|null a plan the user explicitly asked to replace, and which was replaced */
    private ?array $replaced = null;

    /** True once a workout was written to the user's plan under the save scope. */
    private bool $workoutSaved = false;

    private ?string $rangeNote = null;

    /** "Add this plan to my dashboard" is handled once per turn: saved, held for confirmation, or refused. */
    private bool $weekHandled = false;

    /** @var array<int, array<string, mixed>>|null the changes that were written, all of them, when a plan of several days was saved */
    private ?array $weekSaved = null;

    /** @var array<int, array{date: string, label: string, reason: string}> days that were started or performed, which stopped the whole plan from being saved */
    private array $weekBlocked = [];

    /** Why a plan of several days was not saved, in words the application can pass on, when it was refused for something the user can act on. */
    private ?string $weekProblem = null;

    /** @var array<int, string> days of the plan that were already on the user's plan exactly as shown */
    private array $weekAlready = [];

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly PlanLoadGuard $loadGuard,
        private readonly ProposalMatcher $matcher,
        public readonly ToolAuthorization $authorization,
        private readonly WeeklyPlanService $weekly,
        private readonly ?int $conversationId = null,
        private readonly ?PlanReplacer $replacer = null,
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
        // Only this class decides that a day may be replaced; whatever the model put there is thrown away.
        $arguments = ReplacementGrant::strip($arguments);
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

        if ($this->authorization->weekScope !== null && $capability->needsAuthorization()) {
            return $name === 'propose_weekly_plan_changes'
                ? $this->callWeekSave($name, $capability, $arguments, $user)
                : $this->refuse($name, $capability, 'outside_requested_plan', 'Not done: the user asked to save one specific plan of several days, which is done with propose_weekly_plan_changes only. Nothing was changed.');
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

        // Replacing a day's existing plan is a separate decision from writing a plan (see authorizeReplacement()).
        $heldDates = [];

        if ($capability === ToolCapability::PlanCreate || $capability === ToolCapability::PlanAdapt) {
            [$arguments, $early, $heldDates] = $this->authorizeReplacement($name, $capability, $arguments, $user, $unset);

            if ($early !== null) {
                return $early;
            }
        }

        $result = $this->registry->call($name, $arguments, $user);

        if ($heldDates !== [] && is_array($result)) {
            $result['held_for_confirmation'] = $heldDates;
            $result['note'] = 'Those days already have a plan, so nothing was changed on them: the application is asking the user to confirm. Do not say they were changed.';
        }

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
     * The one place a chat tool call is allowed (or not) to REPLACE a plan that is already on a day. Three kinds of day:
     *
     *  - nothing planned: written, as always;
     *  - started, partly done, completed or with recorded sets: never replaced (the write refuses it too);
     *  - an untouched plan: the write may replace it only if the authorization covers THIS change. A user-invoked
     *    endpoint (the Weekly Plan page's own adaptation box) covers it. An explicit request to change the plan covers a
     *    targeted adjustment of the same exercises (PlanChangeKind::TARGETED). Everything else, a different workout in
     *    its place or a rest day made of it, from any request, is held: nothing is written, and the application asks
     *    the user to confirm exactly that (PlanReplacementProposal). A report, a request for a session or a wish is
     *    never enough, and neither is a model's say-so.
     *
     * The service that does the write refuses to replace unless it is handed the grant set here.
     *
     * @param  array<int, array{exercise: string, date: string, requested_kg: float}>  $unset
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: array<int, string>} arguments (with the grant), an early result, days held
     */
    private function authorizeReplacement(string $name, ToolCapability $capability, array $arguments, User $user, array $unset): array
    {
        if ($this->authorization->basis === 'endpoint:'.ToolAuthorization::ENDPOINT_WEEKLY_ADAPT) {
            return [ReplacementGrant::grant($arguments, true), null, []];
        }

        if ($name === 'create_workout_plan' && $capability === ToolCapability::PlanCreate) {
            $date = $this->dateOf($arguments['planned_date'] ?? null, $user);
            $existing = $this->untouchedPlan($user, $date);

            if ($existing === null) {
                return [$arguments, null, []]; // nothing there (or not replaceable: the write itself refuses)
            }

            if ($this->coveredByRequest(PlanChangeKind::of($existing, $arguments))) {
                return [ReplacementGrant::grant($arguments, [$date]), null, []];
            }

            $held = $this->buildHold($user, $date, $existing, PlanReplacementProposal::REPLACE, $arguments, $unset);

            if (is_string($held)) {
                return [$arguments, $this->refuse($name, $capability, 'cannot_ask_for_confirmation', $held), []];
            }

            $this->commitHold($user, $date, $held);

            return [$arguments, $this->pendingResult($date, $existing), []];
        }

        if ($name === 'propose_weekly_plan_changes' && is_array($arguments['changes'] ?? null) && $arguments['changes'] !== []) {
            return $this->authorizeWeeklyReplacements($name, $capability, $arguments, $user, $unset);
        }

        return [$arguments, null, []];
    }

    /**
     * A weekly adaptation keeps working on days with nothing planned and on targeted adjustments the user asked for. A
     * change that would replace or clear an untouched plan is taken out of the batch and held for confirmation; the rest
     * of the batch goes ahead. If any change is invalid, or any hold cannot be made, nothing is held and the tool
     * answers as it always did.
     *
     * @param  array<int, array{exercise: string, date: string, requested_kg: float}>  $unset
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: array<int, string>}
     */
    private function authorizeWeeklyReplacements(string $name, ToolCapability $capability, array $arguments, User $user, array $unset): array
    {
        try {
            $valid = $this->weekly->validatedChanges($user, $arguments['changes']);
        } catch (ValidationException) {
            return [$arguments, null, []]; // the tool reports the same error, and writes nothing
        }

        $original = array_values($arguments['changes']);
        $keep = [];
        $grant = [];
        $holds = [];

        foreach ($valid as $i => $change) {
            $existing = $this->untouchedPlan($user, $change['date']);

            // Nowhere to ask (a button, not a conversation): a day that already has a plan is left as it is, and the
            // write reports it as needing confirmation, while the rest of the batch goes ahead.
            if ($existing === null || $this->conversationId === null) {
                $keep[] = $original[$i];

                continue;
            }

            $kind = $change['action'] === 'clear'
                ? ($existing->isRestDay() ? PlanChangeKind::NONE : PlanChangeKind::REPLACEMENT)
                : PlanChangeKind::of($existing, $change);

            if ($this->coveredByRequest($kind)) {
                $keep[] = $original[$i];
                $grant[] = $change['date'];

                continue;
            }

            $held = $this->buildHold(
                $user, $change['date'], $existing,
                $change['action'] === 'clear' ? PlanReplacementProposal::CLEAR : PlanReplacementProposal::REPLACE,
                $change + ['decision_summary' => $change['reason'], 'reasoning_factors' => $arguments['reasoning_factors'] ?? []],
                $unset,
                $change['reason'],
            );

            if (is_string($held)) {
                return [$arguments, $this->refuse($name, $capability, 'cannot_ask_for_confirmation', $held), []];
            }

            $holds[$change['date']] = [$held, $existing];
        }

        foreach ($holds as $date => [$held]) {
            $this->commitHold($user, $date, $held);
        }

        $arguments['changes'] = $keep;

        return [$grant === [] ? $arguments : ReplacementGrant::grant($arguments, $grant), null, array_keys($holds)];
    }

    /** What a request covers without asking again: no change at all, and, if the user asked to change the plan, an adjustment. */
    private function coveredByRequest(string $kind): bool
    {
        return $kind === PlanChangeKind::NONE
            || ($kind === PlanChangeKind::TARGETED && in_array('explicit_change', explode('+', $this->authorization->basis), true));
    }

    /** The day's plan if it exists and may still be replaced (untouched), else null. */
    private function untouchedPlan(User $user, string $date): ?WorkoutPlan
    {
        $plan = $user->workoutPlans()->with('exercises')->whereDate('planned_date', $date)->first();

        return $plan !== null && $plan->isReplaceable() ? $plan : null;
    }

    private function dateOf(mixed $value, User $user): string
    {
        try {
            return CarbonImmutable::parse(is_string($value) && $value !== '' ? $value : $user->localToday())->toDateString();
        } catch (\Throwable) {
            return (string) $user->localToday();
        }
    }

    /**
     * One held change, checked against the strictest load rules so the user is never asked to confirm something the
     * confirmation would then refuse. A string is the reason it cannot be held.
     *
     * @param  array<string, mixed>  $replacement  a create_workout_plan call (or a weekly change), unused for a clear
     * @param  array<int, array{exercise: string, date: string, requested_kg: float}>  $unset
     * @return array<string, mixed>|string
     */
    private function buildHold(User $user, string $date, WorkoutPlan $existing, string $action, array $replacement, array $unset, string $reason = ''): array|string
    {
        if ($this->conversationId === null || ! ApplicationKey::usable()) {
            return 'Not done, nothing was changed: replacing a day that already has a plan needs the user\'s confirmation and it cannot be asked for here.';
        }

        if (! isset($this->held[$date]) && count($this->held) >= PlanReplacementProposal::MAX_DAYS) {
            return 'Not done, nothing was changed: too many days at once. Ask about fewer days.';
        }

        $change = [
            'date' => $date,
            'existing_plan_id' => $existing->id,
            'existing_revision' => $existing->revision(),
            'existing_summary' => $this->describeExisting($existing),
            'action' => $action,
            'reason' => $reason,
            'replacement' => null,
            'unset' => [],
        ];

        if ($action === PlanReplacementProposal::REPLACE) {
            $replacement = ['planned_date' => $date] + $replacement;
            ['arguments' => $replacement, 'unset' => $stricter] = $this->loadGuard->withoutUnbaselinedLoads($user, 'create_workout_plan', $replacement, false, []);

            if (($violations = $this->loadGuard->violations($user, 'create_workout_plan', $replacement, false)) !== []) {
                return 'Not done, nothing was changed: '.implode(' ', $violations);
            }

            $change['replacement'] = $replacement;
            $change['unset'] = [...$unset, ...$stricter];
        }

        return $change;
    }

    /** @param array<string, mixed> $change */
    private function commitHold(User $user, string $date, array $change): void
    {
        $this->loadsLeftUnset = [...$this->loadsLeftUnset, ...$change['unset']];
        unset($change['unset']);
        $this->held[$date] = $change;
        $this->heldFor = $user->id;
        $this->proposal = null;
    }

    /** @return array<string, mixed> */
    private function pendingResult(string $date, WorkoutPlan $existing): array
    {
        return [
            'error' => 'Nothing was changed yet: '.$date.' already has a planned workout ('.$this->describeExisting($existing).'). The application is now asking the user to confirm replacing it. '
                .'Do not say it was saved or replaced; say that nothing has changed until the user confirms.',
            'pending_confirmation' => true,
        ];
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

        // The day is the application's, whatever the call said (or left out); so is the number stored for a range.
        $arguments['planned_date'] = $scope->date;
        $arguments = $scope->normalise($arguments);

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
            $held = $this->buildHold($user, $scope->date, $existing, PlanReplacementProposal::REPLACE, $arguments, $unset);

            if (is_string($held)) {
                return $this->refuse($name, $capability, 'cannot_ask_for_confirmation', $held);
            }

            $this->commitHold($user, $scope->date, $held);
            $this->saveHandled = true;

            return $this->pendingResult($scope->date, $existing);
        }

        // Writing over an untouched plan the user said to replace, or to an empty day: the grant covers this one day.
        $arguments = ReplacementGrant::grant($arguments, [$scope->date]);

        $result = $this->registry->call($name, $arguments, $user);

        if (is_array($result) && ! array_key_exists('error', $result)) {
            $this->saveHandled = true;
            $this->workoutSaved = true;
            $this->rangeNote = $scope->rangeNote($arguments);

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

    /**
     * A write under "add this plan to my dashboard": exactly the days and the content the user was shown, ALL of them or
     * none. Everything is checked before anything is written (the days named, each exercise as written, the load rules,
     * and that no day has been started or performed). Days with nothing planned are then written together, in one
     * transaction that reads the data back (PlanReplacer). If any day already has an untouched plan, nothing is written:
     * the whole set is held as one signed proposal and the user is asked to confirm exactly those changes. If any day
     * was started or performed, nothing is written for any day, so a week is never half saved.
     */
    private function callWeekSave(string $name, ToolCapability $capability, array $arguments, User $user): mixed
    {
        $scope = $this->authorization->weekScope;

        if ($this->weekHandled) {
            return $this->refuse($name, $capability, 'save_already_handled', 'Not done: the one plan the user asked to save has already been handled this turn. Nothing else was changed.');
        }

        $mismatch = $scope->mismatch($arguments['changes'] ?? null);

        if ($mismatch !== null) {
            return $this->refuse($name, $capability, 'outside_requested_plan', 'Not done, nothing was changed: the user asked to save one specific plan, and this call is not exactly that: '.$mismatch
                .' To save it, call propose_weekly_plan_changes once, with changes for exactly these days: '.$scope->describe().'. Each change also needs a reason.');
        }

        if (empty($arguments['decision_summary']) || empty($arguments['reasoning_factors'])) {
            return ['error' => 'decision_summary and reasoning_factors are required when saving a plan.'];
        }

        $changes = $scope->normalise(array_values($arguments['changes']));
        $today = CarbonImmutable::parse($user->localToday());

        try {
            $valid = $this->weekly->validatedChanges($user, $changes, ['start' => $today->toDateString(), 'end' => $today->addDays(WeekPlanReference::HORIZON_DAYS)->toDateString()]);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        $labels = array_column($scope->days, 'label', 'date');
        $built = [];
        $unset = [];
        $already = [];
        $blocked = [];

        foreach ($valid as $change) {
            $date = $change['date'];
            $existing = $user->workoutPlans()->with('exercises')->whereDate('planned_date', $date)->first();

            // Started or performed: never touched, and the rest of the plan is not saved around it.
            if ($existing !== null && ! $existing->isReplaceable()) {
                $blocked[] = ['date' => $date, 'label' => $labels[$date] ?? $date, 'reason' => $existing->hasRecordedPerformance() ? 'performed' : 'started'];

                continue;
            }

            $replacement = null;

            if ($change['action'] === 'replace') {
                $replacement = ['planned_date' => $date] + $change + ['decision_summary' => $change['reason'], 'reasoning_factors' => $arguments['reasoning_factors']];
                ['arguments' => $replacement, 'unset' => $stricter] = $this->loadGuard->withoutUnbaselinedLoads($user, 'create_workout_plan', $replacement, false, []);

                if (($violations = $this->loadGuard->violations($user, 'create_workout_plan', $replacement, false)) !== []) {
                    $this->weekProblem = implode(' ', $violations);

                    return $this->refuse($name, $capability, 'load_above_limit', 'Not done, nothing was changed: '.implode(' ', $violations));
                }

                $unset = [...$unset, ...$stricter];
            }

            if ($existing !== null && ($replacement === null ? $existing->isRestDay() : PlanChangeKind::of($existing, $replacement) === PlanChangeKind::NONE)) {
                $already[] = $date;

                continue;
            }

            $built[] = [
                'date' => $date,
                'existing_plan_id' => $existing?->id ?? 0,
                'existing_revision' => $existing?->revision() ?? '',
                'existing_summary' => $existing !== null ? $this->describeExisting($existing) : PlanReplacementProposal::NOTHING_PLANNED,
                'action' => $replacement === null ? PlanReplacementProposal::CLEAR : ($existing === null ? PlanReplacementProposal::CREATE : PlanReplacementProposal::REPLACE),
                'reason' => $change['reason'],
                'replacement' => $replacement,
            ];
        }

        $this->weekHandled = true;

        if ($blocked !== []) {
            $this->weekBlocked = $blocked;

            return $this->refuse($name, $capability, 'week_day_protected', 'Not done, nothing was changed for any day: '.implode(', ', array_map(fn (array $b) => $b['date'].' ('.($b['reason'] === 'started' ? 'already started' : 'already has recorded workout performance').')', $blocked))
                .' cannot be replaced, and a plan is saved whole or not at all. Tell the user which days, and that they can ask for the other days only.');
        }

        $this->weekAlready = $already;

        if ($built === []) {
            return ['note' => 'Nothing needed changing: every day of that plan is already on the user\'s plan exactly as shown. Tell the user that.', 'already_on_plan' => $already];
        }

        if ($this->conversationId === null || ! ApplicationKey::usable() || $this->replacer === null) {
            return $this->refuse($name, $capability, 'cannot_ask_for_confirmation', 'Not done, nothing was changed: saving a plan of several days needs the application\'s confirmation record and it cannot be made here.');
        }

        $needsConfirmation = array_filter($built, fn (array $c) => $c['existing_plan_id'] !== 0) !== [];

        if ($needsConfirmation) {
            // All of it waits, the empty days too: a confirmation covers exactly this set and nothing near it.
            foreach ($built as $change) {
                $this->commitHold($user, $change['date'], $change + ['unset' => $change['date'] === $built[0]['date'] ? $unset : []]);
            }

            return [
                'error' => 'Nothing was changed yet: some of those days already have a planned workout, so the application is asking the user to confirm exactly these changes. Do not say anything was saved or replaced; say that nothing has changed until the user confirms.',
                'pending_confirmation' => true,
            ];
        }

        $result = $this->replacer->apply($user, PlanReplacementProposal::create($user->id, $this->conversationId, $built));

        if (is_string($result)) {
            $this->weekProblem = $result;

            return $this->refuse($name, $capability, 'week_not_saved', 'Not done, nothing was changed: '.$result);
        }

        $this->weekSaved = $built;
        $this->workoutSaved = true;
        $this->rangeNote = $scope->rangeNote($changes);
        $this->loadsLeftUnset = [...$this->loadsLeftUnset, ...$unset];

        return [
            'saved' => array_map(fn (array $c) => ['date' => $c['date'], 'action' => $c['action']], $built),
            'already_on_plan' => $already,
            'note' => 'Every day of the plan was saved together and read back. The application will tell the user exactly what was saved; keep your reply short.',
        ];
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

    /** The replacements the application must now ask the user to confirm, as one signed proposal, if this turn held any. */
    public function pendingReplacement(): ?PlanReplacementProposal
    {
        if ($this->held === [] || $this->heldFor === null || $this->conversationId === null) {
            return null;
        }

        return $this->proposal ??= PlanReplacementProposal::create($this->heldFor, $this->conversationId, array_values($this->held));
    }

    /** @return array{date: string, was: string}|null the plan an explicit replacement request just replaced */
    public function replaced(): ?array
    {
        return $this->replaced;
    }

    /** @return array<int, array<string, mixed>>|null the changes written when a plan of several days was saved, else null */
    public function weekSaved(): ?array
    {
        return $this->weekSaved;
    }

    /** @return array<int, array{date: string, label: string, reason: string}> */
    public function weekBlocked(): array
    {
        return $this->weekBlocked;
    }

    /** @return array<int, string> */
    public function weekAlready(): array
    {
        return $this->weekAlready;
    }

    public function weekProblem(): ?string
    {
        return $this->weekProblem;
    }

    /** True once the plan of several days has been written, held, refused or found to be on the plan already. */
    public function weekHandled(): bool
    {
        return $this->weekHandled;
    }

    /** What the user is told about ranges stored as one number in the workout just saved, or null. */
    public function rangeNote(): ?string
    {
        return $this->rangeNote;
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
