<?php

namespace App\Services\Ai\Authorization;

/** Either a precise proposal, or the reason none can be offered. */
final class ProposalResult
{
    /** @param array<string, mixed> $detail */
    private function __construct(
        public readonly ?PlanChangeProposal $proposal,
        public readonly ?string $failure,
        public readonly array $detail = [],
    ) {}

    public static function ok(PlanChangeProposal $proposal): self
    {
        return new self($proposal, null);
    }

    /** @param array<string, mixed> $detail */
    public static function failed(string $reason, array $detail = []): self
    {
        return new self(null, $reason, $detail);
    }
}
