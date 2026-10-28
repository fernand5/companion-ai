<?php

namespace App\Services\Ai\Authorization;

use App\Support\ApplicationKey;
use Carbon\CarbonImmutable;

/**
 * The whole-day replacements that the application showed the user and asked them to confirm: for each day, THIS
 * exact existing plan (id and content fingerprint at the moment it was shown) is replaced by THIS exact workout, or
 * cleared to a rest day. One day for "add this workout to my dashboard"; a few for a weekly adaptation.
 *
 * Same discipline as PlanChangeProposal: built by the application (the workout from a call it already checked against
 * the user's request and the load rules, the existing plan from its own records), immutable, signed, and bound to one
 * user and one conversation. A confirmation authorizes exactly these changes and nothing near them, all or none: if any
 * of the existing plans is edited, progressed, performed or removed after they were shown, the fingerprint no longer
 * matches and nothing is replaced. Whether it was used lives next to it in the stored message (PendingProposalStore).
 */
final class PlanReplacementProposal
{
    public const OPERATION = 'replace_day_plans';

    public const TTL_MINUTES = 15;

    public const MAX_DAYS = 7;

    public const REPLACE = 'replace';

    public const CLEAR = 'clear';

    private const VERSION = 2;

    private const EXERCISE_FIELDS = ['exercise_name', 'planned_sets', 'planned_reps', 'planned_weight_kg', 'planned_duration_seconds'];

    /**
     * @param  array<int, array{date: string, existing_plan_id: int, existing_revision: string, existing_summary: string, action: string, reason: string, replacement: ?array<string, mixed>}>  $changes
     */
    private function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly array $changes,
        public readonly string $createdAt,
        public readonly string $expiresAt,
        private readonly string $signature,
    ) {}

    /**
     * Each change: date, existing_plan_id, existing_revision, existing_summary, action (replace|clear) and, for a
     * replace, the create_workout_plan arguments that have already been checked.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    public static function create(int $userId, int $conversationId, array $changes, ?CarbonImmutable $now = null): self
    {
        // A proposal signed with a missing or malformed key would be a weak one: never create it.
        if (! ApplicationKey::usable()) {
            throw new \LogicException('Refusing to create a proposal: the application key is unusable.');
        }

        if ($changes === [] || count($changes) > self::MAX_DAYS) {
            throw new \LogicException('A replacement proposal covers between one and seven days.');
        }

        $now ??= CarbonImmutable::now();

        $fields = [
            'version' => self::VERSION,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'operation' => self::OPERATION,
            'changes' => self::canonicalChanges(array_map(fn (array $c) => $c + ['replacement' => null], $changes), normalise: true),
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
        foreach (['version', 'user_id', 'conversation_id', 'operation', 'changes', 'created_at', 'expires_at', 'signature'] as $key) {
            if (! array_key_exists($key, $meta)) {
                return null;
            }
        }

        if ($meta['version'] !== self::VERSION || $meta['operation'] !== self::OPERATION
            || ! is_int($meta['user_id']) || ! is_int($meta['conversation_id'])
            || ! is_array($meta['changes']) || $meta['changes'] === [] || count($meta['changes']) > self::MAX_DAYS
            || ! is_string($meta['created_at']) || ! is_string($meta['expires_at']) || ! is_string($meta['signature'])) {
            return null;
        }

        foreach ($meta['changes'] as $change) {
            if (! is_array($change) || ! is_string($change['date'] ?? null) || ! is_int($change['existing_plan_id'] ?? null)
                || ! is_string($change['existing_revision'] ?? null) || ! is_string($change['existing_summary'] ?? null)
                || ! in_array($change['action'] ?? null, [self::REPLACE, self::CLEAR], true)
                || (($change['action'] === self::REPLACE) !== is_array($change['replacement'] ?? null))) {
                return null;
            }
        }

        $fields = [
            'version' => $meta['version'],
            'user_id' => $meta['user_id'],
            'conversation_id' => $meta['conversation_id'],
            'operation' => $meta['operation'],
            // The database may reorder keys and drop a trailing ".0": rebuild it in the one shape it was signed in.
            'changes' => self::canonicalChanges($meta['changes'], normalise: false),
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

    /**
     * The arguments a change is written from (a create_workout_plan call), with the day set by the application.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function arguments(array $change): array
    {
        return ($change['replacement'] ?? []) + ['planned_date' => $change['date']];
    }

    /**
     * What the user is shown for one change, in the application's own words.
     *
     * @param  array<string, mixed>  $change
     */
    public static function describeChange(array $change): string
    {
        return $change['action'] === self::CLEAR
            ? "{$change['date']}: {$change['existing_summary']} would become a rest day"
            : "{$change['date']}: {$change['existing_summary']} would be replaced with ".self::summarise($change['replacement']);
    }

    /**
     * "“Upper body” (Push-ups 3×12, Plank 30 s)"
     *
     * @param  array<string, mixed>  $plan
     */
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
     * The one shape the changes are signed and stored in: ordered by day, fixed key order, whole numbers as integers.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    private static function canonicalChanges(array $changes, bool $normalise): array
    {
        $out = [];

        foreach ($changes as $change) {
            $out[] = [
                'date' => (string) $change['date'],
                'existing_plan_id' => (int) $change['existing_plan_id'],
                'existing_revision' => (string) $change['existing_revision'],
                'existing_summary' => (string) $change['existing_summary'],
                'action' => (string) $change['action'],
                'reason' => mb_substr((string) ($change['reason'] ?? ''), 0, 500),
                'replacement' => ($change['replacement'] ?? null) === null ? null : self::canonicalPlan($normalise ? self::fromArguments($change['replacement']) : $change['replacement']),
            ];
        }

        usort($out, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * @param  array<string, mixed>  $arguments  a create_workout_plan call
     * @return array<string, mixed>
     */
    private static function fromArguments(array $arguments): array
    {
        return [
            'activity_type' => $arguments['activity_type'] ?? '',
            'title' => self::clean((string) ($arguments['title'] ?? '')),
            'duration_minutes' => $arguments['duration_minutes'] ?? null,
            'reasoning' => $arguments['decision_summary'] ?? $arguments['reasoning'] ?? $arguments['reason'] ?? null,
            'reasoning_factors' => array_slice((array) ($arguments['reasoning_factors'] ?? []), 0, 6),
            'exercises' => array_map(fn ($e) => ['exercise_name' => self::clean((string) (((array) $e)['exercise_name'] ?? ''))] + (array) $e, (array) ($arguments['exercises'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function canonicalPlan(array $plan): array
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
            'reasoning' => isset($plan['reasoning']) ? mb_substr((string) $plan['reasoning'], 0, 1000) : null,
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
            'changes' => $this->changes,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $fields */
    private static function fromFields(array $fields, string $signature): self
    {
        return new self($fields['user_id'], $fields['conversation_id'], $fields['changes'], $fields['created_at'], $fields['expires_at'], $signature);
    }

    /** @param array<string, mixed> $fields */
    private static function sign(array $fields): string
    {
        return hash_hmac('sha256', (string) json_encode($fields, JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }
}
