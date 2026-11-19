<?php

namespace App\Services\Ai\Authorization;

use Carbon\CarbonImmutable;

/**
 * Numeric dates ("7/11", "07/11/2026") mean different days in different places: the 7th of November, or July the 11th.
 * Which one is never decided by the language the app is shown in, and never guessed. A numeric date is read only when
 * it can be read one way: one of the two numbers cannot be a month, or the weekday written with it fits one reading and
 * not the other. Otherwise it is not read, and the user is asked for a day that cannot be misread (a weekday, or
 * 2026-11-07).
 */
final class DateReading
{
    /** A day-and-month written with slashes, with an optional year; or with dots when it has a year ("7.11.2026"). */
    public const NUMERIC = '(?<![\\d\\/.x×])(\\d{1,2})(?:\\/(\\d{1,2})(?:\\/(\\d{2,4}))?|\\.(\\d{1,2})\\.(\\d{4}))(?![\\d\\/])(?!\\s*(?:reps?|repetitions?|repeticion(?:es)?|veces|times|kgs?|kilos?|lbs?|mins?|minut\\w+|seconds?|segundos?|secs?|series|sets?|x|×))';

    /**
     * The numeric dates in a text, each as its possible dates (zero, one or two).
     *
     * @return array<int, array<int, string>>
     */
    public static function inText(string $text, CarbonImmutable $today): array
    {
        $found = [];

        if (preg_match_all('/'.self::NUMERIC.'/u', $text, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $hit) {
                $second = ($hit[2] ?? '') !== '' ? (int) $hit[2] : (int) ($hit[4] ?? 0);
                $year = ($hit[3] ?? '') !== '' ? (int) $hit[3] : (($hit[5] ?? '') !== '' ? (int) $hit[5] : null);
                $found[] = self::candidates((int) $hit[1], $second, $year, $today);
            }
        }

        return $found;
    }

    /**
     * Every real date "a/b" can mean: the day-first and the month-first reading, once if they are the same.
     *
     * @return array<int, string>
     */
    public static function candidates(int $a, int $b, ?int $year, CarbonImmutable $today): array
    {
        if ($year !== null && $year < 100) {
            $year += 2000;
        }

        $dates = [];

        foreach (array_unique([[$a, $b], [$b, $a]], SORT_REGULAR) as [$day, $month]) {
            $y = $year ?? $today->year;

            if ($month < 1 || $month > 12 || $day < 1 || ! checkdate($month, $day, $y)) {
                continue;
            }

            $date = CarbonImmutable::create($y, $month, $day, 0, 0, 0, $today->getTimezone());

            // "3/1" said in late December is next year's (as for a month name).
            if ($year === null && $date->lt($today->subDays(180))) {
                $date = $date->addYear();
            }

            $dates[] = $date->toDateString();
        }

        return array_values(array_unique($dates));
    }

    /** The one date a numeric date can mean, or null when it could mean two (or none). */
    public static function only(array $candidates): ?string
    {
        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
