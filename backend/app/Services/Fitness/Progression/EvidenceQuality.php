<?php

namespace App\Services\Fitness\Progression;

enum EvidenceQuality: string
{
    /** Three or more entered sessions at the same load and target, all judged against their target. */
    case Strong = 'strong';

    /** Some evidence exists, but it is migrated, incomplete or has no target to judge it by. */
    case Limited = 'limited';

    /** Too few, too old or too different sessions to compare. */
    case Insufficient = 'insufficient';

    /** Nothing measured at all. */
    case None = 'none';
}
