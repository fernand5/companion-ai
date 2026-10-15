<?php

namespace App\Services\Fitness\Progression;

enum ProgressionDecision: string
{
    /** The record supports a small, bounded load increase. */
    case Increase = 'INCREASE';

    /** Keep the current target. This is the default whenever the record does not clearly support a change. */
    case Maintain = 'MAINTAIN';

    /** Repeated misses at the same load support a small reduction. */
    case Decrease = 'DECREASE';

    /** There is not enough reliable, comparable history to say. */
    case InsufficientData = 'INSUFFICIENT_DATA';

    /** Something (pain, a restriction, an exercise type or load step the policy does not cover) needs a person. */
    case ManualReview = 'MANUAL_REVIEW';

    /** True for the two decisions that propose a different number. */
    public function proposesChange(): bool
    {
        return $this === self::Increase || $this === self::Decrease;
    }
}
