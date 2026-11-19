<?php

namespace App\Services\Ai\Authorization;

/**
 * A number written in a message (a load, in kilograms), read only when it can be read one way.
 *
 * "42.5" and "42,5" are both 42.5. A number whose separator could be a decimal mark or a thousands mark ("1,500",
 * "1.500", "1.234,5") is NOT guessed at: it reads as null, and a null is never a load. So "42,5" is never 425 or 42,
 * and a figure the application cannot read is simply not a stated number (a weight nobody stated is never invented).
 */
final class LoadNumber
{
    /** The digits-and-separators run a number is written as, for matching inside a message. */
    public const PATTERN = '\d+(?:[.,]\d+)*';

    public static function parse(string $raw): ?float
    {
        $raw = trim($raw);

        if (preg_match('/^\d+$/', $raw) === 1) {
            return (float) $raw;
        }

        // One separator and one or two digits after it: a decimal mark, whichever mark it is.
        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $raw, $m) === 1) {
            return (float) ($m[1].'.'.$m[2]);
        }

        // Anything else (three digits after a separator, several separators, more precision than a load has) could be
        // read two ways. It is not read.
        return null;
    }

    /**
     * Every kilogram figure in a text that can be read one way, in the order written. English and Spanish units.
     *
     * @return array<int, float>
     */
    public static function kilograms(string $text): array
    {
        preg_match_all('/('.self::PATTERN.')\s*(?:kg|kgs|kilos?|kilogramos?|kilograms?)\b/u', mb_strtolower($text), $m);

        return array_values(array_filter(array_map(self::parse(...), $m[1]), fn (?float $n) => $n !== null));
    }

    /** How many kilogram figures the text writes, readable or not: an unreadable one still counts as one. */
    public static function countKilograms(string $text): int
    {
        return (int) preg_match_all('/'.self::PATTERN.'\s*(?:kg|kgs|kilos?|kilogramos?|kilograms?)\b/u', mb_strtolower($text));
    }
}
