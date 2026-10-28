<?php

namespace App\Services\Ai\Authorization;

use App\Support\ApplicationKey;
use Carbon\CarbonImmutable;

/**
 * ONE whole-day replacement that the application showed the user and asked them to confirm: this exact plan
 * (id, day and content fingerprint at the moment it was shown) is replaced by this exact workout.
 *
 * Same discipline as PlanChangeProposal: built by the application (the workout from a call it already checked
 * against the user's request and the load rules, the existing plan from its own records), immutable, signed, and
 * bound to one user and one conversation. A confirmation authorizes exactly this and nothing near it: if the
 * existing plan is edited, progressed, performed or removed after it was shown, the fingerprint no longer matches
 * and nothing is replaced. Whether it was used lives next to it in the stored message (PendingProposalStore).
 */
final class PlanReplacementProposal
{
    public const OPERATION = 'replace_day_plan';

    public const TTL_MINUTES = 15;

    private const VERSION = 1;

    private const EXERCISE_FIELDS = ['exercise_name', 'planned_sets', 'planned_reps', 'planned_weight_kg', 'planned_duration_seconds'];

    /** @param array<string, mixed> $replacement activity_type, title, duration_minutes, reasoning, reasoning_factors, exercises */
    private function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly string $date,
        public readonly int $existingPlanId,
        public readonly string $existingRevision,
        public readonly string $existingSummary,
        public readonly array $replacement,
        public readonly string $createdAt,
        public readonly string $expiresAt,
        private readonly string $signature,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  a create_workout_plan call that has already been checked
     */
    public static function create(
        int $userId,
        int $conversationId,
        string $date,
        int $existingPlanId,
        string $existingRevision,
        string $existingSummary,
        array $arguments,
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
            'operation' => self::OPERATION,
            'date' => $date,
            'existing_plan_id' => $existingPlanId,
            'existing_revision' => $existingRevision,
            'existing_summary' => $existingSummary,
            'replacement' => self::normalise($arguments),
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        ];

        return self::fromFields($fields, self::sign($fields));
    }

    /**
     * The stored form. Null for anything malformed; the signature is checked separately (isAuthentic()).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromMeta(array $meta): ?self
    {
        foreach (['version', 'user_id', 'conversation_id', 'operation', 'date', 'existing_plan_id', 'existing_revision', 'existing_summary', 'replacement', 'created_at', 'expires_at', 'signature'] as $key) {
            if (! array_key_exists($key, $meta)) {
                return null;
            }
        }

        if ($meta['version'] !== self::VERSION || $meta['operation'] !== self::OPERATION
            || ! is_int($meta['user_id']) || ! is_int($meta['conversation_id']) || ! is_int($meta['existing_plan_id'])
            || ! is_string($meta['date']) || ! is_string($meta['existing_revision']) || ! is_string($meta['existing_summary'])
            || ! is_array($meta['replacement'])
            || ! is_string($meta['created_at']) || ! is_string($meta['expires_at']) || ! is_string($meta['signature'])) {
            return null;
        }

        $fields = [
            'version' => $meta['version'],
            'user_id' => $meta['user_id'],
            'conversation_id' => $meta['conversation_id'],
            'operation' => $meta['operation'],
            'date' => $meta['date'],
            'existing_plan_id' => $meta['existing_plan_id'],
            'existing_revision' => $meta['existing_revision'],
            'existing_summary' => $meta['existing_summary'],
            // The database may reorder keys and drop a trailing ".0": rebuild it in the one shape it was signed in.
            'replacement' => self::canonical($meta['replacement']),
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

    /** The arguments the replacement is written from (a create_workout_plan call), with the day set by the application. */
    public function arguments(): array
    {
        return $this->replacement + ['planned_date' => $this->date];
    }

    /** What the user is shown, in the application's own words. */
    public function describe(): string
    {
        return sprintf(
            '%s on %s with %s',
            $this->existingSummary,
            $this->date,
            self::summarise($this->replacement),
        );
    }

    /** "“Upper body” (Push-ups 3×12, Plank 3×30 s)" */
    public static function summarise(array $plan): string
    {
        $exercises = array_map(function (array $e): string {
            $amount = isset($e['planned_sets'], $e['planned_reps']) ? " {$e['planned_sets']}×{$e['planned_reps']}" : (isset($e['planned_duration_seconds']) ? " {$e['planned_duration_seconds']} s" : '');

            return $e['exercise_name'].$amount;
        }, $plan['exercises'] ?? []);

        $title = '“'.self::clean((string) ($plan['title'] ?? 'Workout')).'”';

        return $exercises === [] ? $title : $title.' ('.implode(', ', array_slice($exercises, 0, 8)).(count($exercises) > 8 ? ', …' : '').')';
    }

    /** A line of text safe to put in the application's own message: no markup, one line, bounded. */
    public static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79).'…' : $text;
    }

    /**
     * Only the fields a plan is written from, in a fixed shape, so what is signed is exactly what is applied.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function normalise(array $arguments): array
    {
        return self::canonical([
            'activity_type' => $arguments['activity_type'] ?? '',
            'title' => self::clean((string) ($arguments['title'] ?? '')),
            'duration_minutes' => $arguments['duration_minutes'] ?? null,
            'reasoning' => isset($arguments['decision_summary']) ? mb_substr((string) $arguments['decision_summary'], 0, 1000) : null,
            'reasoning_factors' => array_slice((array) ($arguments['reasoning_factors'] ?? []), 0, 6),
            'exercises' => array_map(fn ($e) => ['exercise_name' => self::clean((string) (((array) $e)['exercise_name'] ?? ''))] + (array) $e, (array) ($arguments['exercises'] ?? [])),
        ]);
    }

    /**
     * The one shape a replacement is signed and stored in: fixed key order, whole numbers as integers.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function canonical(array $plan): array
    {
        $number = fn (mixed $v) => is_numeric($v) ? (floor((float) $v) === round((float) $v, 2) ? (int) $v : round((float) $v, 2)) : null;

        $exercises = [];

        foreach ((array) ($plan['exercises'] ?? []) as $exercise) {
            $exercise = (array) $exercise;
            $row = [];

            foreach (self::EXERCISE_FIELDS as $field) {
                $row[$field] = $field === 'exercise_name' ? (string) ($exercise[$field] ?? '') : $number($exercise[$field] ?? null);
            }

            $exercises[] = $row;
        }

        return [
            'activity_type' => (string) ($plan['activity_type'] ?? ''),
            'title' => (string) ($plan['title'] ?? ''),
            'duration_minutes' => $number($plan['duration_minutes'] ?? null),
            'reasoning' => isset($plan['reasoning']) ? (string) $plan['reasoning'] : null,
            'reasoning_factors' => array_values(array_map(fn ($f) => mb_substr((string) $f, 0, 255), (array) ($plan['reasoning_factors'] ?? []))),
            'exercises' => $exercises,
        ];
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return [
            'version' => self::VERSION,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'operation' => self::OPERATION,
            'date' => $this->date,
            'existing_plan_id' => $this->existingPlanId,
            'existing_revision' => $this->existingRevision,
            'existing_summary' => $this->existingSummary,
            'replacement' => $this->replacement,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $fields */
    private static function fromFields(array $fields, string $signature): self
    {
        return new self(
            $fields['user_id'], $fields['conversation_id'], $fields['date'], $fields['existing_plan_id'], $fields['existing_revision'],
            $fields['existing_summary'], $fields['replacement'], $fields['created_at'], $fields['expires_at'], $signature,
        );
    }

    /** @param array<string, mixed> $fields */
    private static function sign(array $fields): string
    {
        return hash_hmac('sha256', (string) json_encode($fields, JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }
}
