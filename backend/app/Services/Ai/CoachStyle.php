<?php

namespace App\Services\Ai;

/**
 * How the coach sounds. A voice only: it never changes what the coach is allowed to
 * recommend, change or claim (see SystemPromptBuilder::voiceSection()).
 */
enum CoachStyle: string
{
    case Supportive = 'supportive';
    case Balanced = 'balanced';
    case Intense = 'intense';

    public const DEFAULT = self::Balanced;

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
