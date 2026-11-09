<?php

namespace App\Services\Ai;

/**
 * How much the coach writes. Length and depth of explanation only: no detail level may drop
 * a pain or safety caution, or a statement of what was not recorded.
 */
enum ResponseDetail: string
{
    case Concise = 'concise';
    case Standard = 'standard';
    case Detailed = 'detailed';

    public const DEFAULT = self::Standard;

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
