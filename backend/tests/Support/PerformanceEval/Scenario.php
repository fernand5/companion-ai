<?php

namespace Tests\Support\PerformanceEval;

use App\Models\User;
use Closure;

/**
 * One coaching situation: a fixture built through the real application paths, the
 * questions a user asks about it, what a correct answer must establish (expected),
 * automatic checks for it, and known-good / known-bad answers that prove the checks
 * themselves (including the failures actually seen from the real model).
 */
final class Scenario
{
    /**
     * @param  Closure(PerformanceEvalCase, User): void  $setup
     * @param  array<int, Question>  $questions
     * @param  array<int, string>  $facts  substrings the model must be shown (in its context) for this to be answerable
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $title,
        public readonly Closure $setup,
        public readonly array $questions,
        public readonly array $facts = [],
        public readonly ?string $guards = null,
    ) {}
}
