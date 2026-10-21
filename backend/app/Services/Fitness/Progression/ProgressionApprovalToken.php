<?php

namespace App\Services\Fitness\Progression;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The exact operation a user is asked to approve, sealed so the client can carry it but
 * neither read nor alter it: encrypted and authenticated with the application key (an
 * altered, truncated or foreign string fails to open). The client receives an opaque string
 * and sends it back; it never supplies a load, an exercise or a plan, so nothing it sends is
 * authority for anything beyond what this token says.
 *
 * It binds one user, one exact plan row and the revision of it that was read, the load it
 * moves from and to, and a fingerprint of the evidence behind it. Being stateless, issuing
 * one writes nothing; using one is a single atomic change (see ProgressionApprovalService).
 */
final class ProgressionApprovalToken
{
    public const TTL_MINUTES = 30;

    private const PURPOSE = 'progression_approval';

    private const VERSION = 1;

    private function __construct(
        public readonly int $userId,
        public readonly int $planId,
        public readonly int $planExerciseId,
        public readonly string $rowRevision,
        public readonly string $date,
        public readonly string $exerciseSlug,
        public readonly string $exerciseName,
        public readonly string $decision,
        public readonly float $fromKg,
        public readonly float $toKg,
        public readonly string $evidence,
        public readonly string $policyVersion,
        public readonly string $issuedAt,
        public readonly string $expiresAt,
    ) {}

    public static function issue(
        int $userId,
        int $planId,
        int $planExerciseId,
        string $rowRevision,
        string $date,
        string $exerciseSlug,
        string $exerciseName,
        string $decision,
        float $fromKg,
        float $toKg,
        string $evidence,
        ?CarbonImmutable $now = null,
    ): self {
        $now ??= CarbonImmutable::now();

        return new self(
            $userId, $planId, $planExerciseId, $rowRevision, $date, $exerciseSlug, $exerciseName, $decision,
            round($fromKg, 2), round($toKg, 2), $evidence, ProgressionPolicy::VERSION,
            $now->toIso8601String(), $now->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        );
    }

    /** The opaque string handed to the client. */
    public function seal(): string
    {
        return Crypt::encryptString((string) json_encode([
            'purpose' => self::PURPOSE,
            'v' => self::VERSION,
            'user_id' => $this->userId,
            'plan_id' => $this->planId,
            'plan_exercise_id' => $this->planExerciseId,
            'row_revision' => $this->rowRevision,
            'date' => $this->date,
            'exercise_slug' => $this->exerciseSlug,
            'exercise_name' => $this->exerciseName,
            'decision' => $this->decision,
            'from_kg' => $this->fromKg,
            'to_kg' => $this->toKg,
            'evidence' => $this->evidence,
            'policy' => $this->policyVersion,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
        ], JSON_PRESERVE_ZERO_FRACTION));
    }

    /** The token behind a submitted string, or null if it is anything other than one this application sealed. */
    public static function open(string $sealed): ?self
    {
        try {
            $data = json_decode(Crypt::decryptString($sealed), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['purpose'] ?? null) !== self::PURPOSE || ($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        foreach (['user_id', 'plan_id', 'plan_exercise_id'] as $int) {
            if (! is_int($data[$int] ?? null)) {
                return null;
            }
        }

        foreach (['row_revision', 'date', 'exercise_slug', 'exercise_name', 'decision', 'evidence', 'policy', 'issued_at', 'expires_at'] as $string) {
            if (! is_string($data[$string] ?? null)) {
                return null;
            }
        }

        if (! is_numeric($data['from_kg'] ?? null) || ! is_numeric($data['to_kg'] ?? null)) {
            return null;
        }

        return new self(
            $data['user_id'], $data['plan_id'], $data['plan_exercise_id'], $data['row_revision'], $data['date'], $data['exercise_slug'],
            $data['exercise_name'], $data['decision'], (float) $data['from_kg'], (float) $data['to_kg'], $data['evidence'], $data['policy'],
            $data['issued_at'], $data['expires_at'],
        );
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo(CarbonImmutable::parse($this->expiresAt));
    }

    /** A stable key for "this exact proposal was used". */
    public function useKey(): string
    {
        return 'progression-approval:'.hash('sha256', implode('|', [$this->userId, $this->planExerciseId, $this->rowRevision, $this->fromKg, $this->toKg, $this->issuedAt]));
    }
}
