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

    private const WEEKDAYS_ES = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    private const MONTHS_ES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    /** "mañana" is tomorrow, unless it is the morning ("esta mañana", "por la mañana"); "pasado mañana" is two days ahead. Text is folded. */
    private const TOMORROW_ES = '(?<!pasado )(?<!la )(?<!esta )(?<!cada )\bmanana\b';

    /** A day and month written with slashes ("7/11"): the 7th of November or July the 11th, which is never guessed. */
    private const NUMERIC_DATE = '(?<![\d\/.x×])\d{1,2}\/\d{1,2}(?:\/\d{2,4})?(?![\d\/])(?!\s*(?:reps?|repeticion\w*|veces|times|kgs?|kilos?|lbs?|mins?|minut\w+|seconds?|segundos?|secs?|series|sets?|x|×))';

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

        // Spanish, read beside the English words from folded text ("miércoles" is "miercoles").
        $es = SpanishSaveIntent::fold($text);
        $weekdaysEs = implode('|', array_keys(self::WEEKDAYS_ES));

        // "el lunes que viene", "el lunes pasado", "la semana que viene", "los lunes" (every Monday): not one nameable day.
        if (preg_match('/\b(?:proxim[oa]|pasad[oa]|ultim[oa])\s+(?:'.$weekdaysEs.')\b|\b(?:'.$weekdaysEs.')\s+(?:que viene|pasado|proximo|siguiente)\b|\bsemana (?:que viene|proxima|siguiente|pasada)\b|\b(?:la )?proxima semana\b|\b(?:todos )?(?:los|cada)\s+(?:'.$weekdaysEs.')\b/u', $es)) {
            return ['date' => null, 'problem' => self::WHICH_DAY];
        }

        if (preg_match('/\b(?:ayer|anoche|anteayer|antier)\b/u', $es)) {
            return ['date' => null, 'problem' => self::PAST];
        }

        // "7/11" is November 7th or July 11th depending on the country: asked about, never guessed.
        if (preg_match('/'.self::NUMERIC_DATE.'/u', $text)) {
            return ['date' => null, 'problem' => self::WHICH_DAY];
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

        if (preg_match('/\b(?:hoy|esta noche)\b/u', $es)) {
            $dates[] = $today->toDateString();
        }

        if (preg_match('/\bpasado manana\b/u', $es)) {
            $dates[] = $today->addDays(2)->toDateString();
        }

        if (preg_match('/'.self::TOMORROW_ES.'/u', $es)) {
            $dates[] = $today->addDay()->toDateString();
        }

        foreach (self::WEEKDAYS_ES as $name => $isoDay) {
            if (preg_match('/\b'.$name.'\b/u', $es)) {
                $dates[] = $today->addDays(($isoDay - $today->dayOfWeekIso + 7) % 7)->toDateString();
            }
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
            // The user named no day. A workout that is itself "for Friday" has a day of its own, so ask. (A Spanish
            // heading with its own consistent date, "Miércoles, 18 de noviembre — Torso y Core", names exactly that day.)
            $headed = self::headingDate($workoutText, $today);

            if ($headed !== null) {
                return $headed === false || $headed < $today->toDateString() || $headed > $today->addDays(self::TOO_FAR_DAYS)->toDateString()
                    ? ['date' => null, 'problem' => self::WHICH_DAY]
                    : ['date' => $headed, 'problem' => null];
            }

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
        $text = self::plain($message);

        return (bool) preg_match('/\b(?:today|tonight|tomorrow|yesterday|next week|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/u', $text)
            || self::namesInSpanish(SpanishSaveIntent::fold($text))
            || (bool) preg_match('/'.self::NUMERIC_DATE.'/u', $text);
    }

    /** The text without the words that name a day, so that a new answer can name it instead. */
    public static function without(string $text): string
    {
        $stripped = (string) preg_replace('/\b(?:(?:next|last|this|on|for)\s+)?(?:today\'?s?|tonight|tomorrow|yesterday|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/iu', ' ', $text);

        // The same, in Spanish ("para mañana", "el viernes", "de hoy", "pasado mañana"), as written, accents or not.
        $accents = static fn (string $folded): string => strtr($folded, ['a' => '[aáàäâ]', 'e' => '[eéèëê]', 'i' => '[iíìïî]', 'o' => '[oóòöô]', 'u' => '[uúùüû]', 'n' => '[nñ]', ' ' => '\\s+']);
        $days = implode('|', array_map($accents, array_keys(self::WEEKDAYS_ES)));
        $stripped = (string) preg_replace(
            '/\b(?:(?:para|el|este|del|de|al|a|en)\s+)*(?:'.$accents('pasado manana').'|'.$accents('esta noche').'|(?<!la )(?<!esta )(?<!cada )'.$accents('manana').'|hoy|ayer|anoche|'.$days.')\b/iu',
            ' ',
            $stripped,
        );

        return trim((string) preg_replace('/[ \t]+/u', ' ', $stripped));
    }

    private static function namesADay(string $text): bool
    {
        return (bool) preg_match('/\b(?:tomorrow|'.implode('|', array_keys(self::WEEKDAYS)).')\b|\b\d{4}-\d{2}-\d{2}\b/iu', $text)
            || self::namesInSpanish(SpanishSaveIntent::fold($text), includeToday: false);
    }

    /** Whether folded Spanish text names a day: today, tomorrow, the day after, yesterday, a weekday, "next week". */
    private static function namesInSpanish(string $es, bool $includeToday = true): bool
    {
        return (bool) preg_match('/\b(?:'.($includeToday ? 'hoy|esta noche|' : '').'ayer|anoche|anteayer|antier|pasado manana|(?:la )?proxima semana|semana (?:que viene|proxima|siguiente|pasada)|'.implode('|', array_keys(self::WEEKDAYS_ES)).')\b|'.self::TOMORROW_ES.'/u', $es);
    }

    /**
     * The day the workout's own dated heading names ("Miércoles, 18 de noviembre — Torso y Core", "Wednesday, November 18:
     * Upper body", "Wednesday 2026-11-18"), in either language by the same rule: its date when the weekday and the date
     * agree, false when they do not or the text has several different ones, null when it has no dated heading.
     *
     * This only says WHICH day the referenced workout is for. It authorizes nothing: the write is allowed by the user's
     * own request to save it, and a user who names a day in that request decides the day (see resolve()). A heading with
     * only a weekday, or a date that does not fit its weekday, is not read: the user is asked.
     */
    private static function headingDate(string $text, CarbonImmutable $today): string|false|null
    {
        $found = [];
        $monthNames = self::MONTHS_ES + ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $weekdayNames = self::WEEKDAYS_ES + self::WEEKDAYS;
        $weekdays = implode('|', array_keys($weekdayNames));
        $months = implode('|', array_keys($monthNames));

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $head = SpanishSaveIntent::fold(trim((string) preg_replace('/^[\s#*_>\-•]+/u', '', $line)));

            if (! preg_match('/^('.$weekdays.')\b\.?/u', $head, $w)) {
                continue;
            }

            $rest = mb_substr($head, mb_strlen($w[0]), 60);
            $year = null;

            if (preg_match('/^[\s,:(\-–—]*(\d{4})-(\d{2})-(\d{2})\b/u', $rest, $m)) {
                [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif (preg_match('/^[\s,:(\-–—]*(\d{1,2})\s+de\s+('.$months.')\b(?:\s+de\s+(\d{4}))?/u', $rest, $m)) {
                [$month, $day, $year] = [$monthNames[$m[2]], (int) $m[1], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
            } elseif (preg_match('/^[\s,:(\-–—]*('.$months.')\b\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(\d{4}))?/u', $rest, $m)) {
                [$month, $day, $year] = [$monthNames[$m[1]], (int) $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
            } elseif (preg_match('/^[\s,:(\-–—]*(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?('.$months.')\b\.?(?:,?\s+(\d{4}))?/u', $rest, $m)) {
                [$month, $day, $year] = [$monthNames[$m[2]], (int) $m[1], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
            } else {
                continue;
            }

            $date = self::dateOn($year, $month, $day, $today);

            if ($date === null || $date->dayOfWeekIso !== $weekdayNames[$w[1]]) {
                return false;
            }

            $found[$date->toDateString()] = true;
        }

        return match (count($found)) {
            0 => null,
            1 => array_key_first($found),
            default => false,
        };
    }

    /** A real calendar date in the user's timezone; a month and day with no year is the coming one (as for a month name anywhere else). */
    private static function dateOn(?int $year, int $month, int $day, CarbonImmutable $today): ?CarbonImmutable
    {
        $y = $year ?? $today->year;

        if (! checkdate($month, $day, $y)) {
            return null;
        }

        $date = CarbonImmutable::create($y, $month, $day, 0, 0, 0, $today->getTimezone());

        return $year === null && $date->lt($today->subDays(180)) ? $date->addYear() : $date;
    }

    private static function plain(string $message): string
    {
        $text = (string) preg_replace('/```.*?```/su', ' ', mb_strtolower($message));
        $text = (string) preg_replace('/^\s*>.*$/mu', ' ', $text);
        $text = (string) preg_replace('/["“”«»].*?["“”«»]/su', ' ', $text);

        return str_replace(['’', '‘'], "'", $text);
    }
}
