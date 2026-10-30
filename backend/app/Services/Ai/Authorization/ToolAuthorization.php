<?php

namespace App\Services\Ai\Authorization;

/**
 * What one chat turn may do, fixed BEFORE the model is called and never changed
 * by anything the model or a tool says. It is built only from the user's own
 * message and from state the application stored itself (a pending confirmation
 * the application asked for), never from model output.
 */
final class ToolAuthorization
{
    /** @var array<int, ToolCapability> */
    private array $granted;

    /**
     * @param  array<int, ToolCapability>  $granted  plan capabilities granted (Read and Record are always allowed)
     * @param  string  $basis  why, as a short code for logs and tests
     * @param  string|null  $followUp  what the application itself should say after the reply (one of the FOLLOW_UP_* codes)
     * @param  PlanChangeProposal|null  $scope  when set, the plan write is limited to exactly this confirmed change
     * @param  bool  $mayRaiseLoads  true only when the user explicitly asked for (or confirmed) the change; every other
     *                               write is held to the baseline in PlanLoadGuard, so loads only go up through approval
     * @param  array<int, float>  $statedLoadsKg  loads (kg) the user wrote in the instruction that authorized the write; the only
     *                                            numbers that may start an exercise that has no baseline
     * @param  SaveWorkoutScope|null  $saveScope  when set, the plan write is limited to saving exactly this workout on exactly this day
     * @param  array<string, mixed>  $followUpData  what the follow-up question needs to say (the offered exercises, the day), stored with the application's question
     */
    public function __construct(
        array $granted,
        public readonly string $basis,
        public readonly ?string $followUp = null,
        private readonly ?PlanChangeProposal $scope = null,
        public readonly bool $mayRaiseLoads = false,
        public readonly array $statedLoadsKg = [],
        public readonly ?SaveWorkoutScope $saveScope = null,
        public readonly array $followUpData = [],
    ) {
        $this->granted = array_values(array_unique([ToolCapability::Read, ToolCapability::Record, ...$granted], SORT_REGULAR));
    }

    /** The Weekly Plan adaptation box: the user typed what changed, which authorizes replacing the days it affects. */
    public const ENDPOINT_WEEKLY_ADAPT = 'weekly_plan';

    /** "Generate my week": fills days that have no plan, and replaces nothing. */
    public const ENDPOINT_WEEKLY_GENERATE = 'weekly_plan_generate';

    /** The user seems to want a change they did not clearly ask for: the application tries to turn it into one precise proposal. */
    public const FOLLOW_UP_PROPOSE = 'propose';

    /** Something that might change the plan was mentioned but cannot be confirmed with a "yes": ask for an explicit request. */
    public const FOLLOW_UP_EXPLICIT_REQUEST = 'explicit_request';

    /** The user asked to put "this workout" on the plan, but nothing in the conversation describes one. */
    public const FOLLOW_UP_NO_WORKOUT_TO_SAVE = 'no_workout_to_save';

    /** More than one workout could be meant, and the user's words do not pick one. */
    public const FOLLOW_UP_WHICH_WORKOUT = 'which_workout';

    /** An exercise in the workout is "A or B" and the user has not said which. */
    public const FOLLOW_UP_CHOOSE_EXERCISE = 'choose_exercise';

    /** The workout in the conversation is visibly unfinished. */
    public const FOLLOW_UP_INCOMPLETE_WORKOUT = 'incomplete_workout';

    /** The workout is somebody else's words, quoted. */
    public const FOLLOW_UP_QUOTED_WORKOUT = 'quoted_workout';

    /** The day to put the workout on is unclear, already past, or named twice. */
    public const FOLLOW_UP_WHICH_DAY = 'which_day';

    /** A bare "yes" with nothing valid waiting for it. */
    /** A retry or answer to a request that is too old, or from another day, to be replayed. */
    public const FOLLOW_UP_RETRY_EXPIRED = 'retry_expired';

    public const FOLLOW_UP_NOTHING_PENDING = 'nothing_pending';

    /** Questions and everything else the user has not asked to turn into a plan change. */
    public static function readOnly(string $basis = 'read_only', ?string $followUp = null, array $followUpData = []): self
    {
        return new self([], $basis, $followUp, followUpData: $followUpData);
    }

    /**
     * A "yes" that redeemed a pending proposal: ONE plan write, and only the exact change
     * that was shown. Nothing else is granted (no weekly changes, no marking exercises done).
     */
    public static function confirmedProposal(PlanChangeProposal $proposal): self
    {
        return new self([ToolCapability::PlanCreate], 'confirmed_proposal', null, $proposal, mayRaiseLoads: true);
    }

    /** The change this authorization is limited to, or null when it is not a scoped one. */
    public function scopedProposal(): ?PlanChangeProposal
    {
        return $this->scope;
    }

    /**
     * An endpoint whose whole purpose is the plan change, called deliberately by the
     * user (the Weekly Plan page). The endpoint keeps its own tool allow-list; this
     * only records that the request itself is the authorization.
     */
    public static function userInvokedEndpoint(string $endpoint, ToolCapability ...$capabilities): self
    {
        return new self($capabilities, "endpoint:{$endpoint}");
    }

    public function permits(ToolCapability $capability): bool
    {
        return in_array($capability, $this->granted, true);
    }

    /** True when at least one capability that changes a plan is granted. */
    public function allowsAnyPlanChange(): bool
    {
        foreach ($this->granted as $capability) {
            if ($capability->needsAuthorization()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function grantedPlanCapabilities(): array
    {
        return array_values(array_map(
            fn (ToolCapability $c) => $c->value,
            array_filter($this->granted, fn (ToolCapability $c) => $c->needsAuthorization()),
        ));
    }
}
