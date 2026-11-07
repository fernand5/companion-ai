<?php

namespace App\Services\Ai\Authorization;

use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Add this plan to my dashboard" after a plan for several days: which days, on which dates, doing what. Found
 * deterministically from the text the user is pointing at, or the reason it cannot be, so the coach is never asked to
 * write a week from a plan nobody can pin down, and a plan of several days is never read as one workout.
 *
 * A plan of several days is a text with two or more day headings ("Saturday: Lower body", "**Sunday, Nov 1 – Rest day**",
 * "Day 2 (Sunday)"). Each heading starts a section, which runs to the next heading or to the coach's closing offer ("If
 * this plan looks good…"). A section is a workout (its exercise lines, read as in SavedWorkoutReference), or a rest day
 * (it lists nothing and says so). Anything else is asked about, never filled in: a day with nothing in it, a day that is
 * labelled rest and lists work, an exercise that is "A or B", days that have already passed, dates that do not fit
 * their weekday, "next week" with no dates.
 *
 * A weekday with no date is the day of the user's CURRENT week (Monday to Sunday, in their timezone). The user can name
 * the days they want ("for Saturday and Sunday"); only those are then used. Lexical on purpose, like the classifier and
 * SavedWorkoutReference: it only ever decides whether a write the user already asked for may go ahead, and when unsure
 * it asks.
 */
final class WeekPlanReference
{
    public const MAX_DAYS = PlanReplacementProposal::MAX_DAYS;

    /** How far ahead a day may be (the same two months as a single workout). */
    public const HORIZON_DAYS = 60;

    private const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        'mon' => 1, 'tues' => 2, 'tue' => 2, 'wed' => 3, 'thurs' => 4, 'thur' => 4, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** The coach's closing words: after these nothing is part of a day. */
    private const CLOSING = '/^(?:if (?:this|that|you|the|it)\b|would you\b|want me\b|do you want\b|let me know\b|just say\b|shall i\b|say\b|reply\b|feel free\b|once you\b|when you(?:\'re| are) ready\b|i can also\b|i(?:\'ll| will)\b|ready to\b|note:|notes?\b|tip:|remember\b)/iu';

    /** A listed line that is not an exercise: a warm-up, a note, a sub-heading, a total. */
    private const NOT_AN_EXERCISE_LINE = '/^(?:warm|cool|rest\b|stretch|breath|hydrat|finish|note|tip|goal|focus|form\b|tempo|intensity|equipment|why\b|optional|total|duration|time\b|repeat|circuit|round)|:\s*$/iu';

    private const REST = '/\b(?:rest(?:ing)?(?:\s+day)?|day off|off day|no workout|recovery day|take it easy|nothing planned|complete rest|full rest)\b/iu';

    /**
     * @param  Collection<int, Message>  $history  the conversation up to and including the user's current message
     * @param  bool  $inMessageOnly  the request names its own workout, so the earlier reply is not looked at
     * @param  bool  $retry  as for SavedWorkoutReference::resolve()
     */
    public function resolve(string $userMessage, Collection $history, CarbonImmutable $today, bool $inMessageOnly = false, bool $retry = false): WeekPlan
    {
        $sections = self::sections($userMessage);
        $fromMessage = count($sections) >= 2;
        $text = $userMessage;

        if (! $fromMessage) {
            // A routine the user wrote out themselves is the single-workout path's business, and so is a request that names its own workout.
            if ($inMessageOnly || SavedWorkoutReference::items($userMessage) !== []) {
                return WeekPlan::notAWeek();
            }

            $reply = SavedWorkoutReference::replyBefore($history, $retry);
            $sections = $reply === null ? [] : self::sections($reply);
            $text = (string) $reply;
        }

        if (count($sections) < 2) {
            return WeekPlan::notAWeek();
        }

        if (SavedWorkoutReference::isQuoted($text) || self::hasQuotedDay($text)) {
            return WeekPlan::unresolved(WeekPlan::QUOTED);
        }

        $omitted = [];

        // ---- dates ---------------------------------------------------------------------------------------
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $dated = [];
        $seen = [];

        foreach ($sections as $section) {
            $date = self::dateOf($section, $today, $weekStart);

            if ($date === null) {
                return WeekPlan::unresolved(WeekPlan::DATES, [$section['label']], 'the date in that heading is not that day of the week');
            }

            if (isset($seen[$date])) {
                return WeekPlan::unresolved(WeekPlan::DATES, [$section['label']], 'that day appears twice');
            }

            $seen[$date] = true;
            $dated[] = $section + ['date' => $date];
        }

        if (! array_filter($dated, fn (array $s) => $s['explicit']) && preg_match('/\bnext week\b/iu', $text)) {
            return WeekPlan::unresolved(WeekPlan::DATES, [], 'the plan is for next week but its days have no dates');
        }

        // ---- which of the days the user asked for ------------------------------------------------------------
        if (! $fromMessage && ($named = self::namedDates($userMessage, $today)) !== []) {
            $present = array_column($dated, 'date');

            foreach ($named as $date => $word) {
                if (! in_array($date, $present, true)) {
                    return WeekPlan::unresolved(WeekPlan::DATES, [$word], 'the plan has no such day');
                }
            }

            $omitted = array_map(fn (array $s) => $s['label'], array_filter($dated, fn (array $s) => ! isset($named[$s['date']])));
            $dated = array_values(array_filter($dated, fn (array $s) => isset($named[$s['date']])));
        }

        usort($dated, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        $past = array_values(array_filter($dated, fn (array $s) => $s['date'] < $today->toDateString()));

        if ($past !== []) {
            return WeekPlan::unresolved(WeekPlan::PAST_DAYS, array_map(fn (array $s) => $s['label'], $past));
        }

        $far = array_values(array_filter($dated, fn (array $s) => $s['date'] > $today->addDays(self::HORIZON_DAYS)->toDateString()));

        if ($far !== []) {
            return WeekPlan::unresolved(WeekPlan::DATES, array_map(fn (array $s) => $s['label'], $far), 'that is more than two months ahead');
        }

        if (count($dated) > self::MAX_DAYS) {
            return WeekPlan::unresolved(WeekPlan::TOO_MANY);
        }

        // One plan is one stretch of days, not days scattered over weeks.
        if ($dated !== [] && CarbonImmutable::parse($dated[0]['date'])->diffInDays(CarbonImmutable::parse($dated[count($dated) - 1]['date'])) > 6) {
            return WeekPlan::unresolved(WeekPlan::DATES, [], 'its days are more than a week apart');
        }

        // ---- what each day holds ---------------------------------------------------------------------------
        $days = [];
        $choices = [];

        foreach ($dated as $section) {
            $body = $section['body'];
            $items = SavedWorkoutReference::items($body);
            $labelRest = (bool) preg_match(self::REST, $section['title']);

            if (SavedWorkoutReference::looksUnfinished($body)) {
                return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it looks cut off or unfinished');
            }

            if (self::offersAlternatives($body)) {
                return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it offers alternatives (options) and I can only save one workout per day');
            }

            if ($section['after'] !== '' && (SavedWorkoutReference::items($section['after']) !== [] || SavedWorkoutReference::namesMovement($section['after']))) {
                return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'the text under its note mentions exercises that I would leave out');
            }

            if ($items === []) {
                if (SavedWorkoutReference::namesMovement($section['title']."\n".$body)) {
                    return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it mentions an activity with no sets, reps or time');
                }

                if (preg_match('/\brest\b[^.\n]{0,25}(?:\bor\b|\/)|(?:\bor\b|\/)\s*(?:a\s+)?rest\b/iu', $section['title']."\n".$body)) {
                    return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it says rest or something else');
                }

                if (! preg_match(self::REST, $section['title']."\n".$body)) {
                    return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it lists no exercises and does not say it is a rest day');
                }

                $days[] = ['date' => $section['date'], 'label' => $section['label'], 'kind' => WeekPlan::KIND_REST, 'items' => []];

                continue;
            }

            if ($labelRest) {
                return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'it is called a rest day but also lists exercises');
            }

            // A listed line that gives no sets, reps or time would be left out of the saved day: say so instead.
            foreach ($section['list'] as $line) {
                if (SavedWorkoutReference::items($line) === [] && ! preg_match(self::NOT_AN_EXERCISE_LINE, $line) && preg_match('/\p{L}/u', $line)) {
                    return WeekPlan::unresolved(WeekPlan::UNCLEAR_DAY, [$section['label']], 'the line "'.mb_substr($line, 0, 50).'" gives no sets, reps or time, so I would have to leave it out');
                }
            }

            $settled = [];

            foreach ($items as $item) {
                $one = SavedWorkoutReference::settle($item, $userMessage);

                if ($one['options'] !== null) {
                    $choices[] = ['item' => $item, 'options' => $one['options'], 'day' => $section['label']];
                }

                $settled[] = $one['item'];
            }

            $days[] = ['date' => $section['date'], 'label' => $section['label'], 'kind' => WeekPlan::KIND_WORKOUT, 'items' => $settled];
        }

        if ($choices !== []) {
            return WeekPlan::needsChoice($choices);
        }

        return WeekPlan::found($days, $text, array_values($omitted));
    }

    /** Whether a day offers options ("Option A", "Alternatively", "if you prefer"), which would be saved together as one workout. */
    private static function offersAlternatives(string $body): bool
    {
        return (bool) preg_match('/^[\s>*_#\-•]*(?:option|alternative|choice|version)\s*(?:#|no\.?)?\s*(?:\d{1,2}|[a-d])\b|\b(?:alternatively|another option|or if you prefer|if you prefer)\b/imu', $body);
    }

    /**
     * The day sections of a text, in order. Each: weekday (1-7), label (the heading as written), title (the heading
     * without the weekday), body (everything under it, the heading's own remainder first), date parts if the heading has any.
     *
     * @return array<int, array{weekday: int, label: string, title: string, body: string, list: array<int, string>, after: string, month: ?int, day: ?int, year: ?int, explicit: bool}>
     */
    public static function sections(string $text): array
    {
        $sections = [];
        $current = null;
        $closed = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            // A block quote is somebody's example, never one of the plan's own days (see hasQuotedDay()).
            if (preg_match('/^\s*>/u', $raw)) {
                continue;
            }

            $line = trim((string) preg_replace('/[*_`]+/u', '', $raw));
            $bare = trim((string) preg_replace('/^[\s#\-•]+/u', '', $line));

            if ($bare === '') {
                if ($current !== null) {
                    $sections[$current]['body'] .= "\n";
                }

                continue;
            }

            // On the raw line: a "* " bullet has lost its marker once the markdown is stripped.
            $isListItem = (bool) preg_match('/^\s*(?:[*\-•–—]|\d+[.)])\s+/u', $raw);

            if (($heading = self::heading($bare)) !== null) {
                $sections[] = $heading;
                $current = array_key_last($sections);
                $closed = false;

                continue;
            }

            // The coach's closing offer or a note is not part of the day above it; a later day is still read.
            if ($current !== null && ! $isListItem && preg_match(self::CLOSING, $bare)) {
                $closed = true;
            }

            if ($current !== null && ! $closed) {
                $sections[$current]['body'] .= "\n".$line;

                if ($isListItem) {
                    $sections[$current]['list'][] = $line;
                }
            } elseif ($current !== null) {
                // Whatever follows a note is not part of the day, but it must not hide exercises (see resolve()).
                $sections[$current]['after'] .= "\n".$line;
            }
        }

        return $sections;
    }

    /** Whether a block-quoted line looks like a day of a plan: an example somebody else wrote, which is never saved. */
    public static function hasQuotedDay(string $text): bool
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            if (preg_match('/^\s*>/u', $raw) && self::heading(trim((string) preg_replace('/^[\s>#\-•*_`]+/u', '', $raw))) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array{weekday: int, label: string, title: string, body: string, list: array<int, string>, after: string, month: ?int, day: ?int, year: ?int, explicit: bool}|null */
    private static function heading(string $bare): ?array
    {
        $names = implode('|', array_keys(self::WEEKDAYS));

        if (! preg_match('/^(?:day\s*\d+\s*[:\-–—(]\s*)?('.$names.')\b\.?(.*)$/iu', $bare, $m) || mb_strlen($bare) > 400) {
            return null;
        }

        $rest = $m[2];

        if (trim($rest) !== '' && ! preg_match('/^\s*(?:[:,;()\-–—\/]|\d|(?:'.implode('|', array_keys(self::MONTHS)).')[a-z]*\b)/iu', $rest)) {
            return null; // "Saturday and Sunday are…", "Sunday salutations": a sentence, not a heading
        }

        // "Saturday, Sunday and Monday are your days", "Saturday - Sunday": a list of days, not one.
        if (preg_match('/^\s*(?:[,\/&\-–—]|and\b)\s*(?:and\s+)?(?:'.$names.')\b/iu', $rest)) {
            return null;
        }

        $head = mb_substr($rest, 0, 80);
        $month = $day = $year = null;

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $head, $d)) {
            [$year, $month, $day] = [(int) $d[1], (int) $d[2], (int) $d[3]];
        } elseif (preg_match('/\b('.implode('|', array_keys(self::MONTHS)).')[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', $head, $d)) {
            [$month, $day] = [self::MONTHS[strtolower($d[1])], (int) $d[2]];
        } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?('.implode('|', array_keys(self::MONTHS)).')[a-z]*\b/iu', $head, $d)) {
            [$month, $day] = [self::MONTHS[strtolower($d[2])], (int) $d[1]];
        }

        // The heading as the coach wrote it, which is how the user and the application's questions name the day.
        $label = rtrim(trim((string) preg_replace('/\s+/u', ' ', ucfirst(mb_strtolower($m[1])).$rest)), " \t:-–—,(");
        $label = mb_strlen($label) > 70 ? rtrim(mb_substr($label, 0, 69)).'…' : $label;
        $title = trim((string) preg_replace('/^[\s:\-–—,()]+/u', '', $rest));

        return [
            'weekday' => self::WEEKDAYS[strtolower($m[1])],
            'label' => rtrim($label, " \t:-–—,("),
            'title' => $title,
            'body' => $title,
            'list' => [],
            'after' => '',
            'month' => $month,
            'day' => $day,
            'year' => $year,
            'explicit' => $month !== null,
        ];
    }

    /** The date of a section, or null if its own date is not on its weekday. */
    private static function dateOf(array $section, CarbonImmutable $today, CarbonImmutable $weekStart): ?string
    {
        if ($section['month'] === null) {
            return $weekStart->addDays($section['weekday'] - 1)->toDateString();
        }

        $year = $section['year'] ?? $today->year;

        if (! checkdate($section['month'], $section['day'], $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $section['month'], $section['day'], 0, 0, 0, $today->getTimezone());

        // "Jan 3" said in late December is next year's.
        if ($section['year'] === null && $date->lt($today->subDays(180))) {
            $date = $date->addYear();
        }

        return $date->dayOfWeekIso === $section['weekday'] ? $date->toDateString() : null;
    }

    /**
     * The days the user's own words name ("for Saturday and Sunday", "just tomorrow"): date => the word they used.
     *
     * @return array<string, string>
     */
    private static function namedDates(string $message, CarbonImmutable $today): array
    {
        $named = [];
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $full = implode('|', array_filter(array_keys(self::WEEKDAYS), fn (string $d) => strlen($d) > 3));

        if (preg_match_all('/\b('.$full.'|today|tomorrow)\b/iu', $message, $hits)) {
            foreach ($hits[1] as $word) {
                $word = strtolower($word);
                $date = match ($word) {
                    'today' => $today->toDateString(),
                    'tomorrow' => $today->addDay()->toDateString(),
                    default => $weekStart->addDays(self::WEEKDAYS[$word] - 1)->toDateString(),
                };
                $named[$date] = ucfirst($word);
            }
        }

        return $named;
    }
}
