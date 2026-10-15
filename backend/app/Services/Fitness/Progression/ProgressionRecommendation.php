<?php

namespace App\Services\Fitness\Progression;

/**
 * A calculated recommendation about ONE exact exercise. It is data: it changes
 * no plan and nothing is applied from it. The proposed target is a number the
 * engine computed under the policy; anything that explains it must quote it, not
 * produce another.
 *
 * @phpstan-type Target array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int}
 */
final class ProgressionRecommendation
{
    /**
     * @param  array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, source: string}|null  $currentTarget
     * @param  array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, dimension: string, from: float, to: float, step_kg: float, change_percent: float}|null  $proposedTarget
     * @param  array<int, array<string, mixed>>  $supportingSessions  newest first
     * @param  array<int, ReasonCode>  $reasons
     * @param  array<int, string>  $safetyCaveats
     */
    public function __construct(
        public readonly string $exerciseName,
        public readonly ?string $exerciseSlug,
        public readonly ProgressionDecision $decision,
        public readonly ?array $currentTarget,
        public readonly ?array $proposedTarget,
        public readonly array $supportingSessions,
        public readonly EvidenceQuality $evidenceQuality,
        public readonly array $reasons,
        public readonly array $safetyCaveats,
        public readonly string $asOf,
    ) {}

    public function has(ReasonCode $code): bool
    {
        return in_array($code, $this->reasons, true);
    }

    /** @return array<int, string> */
    public function reasonCodes(): array
    {
        return array_map(fn (ReasonCode $code) => $code->value, $this->reasons);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exercise' => ['name' => $this->exerciseName, 'slug' => $this->exerciseSlug],
            'decision' => $this->decision->value,
            'current_target' => $this->currentTarget,
            'proposed_target' => $this->proposedTarget,
            'supporting_sessions' => $this->supportingSessions,
            'evidence_quality' => $this->evidenceQuality->value,
            'reason_codes' => $this->reasonCodes(),
            'reasons' => array_map(fn (ReasonCode $code) => $code->meaning(), $this->reasons),
            'safety_caveats' => $this->safetyCaveats,
            'policy_version' => ProgressionPolicy::VERSION,
            'as_of' => $this->asOf,
            'applied' => false,
        ];
    }
}
