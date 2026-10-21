<?php

namespace App\Services\Fitness\Progression;

use App\Models\ProgressionDismissal;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PlanLoadGuard;
use App\Services\Fitness\WorkoutPlanService;
use App\Support\ExerciseSlug;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns calculated recommendations into user-approved changes to ONE future target.
 *
 * It owns every decision itself; the browser is only a way to ask. Showing recommendations
 * writes nothing. Approving takes nothing from the client but the sealed proposal the server
 * issued, then:
 *   1. opens it (altered, foreign or stale-formatted proposals do not open) and checks it is
 *      this user's and unexpired;
 *   2. recomputes the recommendation from the current record and requires it to be exactly
 *      what was issued: same decision, same loads, same evidence, same plan row at the same
 *      revision. Anything that moved (a new session, a pain note, an edited plan, a changed
 *      policy) makes it stale and a fresh recommendation is needed;
 *   3. re-checks eligibility and the maximum permitted change on its own;
 *   4. spends the proposal atomically, then changes that one row with a single conditional
 *      UPDATE, so of any number of concurrent approvals at most one has any effect.
 * Only the planned load of that one future exercise changes. Recorded performance, historical
 * snapshots, completed workouts, other exercises and other days are never touched.
 */
class ProgressionApprovalService
{
    public const MAX_EXERCISES = 8;

    public function __construct(
        private readonly ProgressionService $progression,
        private readonly PlanLoadGuard $loadGuard,
        private readonly WorkoutPlanService $plans,
    ) {}

    /**
     * What the Weekly Plan screen shows. Read-only; each actionable item carries the sealed
     * proposal the user may approve, built here and nowhere else.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function overview(User $user): array
    {
        $now = CarbonImmutable::now();
        $items = [];
        $entries = $this->progression->overview($user, self::MAX_EXERCISES);
        $dismissals = ProgressionDismissal::query()
            ->where('user_id', $user->id)
            ->whereIn('workout_plan_exercise_id', array_filter(array_map(fn (array $e) => $e['row']?->id, $entries)))
            ->get()
            ->keyBy('workout_plan_exercise_id');

        foreach ($entries as ['recommendation' => $recommendation, 'row' => $row]) {
            $blocked = $this->whyNotActionable($user, $recommendation, $row);
            $dismissal = null;

            // Declined, and nothing about it has changed since: shown as kept, not offered again.
            if ($blocked === null && ($found = $dismissals->get($row->id)) !== null
                && hash_equals($found->fingerprint, $this->dismissalKey((int) $row->id, (float) $row->planned_weight_kg, $this->evidenceFingerprint($recommendation)))) {
                $blocked = 'dismissed';
                $dismissal = ['at' => $found->dismissed_at->toIso8601String()];
            }

            $proposal = null;

            if ($blocked === null) {
                $token = ProgressionApprovalToken::issue(
                    $user->id, (int) $row->workout_plan_id, (int) $row->id, (string) $row->getRawOriginal('updated_at'),
                    substr((string) $row->plan_date, 0, 10), (string) $recommendation->exerciseSlug, $recommendation->exerciseName,
                    $recommendation->decision->value, (float) $row->planned_weight_kg, (float) $recommendation->proposedTarget['to'],
                    $this->evidenceFingerprint($recommendation), $now,
                );
                $proposal = ['id' => $token->seal(), 'expires_at' => $token->expiresAt];
            }

            $items[] = $recommendation->toArray() + [
                'applies_to' => $row !== null ? ['date' => substr((string) $row->plan_date, 0, 10)] : null,
                'actionable' => $blocked === null,
                'not_actionable_reason' => $blocked,
                'dismissed' => $dismissal,
                'proposal' => $proposal,
            ];
        }

        return ['data' => $items, 'meta' => ['policy_version' => ProgressionPolicy::VERSION, 'max_exercises' => self::MAX_EXERCISES, 'generated_at' => $now->toIso8601String()]];
    }

    /**
     * Apply an approved proposal.
     *
     * @return array<string, mixed> what was changed
     *
     * @throws ProgressionApprovalException
     */
    public function approve(User $user, string $sealed): array
    {
        $token = $this->open($user, $sealed);

        if ($token->isExpired()) {
            throw ProgressionApprovalException::expired();
        }

        ['recommendation' => $recommendation, 'row' => $row] = $this->progression->overviewFor($user, $token->exerciseName);

        // 2. Exactly what was issued, on exactly the row that was read.
        $stillTheSame = ExerciseSlug::from($token->exerciseName) === $token->exerciseSlug
            && $row !== null
            && $row->id === $token->planExerciseId
            && $row->workout_plan_id === $token->planId
            && (string) $row->getRawOriginal('updated_at') === $token->rowRevision
            && $row->planned_weight_kg !== null
            && abs((float) $row->planned_weight_kg - $token->fromKg) < 1e-9
            && $recommendation->decision->value === $token->decision
            && $recommendation->proposedTarget !== null
            && abs((float) $recommendation->proposedTarget['to'] - $token->toKg) < 1e-9
            && $token->policyVersion === ProgressionPolicy::VERSION
            && $this->evidenceFingerprint($recommendation) === $token->evidence;

        if (! $stillTheSame) {
            throw ProgressionApprovalException::stale();
        }

        // 3. Eligibility and the maximum permitted change, decided here again, not trusted from issue time.
        if (($blocked = $this->whyNotActionable($user, $recommendation, $row)) !== null) {
            throw ProgressionApprovalException::ineligible($blocked);
        }

        // The user already said "keep it" to exactly this recommendation (say, in another tab): that stands.
        $dismissed = ProgressionDismissal::query()->where('user_id', $user->id)->where('workout_plan_exercise_id', $token->planExerciseId)->value('fingerprint');

        if ($dismissed !== null && hash_equals($dismissed, $this->dismissalKey($token->planExerciseId, $token->fromKg, $token->evidence))) {
            throw ProgressionApprovalException::dismissed();
        }

        // 4. Spend it. Whatever happens next, this proposal cannot be used again.
        if (! Cache::add($token->useKey(), 1, CarbonImmutable::now()->addMinutes(ProgressionApprovalToken::TTL_MINUTES + 5))) {
            throw ProgressionApprovalException::alreadyUsed();
        }

        $applied = $this->plans->applyApprovedLoad($user, $token->planId, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision);

        if (! $applied) {
            throw ProgressionApprovalException::stale();
        }

        Log::info('progression.approved', [
            'user_id' => $user->id,
            'plan_exercise_id' => $token->planExerciseId,
            'decision' => $token->decision,
            'policy' => $token->policyVersion,
        ]);

        return [
            'applied' => true,
            'exercise' => ['name' => $token->exerciseName, 'slug' => $token->exerciseSlug],
            'date' => $token->date,
            'from_kg' => $token->fromKg,
            'to_kg' => $token->toKg,
        ];
    }

    /**
     * "Keep current target": remember, durably, that the user declined THIS recommendation for THIS
     * plan row. The plan is not touched. It is only recorded if the proposal is still exactly what
     * the server would recommend now (otherwise there is nothing current to decline: refresh).
     * Repeating it, or two requests at once, writes the same single record (one atomic upsert).
     *
     * @throws ProgressionApprovalException
     */
    public function decline(User $user, string $sealed): void
    {
        $token = $this->open($user, $sealed);

        ['recommendation' => $recommendation, 'row' => $row] = $this->progression->overviewFor($user, $token->exerciseName);

        $current = $row !== null
            && ExerciseSlug::from($token->exerciseName) === $token->exerciseSlug
            && $row->id === $token->planExerciseId
            && $row->workout_plan_id === $token->planId
            && $row->planned_weight_kg !== null
            && abs((float) $row->planned_weight_kg - $token->fromKg) < 1e-9
            && $token->policyVersion === ProgressionPolicy::VERSION
            && $this->evidenceFingerprint($recommendation) === $token->evidence;

        if (! $current) {
            throw ProgressionApprovalException::stale();
        }

        $now = CarbonImmutable::now();

        ProgressionDismissal::query()->upsert(
            [[
                'user_id' => $user->id,
                'workout_plan_exercise_id' => $token->planExerciseId,
                'exercise_slug' => $token->exerciseSlug,
                'fingerprint' => $this->dismissalKey($token->planExerciseId, $token->fromKg, $token->evidence),
                'dismissed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'workout_plan_exercise_id'],
            ['exercise_slug', 'fingerprint', 'dismissed_at', 'updated_at'],
        );

        Log::info('progression.declined', ['user_id' => $user->id, 'plan_exercise_id' => $token->planExerciseId, 'decision' => $token->decision]);
    }

    /** @throws ProgressionApprovalException */
    private function open(User $user, string $sealed): ProgressionApprovalToken
    {
        $token = ProgressionApprovalToken::open($sealed) ?? throw ProgressionApprovalException::invalid();

        if ($token->userId !== $user->id) {
            throw ProgressionApprovalException::notYours();
        }

        return $token;
    }

    /**
     * Null when the recommendation can be applied; otherwise a code for why it cannot. The same rules
     * decide whether a proposal is issued and whether it is applied.
     */
    private function whyNotActionable(User $user, ProgressionRecommendation $r, ?WorkoutPlanExercise $row): ?string
    {
        if (! $r->decision->proposesChange() || $r->proposedTarget === null) {
            return 'not_a_change';
        }

        if ($row === null || ($r->currentTarget['source'] ?? null) !== 'next_planned') {
            return 'no_upcoming_session';
        }

        if ($row->planned_weight_kg === null || $r->exerciseSlug === null || $row->exercise_slug !== $r->exerciseSlug) {
            return 'no_load_in_plan';
        }

        $plan = $row->workoutPlan;

        if ($row->status !== WorkoutPlanExercise::STATUS_PENDING
            || $plan === null
            || $plan->user_id !== $user->id
            || ! in_array($plan->status, [WorkoutPlan::STATUS_PLANNED, WorkoutPlan::STATUS_IN_PROGRESS], true)
            || $plan->hasRecordedPerformance()) {
            return 'workout_already_started';
        }

        $current = (float) $row->planned_weight_kg;
        $to = (float) $r->proposedTarget['to'];

        if ($r->decision === ProgressionDecision::Increase) {
            if ($to <= $current) {
                return 'plan_already_at_or_above';
            }

            $limit = $this->loadGuard->limitFor($user, $r->exerciseSlug);

            if ($limit !== null && $to > $limit['maximum_kg'] + 1e-9) {
                return 'above_maximum_change';
            }
        } elseif ($to >= $current) {
            return 'plan_already_at_or_below';
        }

        return null;
    }

    /**
     * What a dismissal is bound to: the exact plan row, the load it carries now, and the whole
     * recommendation (decision, loads, policy and the sessions behind it). A replaced plan is a new
     * row; an edited load, a new session or a new policy changes one of the others. Any of those
     * makes an old dismissal stop matching, so a materially new recommendation is offered afresh.
     */
    private function dismissalKey(int $planExerciseId, float $currentLoadKg, string $evidence): string
    {
        return hash('sha256', (string) json_encode([$planExerciseId, round($currentLoadKg, 2), $evidence]));
    }

    /** The decision, the numbers, the policy and the sessions behind them, as one comparable value. */
    private function evidenceFingerprint(ProgressionRecommendation $r): string
    {
        return hash('sha256', (string) json_encode([
            $r->decision->value,
            $r->proposedTarget['from'] ?? null,
            $r->proposedTarget['to'] ?? null,
            ProgressionPolicy::VERSION,
            array_map(fn (array $s) => [$s['workout_session_id'], $s['recorded_as'], $s['performed'], $s['target_reached']], $r->supportingSessions),
        ]));
    }
}
