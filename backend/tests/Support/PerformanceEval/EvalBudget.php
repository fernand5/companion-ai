<?php

namespace Tests\Support\PerformanceEval;

/**
 * A guard for real-model runs: stop after a fixed number of answers, and stop at once when the
 * provider keeps failing. A repeated failure is usually a quota or billing limit (a monthly
 * spending cap returns the same 429 as a per-minute limit), which retrying cannot fix and only
 * makes worse; the run halts and says why instead of waiting and trying again.
 */
final class EvalBudget
{
    private int $answers = 0;

    private int $consecutiveErrors = 0;

    public function __construct(
        public readonly int $maxAnswers = 400,
        public readonly int $maxConsecutiveErrors = 2,
    ) {}

    public function record(bool $failed): void
    {
        $this->answers++;
        $this->consecutiveErrors = $failed ? $this->consecutiveErrors + 1 : 0;
    }

    /** Why the run must stop, or null to carry on. */
    public function haltReason(): ?string
    {
        if ($this->consecutiveErrors >= $this->maxConsecutiveErrors) {
            return "{$this->consecutiveErrors} provider errors in a row (likely a quota or spending cap): stopping instead of retrying.";
        }

        if ($this->answers >= $this->maxAnswers) {
            return "answer budget of {$this->maxAnswers} reached.";
        }

        return null;
    }
}
