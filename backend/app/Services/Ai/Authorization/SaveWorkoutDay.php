<?php

namespace App\Services\Ai\Authorization;

use Carbon\CarbonImmutable;

/**
 * Which day "add this workout to my dashboard" is for. The user's words decide it ("today", "tomorrow", "Friday",
 * "2026-10-30"); with none it is today, unless the workout text itself mentions another day, in which case the
 * user is asked. The model never picks the day, and a day that is in the past, more than two months away or
 * named twice differently is not guessed at.
 */
final class SaveWorkoutDay
{
    public const TOO_FAR_DAYS = 60;

    private const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

    public const WHICH_DAY = 'which_day';

    public const PAST = 'past_day';

    /**
     * @return array{date: ?string, problem: ?string} the day, or what is wrong with how it was named
     */
    public static function resolve(string $userMessage, string $workoutText, CarbonImmutable $today): array
    {
        $text = self::plain($userMessage);
        $dates = [];

        if (preg_match('/\b(?:next|last)\s+(?:'.implode('|', array_keys(self::WEEKDAYS)).')\b/u', $text) || preg_match('/\b(?:next|last) week\b/u', $text)) {
            return ['date' => null, 'problem' => self::WHICH_DAY];
        }

        if (preg_match('/\byesterday\b/u', $text)) {
            return ['date' => null, 'problem' => self::PAST];
        }

        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (! checkdate((int) $hit[2], (int) $hit[3], (int) $hit[1])) {
                    return ['date' => null, 'problem' => self::WHICH_DAY];
                }

                $dates[] = $hit[0];
            }
        }

        if (preg_match('/\b(?:today|tonight|today\'s)\b/u', $text)) {
            $dates[] = $today->toDateString();
        }

        if (preg_match('/\btomorrow\b/u', $text)) {
            $dates[] = $today->addDay()->toDateString();
        }

        foreach (self::WEEKDAYS as $name => $isoDay) {
            if (preg_match('/\b'.$name.'\b/u', $text)) {
                $dates[] = $today->addDays(($isoDay - $today->dayOfWeekIso + 7) % 7)->toDateString();
            }
        }

        $dates = array_values(array_unique($dates));

        if (count($dates) > 1) {
            return ['date' => null, 'problem' => self::WHICH_DAY];
        }

        if ($dates === []) {
            // The user named no day. A workout that is itself "for Friday" has a day of its own, so ask.
            return self::namesADay($workoutText)
                ? ['date' => null, 'problem' => self::WHICH_DAY]
                : ['date' => $today->toDateString(), 'problem' => null];
        }

        $date = CarbonImmutable::parse($dates[0]);

        if ($date->lessThan($today)) {
            return ['date' => null, 'problem' => self::PAST];
        }

        if ($date->greaterThan($today->addDays(self::TOO_FAR_DAYS))) {
            return ['date' => null, 'problem' => self::WHICH_DAY];
        }

        return ['date' => $date->toDateString(), 'problem' => null];
    }

    /** True if the message names a day at all (today, tomorrow, a weekday, a date), whether or not it is a usable one. */
    public static function names(string $message): bool
    {
        return (bool) preg_match('/\b(?:today|tonight|tomorrow|yesterday|next week|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/u', self::plain($message));
    }

    /** The text without the words that name a day, so that a new answer can name it instead. */
    public static function without(string $text): string
    {
        $stripped = (string) preg_replace('/\b(?:(?:next|last|this|on|for)\s+)?(?:today\'?s?|tonight|tomorrow|yesterday|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/iu', ' ', $text);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $stripped));
    }

    private static function namesADay(string $text): bool
    {
        return (bool) preg_match('/\b(?:tomorrow|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/iu', $text);
    }

    private static function plain(string $message): string
    {
        $text = (string) preg_replace('/```.*?```/su', ' ', mb_strtolower($message));
        $text = (string) preg_replace('/^\s*>.*$/mu', ' ', $text);
        $text = (string) preg_replace('/["“”«»].*?["“”«»]/su', ' ', $text);

        return str_replace(['’', '‘'], "'", $text);
    }
}
