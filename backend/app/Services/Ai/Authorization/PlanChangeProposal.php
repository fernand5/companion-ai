<?php

namespace App\Services\Ai\Authorization;

use App\Support\ApplicationKey;
use Carbon\CarbonImmutable;

/**
 * ONE specific plan change that the application showed the user and asked them to
 * confirm: set one exact exercise, in one exact plan on one exact day, to one
 * exact load (and sets/reps only if the user named them).
 *
 * It is immutable and signed. The application builds it from the USER's own words
 * and its own records, never from anything the model wrote, and the model cannot
 * enlarge it: a confirmation authorizes exactly this and nothing near it (see
 * ProposalMatcher). Whether it has been used lives next to it in the stored message
 * (see PendingProposalStore), outside the signed fields.
 */
final class PlanChangeProposal
{
    public const OPERATION_SET_EXERCISE_TARGET = 'set_exercise_target';

    public const TTL_MINUTES = 15;

    private const VERSION = 1;

    private function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly string $operation,
        public readonly int $planId,
        public readonly int $planExerciseId,
        public readonly string $date,
        public readonly string $exerciseSlug,
        public readonly string $exerciseName,
        public readonly float $plannedWeightKg,
        public readonly ?int $plannedSets,
        public readonly ?int $plannedReps,
        public readonly string $createdAt,
        public readonly string $expiresAt,
        private readonly string $signature,
    ) {}

    public static function create(
        int $userId,
        int $conversationId,
        int $planId,
        int $planExerciseId,
        string $date,
        string $exerciseSlug,
        string $exerciseName,
        float $plannedWeightKg,
        ?int $plannedSets,
        ?int $plannedReps,
        ?CarbonImmutable $now = null,
    ): self {
        // A proposal signed with a missing or malformed key would be a weak one: never create it.
        if (! ApplicationKey::usable()) {
            throw new \LogicException('Refusing to create a proposal: the application key is unusable.');
        }

        $now ??= CarbonImmutable::now();

        $fields = [
            'version' => self::VERSION,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'operation' => self::OPERATION_SET_EXERCISE_TARGET,
            'plan_id' => $planId,
            'plan_exercise_id' => $planExerciseId,
            'date' => $date,
            'exercise_slug' => $exerciseSlug,
            'exercise_name' => $exerciseName,
            'planned_weight_kg' => round($plannedWeightKg, 2),
            'planned_sets' => $plannedSets,
            'planned_reps' => $plannedReps,
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        ];

        return self::fromFields($fields, self::sign($fields));
    }

    /**
     * The stored form. Returns null for anything malformed; the signature is checked
     * separately (isAuthentic()) so a tampered record can be told from a broken one.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromMeta(array $meta): ?self
    {
        $required = ['version', 'user_id', 'conversation_id', 'operation', 'plan_id', 'plan_exercise_id', 'date', 'exercise_slug', 'exercise_name', 'planned_weight_kg', 'created_at', 'expires_at', 'signature'];

        foreach ($required as $key) {
            if (! array_key_exists($key, $meta)) {
                return null;
            }
        }

        if (($meta['version'] ?? null) !== self::VERSION
            || ($meta['operation'] ?? null) !== self::OPERATION_SET_EXERCISE_TARGET
            || ! is_int($meta['user_id']) || ! is_int($meta['conversation_id']) || ! is_int($meta['plan_id']) || ! is_int($meta['plan_exercise_id'])
            || ! is_string($meta['date']) || ! is_string($meta['exercise_slug']) || ! is_string($meta['exercise_name'])
            || ! is_numeric($meta['planned_weight_kg'])
            || ! is_string($meta['created_at']) || ! is_string($meta['expires_at']) || ! is_string($meta['signature'])
            || ($meta['planned_sets'] ?? null) !== null && ! is_int($meta['planned_sets'])
            || ($meta['planned_reps'] ?? null) !== null && ! is_int($meta['planned_reps'])) {
            return null;
        }

        $fields = [
            'version' => $meta['version'],
            'user_id' => $meta['user_id'],
            'conversation_id' => $meta['conversation_id'],
            'operation' => $meta['operation'],
            'plan_id' => $meta['plan_id'],
            'plan_exercise_id' => $meta['plan_exercise_id'],
            'date' => $meta['date'],
            'exercise_slug' => $meta['exercise_slug'],
            'exercise_name' => $meta['exercise_name'],
            'planned_weight_kg' => round((float) $meta['planned_weight_kg'], 2),
            'planned_sets' => $meta['planned_sets'] ?? null,
            'planned_reps' => $meta['planned_reps'] ?? null,
            'created_at' => $meta['created_at'],
            'expires_at' => $meta['expires_at'],
        ];

        return self::fromFields($fields, $meta['signature']);
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        return $this->fields() + ['signature' => $this->signature];
    }

    /** True only if every signed field is exactly as the application wrote it. */
    public function isAuthentic(): bool
    {
        return ApplicationKey::usable() && hash_equals(self::sign($this->fields()), $this->signature);
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo(CarbonImmutable::parse($this->expiresAt));
    }

    /** What the user is shown, in the application's own words. */
    public function describe(): string
    {
        return sprintf(
            'set %s to %s kg%s on %s',
            $this->exerciseName,
            rtrim(rtrim(number_format($this->plannedWeightKg, 2, '.', ''), '0'), '.'),
            $this->plannedSets !== null && $this->plannedReps !== null ? " ({$this->plannedSets}×{$this->plannedReps})" : '',
            $this->date,
        );
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return [
            'version' => self::VERSION,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'operation' => $this->operation,
            'plan_id' => $this->planId,
            'plan_exercise_id' => $this->planExerciseId,
            'date' => $this->date,
            'exercise_slug' => $this->exerciseSlug,
            'exercise_name' => $this->exerciseName,
            'planned_weight_kg' => $this->plannedWeightKg,
            'planned_sets' => $this->plannedSets,
            'planned_reps' => $this->plannedReps,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $fields */
    private static function fromFields(array $fields, string $signature): self
    {
        return new self(
            $fields['user_id'], $fields['conversation_id'], $fields['operation'], $fields['plan_id'], $fields['plan_exercise_id'],
            $fields['date'], $fields['exercise_slug'], $fields['exercise_name'], (float) $fields['planned_weight_kg'],
            $fields['planned_sets'], $fields['planned_reps'], $fields['created_at'], $fields['expires_at'], $signature,
        );
    }

    /** @param array<string, mixed> $fields */
    private static function sign(array $fields): string
    {
        return hash_hmac('sha256', (string) json_encode($fields, JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }
}
