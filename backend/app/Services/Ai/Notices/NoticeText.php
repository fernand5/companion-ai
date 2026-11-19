<?php

namespace App\Services\Ai\Notices;

use App\Models\User;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Support\AppLocale;
use Carbon\CarbonImmutable;

/**
 * The words of everything the application itself says to the user about a coach reply, a save, a held replacement or a
 * plan of several days, in the language the user chose (lang/{en,es}/{coach,plan}.php).
 *
 * PRESENTATION ONLY. The language is read here and nowhere in a decision: which notice to give, which proposal to hold,
 * what is written, which tool may run and what a user's "yes" confirms are all decided before any text is chosen, from
 * the user's own words and the tool results. This class is handed finished facts (dates, plan summaries, closed reason
 * codes, the proposals the application already signed and verified) and returns a sentence. It never parses what the
 * user wrote, never writes anything and never feeds back into a parser.
 *
 *  - Every key is a literal in this class. A string the user or the model wrote is only ever a VALUE substituted into
 *    a sentence (an exercise name, a title), never a key, and substituted values are not scanned again.
 *  - English is the source wording and the fallback (a missing Spanish key shows the English sentence).
 *  - Phrases the user is told to type come from Commands (EnglishCommands / SpanishCommands): the parsers read both
 *    languages for everyone, so a message names the phrase of the language it is written in. (`english_note` is kept,
 *    empty in both languages, so a language that is ever added without parser support can say so again.)
 *  - A few sentences are written by the protected authorization modules in English (the range note, the unset-load
 *    note, why a confirmed change could not be made). For English they pass through byte for byte. For another language
 *    they are rebuilt, for DISPLAY, from the exact English template they were made from; a sentence that does not match
 *    a known template falls back to a generic localized line or, for the notes, to the English original. A failed match
 *    can therefore only show a different sentence: it never changes what was or was not done.
 *  - The signed proposals are never altered: a proposal's stored fields are read to describe it, and nothing is written
 *    back (no payload is translated, no signature recomputed).
 */
final class NoticeText
{
    private const DATE = '\d{4}-\d{2}-\d{2}';

    public function __construct(private readonly AppLocale $locale) {}

    public static function for(User $user): self
    {
        return new self($user->appLocale());
    }

    public function locale(): AppLocale
    {
        return $this->locale;
    }

    // ---- the plumbing ---------------------------------------------------------------------------------------------------

    /** @param array<string, scalar|null> $replace */
    private function t(string $key, array $replace = []): string
    {
        $locale = $this->locale->value;

        return trans($key, $replace + ['english_note' => trans('coach.english_note', [], $locale)] + Commands::for($this->locale), $locale);
    }

    /** Joins sentences with one space, skipping any that are empty (the English note is empty in English). */
    private function join(string ...$sentences): string
    {
        return implode(' ', array_filter(array_map('trim', $sentences), fn (string $s) => $s !== ''));
    }

    private function isEnglish(): bool
    {
        return $this->locale === AppLocale::English;
    }

    // ---- questions before a save ------------------------------------------------------------------------------------------

    public function noWorkoutToSave(): string
    {
        return $this->t('coach.save_question.no_workout');
    }

    public function whichWorkout(): string
    {
        return $this->t('coach.save_question.which_workout');
    }

    public function incompleteWorkout(): string
    {
        return $this->t('coach.save_question.incomplete');
    }

    public function quotedWorkout(): string
    {
        return $this->t('coach.save_question.quoted');
    }

    public function retryExpiredNewDay(?string $madeOn): string
    {
        return $this->t('coach.save_question.retry_new_day', ['made_on' => $madeOn ?? $this->t('coach.earlier_day')]);
    }

    public function retryExpiredTooOld(): string
    {
        return $this->t('coach.save_question.retry_too_old', ['minutes' => PlanReplacementProposal::TTL_MINUTES]);
    }

    public function whichDay(bool $pastDay): string
    {
        return $this->t($pastDay ? 'coach.save_question.which_day_past' : 'coach.save_question.which_day');
    }

    /** @param array<int, array{item?: string, options: array<int, string>, day?: string}> $choices */
    public function exerciseChoice(array $choices): string
    {
        $or = $this->t('coach.save_question.choice_or');
        $lines = array_map(fn (array $c) => '"'.implode('" '.$or.' "', $c['options']).'"'.(isset($c['day']) ? ' ('.$c['day'].')' : ''), $choices);
        $first = $choices[0]['options'][0] ?? $this->t('coach.save_question.choice_first');

        return $this->t(count($lines) === 1 ? 'coach.save_question.choice_one' : 'coach.save_question.choice_many', [
            'lines' => count($lines) === 1 ? $lines[0] : implode('; ', $lines),
            'first' => $first,
        ]);
    }

    /**
     * Why a plan of several days cannot be saved yet.
     *
     * @param  array<int, string>  $problems  the day labels or dates the question names
     */
    public function weekQuestion(string $case, array $problems, string $detail, int $maxDays): string
    {
        $days = implode('; ', array_map(fn (string $d) => '"'.$d.'"', $problems));
        $values = ['days' => $days, 'max' => $maxDays, 'nothing_saved' => $this->t('plan.week.nothing_saved')];

        return match ($case) {
            'unclear_day' => $this->t('coach.week_question.unclear_day', $values + ['detail' => $this->weekDetail($detail)]),
            'past_days' => $this->t('coach.week_question.past_days', $values),
            'no_such_day' => $this->t('coach.week_question.no_such_day', $values),
            'dates' => $this->t('coach.week_question.dates', $values + ['detail' => $days !== ''
                ? $this->t('plan.week.detail_days', ['days' => $days, 'detail' => $this->weekDetail($detail)])
                : $this->t('plan.week.detail_only', ['detail' => $this->weekDetail($detail)])]),
            'too_many' => $this->t('coach.week_question.too_many', $values),
            default => $this->t('coach.week_question.unreadable', $values),
        };
    }

    /** The reason a plan of several days could not be read, as the application worded it in English. */
    private function weekDetail(string $english): string
    {
        if ($this->isEnglish()) {
            return $english;
        }

        $known = [
            'the date in that heading is not that day of the week' => 'heading_not_that_day',
            'that day appears twice' => 'twice',
            'the plan is for next week but its days have no dates' => 'next_week_no_dates',
            'the plan has no such day' => 'no_such_day',
            'that is more than two months ahead' => 'too_far',
            'its days are more than a week apart' => 'apart',
            'it looks cut off or unfinished' => 'cut_off',
            'it offers alternatives (options) and I can only save one workout per day' => 'alternatives',
            'the text under its note mentions exercises that I would leave out' => 'note_mentions_exercises',
            'it mentions an activity with no sets, reps or time' => 'activity_no_numbers',
            'it says rest or something else' => 'rest_or_else',
            'it lists no exercises and does not say it is a rest day' => 'no_exercises_no_rest',
            'it is called a rest day but also lists exercises' => 'rest_but_exercises',
        ];

        if (isset($known[$english])) {
            return $this->t('plan.week_detail.'.$known[$english]);
        }

        if (preg_match('/^the line "(.*)" gives no sets, reps or time, so I would have to leave it out$/us', $english, $m) === 1) {
            return $this->t('plan.week_detail.line_without_numbers', ['line' => $m[1]]);
        }

        return $this->t('plan.week_detail.unknown');
    }

    // ---- what happened to a requested save ---------------------------------------------------------------------------------

    public function cannotAskConfirmation(): string
    {
        return $this->t('coach.save.cannot_ask_confirmation');
    }

    public function saveFailed(): string
    {
        return $this->t('coach.save.failed');
    }

    public function saveDayPerformed(string $date): string
    {
        return $this->t('coach.save.day_performed', ['date' => $date]);
    }

    public function replacedAsAsked(string $was, string $date): string
    {
        return $this->t('coach.save.replaced_as_asked', ['was' => $this->existing($was), 'date' => $date]);
    }

    // ---- a held replacement ---------------------------------------------------------------------------------------------------

    /** What a stored "what is there now" summary says, in this language: only the two fixed phrases are words. */
    public function existing(string $stored): string
    {
        return match ($stored) {
            'a rest day' => $this->t('plan.rest_day'),
            PlanReplacementProposal::NOTHING_PLANNED => $this->t('plan.nothing_planned'),
            default => $stored, // a plan's own title and exercises: the user's words, shown as they are
        };
    }

    /**
     * One held change, described from the proposal's own fields (already verified): never the stored text.
     *
     * @param  array<string, mixed>  $change
     */
    public function describeChange(array $change): string
    {
        $values = ['date' => $change['date'], 'existing' => $this->existing((string) $change['existing_summary'])];

        if ($change['action'] === PlanReplacementProposal::CREATE) {
            return $this->t('plan.change.create', $values + ['replacement' => PlanReplacementProposal::summarise($change['replacement'])]);
        }

        if ($change['action'] === PlanReplacementProposal::CLEAR && $change['existing_plan_id'] === 0) {
            return $this->t('plan.change.clear_new', $values);
        }

        return $change['action'] === PlanReplacementProposal::CLEAR
            ? $this->t('plan.change.clear_existing', $values)
            : $this->t('plan.change.replace', $values + ['replacement' => PlanReplacementProposal::summarise($change['replacement'])]);
    }

    /** The application's question for a held replacement. */
    public function replacementQuestion(PlanReplacementProposal $proposal): string
    {
        $minutes = PlanReplacementProposal::TTL_MINUTES;
        $changes = $proposal->changes;

        if (count($changes) === 1) {
            $change = $changes[0];
            $values = ['date' => $change['date'], 'existing' => $this->existing((string) $change['existing_summary']), 'minutes' => $minutes];

            return $change['action'] === PlanReplacementProposal::CLEAR
                ? $this->t('plan.replacement.single_clear', $values)
                : $this->t('plan.replacement.single_replace', $values + ['replacement' => PlanReplacementProposal::summarise($change['replacement'])]);
        }

        $header = array_filter($changes, fn (array $c) => $c['action'] === PlanReplacementProposal::CREATE || $c['existing_plan_id'] === 0) !== []
            ? 'plan.replacement.several_with_new_days'
            : 'plan.replacement.several_existing';

        return $this->t($header)."\n"
            .implode("\n", array_map(fn (array $c) => '- '.$this->describeChange($c), $changes))
            ."\n".$this->t('plan.replacement.confirm_all', ['minutes' => $minutes]);
    }

    /** "Why didn't you add it?": nothing was written in this conversation, said from what the application recorded. */
    public function whyNotSaved(): string
    {
        return $this->t('coach.save.why_not_saved');
    }

    /** "Why didn't you add it?" after a blocked save: the application's own last notice about it, not a reason made up now. */
    public function whyNotSavedLast(string $notice): string
    {
        return $this->t('coach.save.why_not_saved_last', ['notice' => $notice]);
    }

    // ---- the answer to a "yes" to a held replacement ------------------------------------------------------------------------

    /** A reply to a question that is not clearly a yes (see Confirmation): nothing happened and the question is still open. */
    public function confirmationUnclear(): string
    {
        return $this->t('coach.confirmation.unclear');
    }

    public function confirmationInvalid(): string
    {
        return $this->t('coach.confirmation.invalid');
    }

    /** @param  string  $reason  why the change could not be made, as the replacer worded it (English) */
    public function confirmationRefused(string $reason): string
    {
        return $this->t('coach.confirmation.refused', ['reason' => $this->problem($reason)]);
    }

    public function confirmationApplied(PlanReplacementProposal $proposal): string
    {
        $lines = array_map(fn (array $c) => match (true) {
            $c['action'] === PlanReplacementProposal::CREATE => $this->t('coach.confirmation.added', ['replacement' => PlanReplacementProposal::summarise($c['replacement']), 'date' => $c['date']]),
            $c['action'] === PlanReplacementProposal::CLEAR && $c['existing_plan_id'] === 0 => $this->t('coach.confirmation.marked_rest', ['date' => $c['date']]),
            $c['action'] === PlanReplacementProposal::CLEAR => $this->t('coach.confirmation.turned_rest', ['date' => $c['date'], 'existing' => $this->existing((string) $c['existing_summary'])]),
            default => $this->t('coach.confirmation.replaced', ['existing' => $this->existing((string) $c['existing_summary']), 'date' => $c['date'], 'replacement' => PlanReplacementProposal::summarise($c['replacement'])]),
        }, $proposal->changes);

        return $this->join(...[$this->t('coach.confirmation.done'), ...$lines, $this->t('coach.confirmation.checked')]);
    }

    // ---- a plan of several days ------------------------------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $saved  the changes written
     * @param  array<int, string>  $already  dates that were already as shown
     * @param  array<int, string>  $omitted  days left out
     */
    public function weekSaved(array $saved, array $already, array $omitted): string
    {
        $lines = array_map(fn (array $c) => $this->t('plan.week.day_line', [
            'day' => $this->dayLabel($c['date']),
            'what' => $c['action'] === PlanReplacementProposal::CLEAR ? $this->t('plan.rest_day_short') : PlanReplacementProposal::summarise($c['replacement']),
        ]), $saved);

        $alreadyLine = $already === [] ? '' : $this->t(count($already) === 1 ? 'plan.week.already_one' : 'plan.week.already_many', ['dates' => implode(', ', $already)]);
        $omittedLine = $omitted === [] ? '' : $this->t('plan.week.omitted', ['omitted' => implode(', ', $omitted)]);

        return $this->join($this->t('plan.week.saved_header')."\n".implode("\n", $lines).($alreadyLine !== '' ? ' '.$alreadyLine : ''), $omittedLine);
    }

    /** "Friday, Nov 13" / "viernes, 13 de noviembre". */
    private function dayLabel(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $this->isEnglish() ? $day->format('l, M j') : $day->locale($this->locale->value)->isoFormat('dddd, D [de] MMMM');
    }

    /** @param array<int, array{date: string, label: string, reason: string}> $blocked */
    public function weekBlocked(array $blocked): string
    {
        $list = implode(', ', array_map(fn (array $b) => $this->t('plan.week.blocked_item', [
            'date' => $b['date'],
            'label' => $b['label'],
            'why' => $this->t($b['reason'] === 'started' ? 'plan.week.blocked_started' : 'plan.week.blocked_performed'),
        ]), $blocked));

        return $this->t('plan.week.blocked', ['list' => $list]);
    }

    /** @param array<int, string> $dates */
    public function weekAlready(array $dates): string
    {
        return $this->t('plan.week.already_everything', ['dates' => implode(', ', $dates)]);
    }

    /** @param  ?string  $problem  why it was not saved, as the application worded it (English), or null */
    public function weekNotSaved(?string $problem): string
    {
        return $problem === null
            ? $this->t('plan.week.not_saved')
            : $this->t('plan.week.not_saved_because', ['problem' => rtrim($this->problem($problem), '. ')]);
    }

    // ---- after a proposal, or none ------------------------------------------------------------------------------------------------

    private function explicit(): string
    {
        return $this->t('coach.follow_up.explicit');
    }

    public function confirmedChangeFailed(): string
    {
        return $this->t('coach.follow_up.confirmed_change_failed');
    }

    public function proposalNoLongerValid(): string
    {
        return $this->t('coach.follow_up.proposal_no_longer_valid', ['explicit' => $this->explicit()]);
    }

    public function nothingPending(bool $workoutWasDiscussed): string
    {
        return $this->join(
            $this->t('coach.follow_up.nothing_pending'),
            $workoutWasDiscussed ? $this->t('coach.follow_up.nothing_pending_discussed') : '',
            $this->explicit(),
            $this->t('coach.english_note'),
        );
    }

    public function explicitRequestNeeded(): string
    {
        return $this->t('coach.follow_up.explicit_request_needed', ['explicit' => $this->explicit()]);
    }

    public function changeProposed(PlanChangeProposal $proposal): string
    {
        return $this->t('coach.follow_up.change_proposed', ['describe' => $this->describeProposal($proposal), 'minutes' => PlanChangeProposal::TTL_MINUTES]);
    }

    /** A proposed change to one exercise's weight, described from the proposal's own (verified) fields. */
    public function describeProposal(PlanChangeProposal $proposal): string
    {
        return $this->t('plan.proposal.set_target', [
            'exercise' => $proposal->exerciseName,
            'kg' => $this->kg($proposal->plannedWeightKg),
            'amount' => $proposal->plannedSets !== null && $proposal->plannedReps !== null ? " ({$proposal->plannedSets}×{$proposal->plannedReps})" : '',
            'date' => $proposal->date,
        ]);
    }

    /** @param array{requested_kg: float, recorded_kg: float, maximum_kg: float} $detail */
    public function aboveMaximumChange(array $detail): string
    {
        return $this->t('coach.follow_up.above_maximum_change', [
            'requested' => $this->kg($detail['requested_kg']),
            'recorded' => $this->kg($detail['recorded_kg']),
            'maximum' => $this->kg($detail['maximum_kg']),
        ]);
    }

    public function changeNotSpecific(): string
    {
        return $this->t('coach.follow_up.change_not_specific', ['explicit' => $this->explicit()]);
    }

    private function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    // ---- corrections of what the model claimed ------------------------------------------------------------------------------------

    public function noAccessCorrection(bool $addNothingChanged): string
    {
        return $this->join(
            $this->t('coach.correction.no_access_intro'),
            $addNothingChanged ? $this->t('coach.correction.nothing_changed') : '',
            $this->t('coach.correction.no_access_how'),
        );
    }

    public function notSavedCorrection(): string
    {
        return $this->t('coach.correction.not_saved');
    }

    public function partialCorrection(): string
    {
        return $this->t('coach.correction.partial');
    }

    // ---- notes the protected modules word in English: shown in this language -----------------------------------------------------------

    /** What the application tells the user about loads it left unset (English as the load guard worded it, or null). */
    public function loadsLeftUnset(?string $english): ?string
    {
        if ($english === null || $this->isEnglish()) {
            return $english;
        }

        if (preg_match('/^I left the starting weight for (.+) unset: there is no recorded or planned weight to base one on\. Choose a weight you can lift comfortably for the target reps, and record it when you log your sets\.$/us', $english, $m) === 1) {
            return $this->t('coach.load_left_unset', ['names' => $m[1]]);
        }

        return $english;
    }

    /** What the user is told when a range was stored as one number (English as the scope worded it, or null). */
    public function rangeNote(?string $english): ?string
    {
        if ($english === null || $this->isEnglish()) {
            return $english;
        }

        if (preg_match('/^Your plan keeps one number for each of these, so I saved the low end of every range: (.+)\. You can change them in the plan\.$/us', $english, $m) !== 1) {
            return $english;
        }

        $parts = [];

        foreach (explode('; ', $m[1]) as $part) {
            if (preg_match('/^(?:('.self::DATE.') )?(.+?) ((?:\d+ (?:sets|reps|seconds) \(written \d+-\d+\))(?:, \d+ (?:sets|reps|seconds) \(written \d+-\d+\))*)$/us', $part, $p) !== 1) {
                return $english; // a shape that is not understood is shown as it was written, never guessed at
            }

            preg_match_all('/(\d+) (sets|reps|seconds) \(written (\d+)-(\d+)\)/u', $p[3], $said, PREG_SET_ORDER);

            $parts[] = ($p[1] !== '' ? $p[1].' ' : '').$this->t('plan.range.part', [
                'exercise' => $p[2],
                'said' => implode(', ', array_map(fn (array $s) => $this->t('plan.range.said', ['amount' => $s[1], 'unit' => $this->t('plan.range.'.$s[2]), 'low' => $s[3], 'high' => $s[4]]), $said)),
            ]);
        }

        return $this->t('coach.range_note', ['parts' => implode('; ', $parts)]);
    }

    /**
     * Why a change could not be made, as the replacer or the load guard worded it in English. English passes through
     * untouched; another language gets the matching reason, or a generic one for any wording that is not known.
     */
    private function problem(string $english): string
    {
        if ($this->isEnglish()) {
            return $english;
        }

        $date = '('.self::DATE.')';

        $dated = [
            "/^a plan appeared on {$date} after I asked\\.$/" => 'plan_appeared',
            "/^the plan I asked about for {$date} is no longer there\\.$/" => 'plan_gone',
            "/^the plan for {$date} changed after I asked\\.$/" => 'plan_changed',
            "/^{$date} already has recorded workout performance, so its plan can no longer be replaced\\.$/" => 'performed',
            "/^{$date} was not saved\\.$/" => 'not_saved',
            "/^{$date} was not saved as a rest day\\.$/" => 'not_saved_rest',
            "/^{$date} does not hold what was shown\\.$/" => 'not_as_shown',
        ];

        foreach ($dated as $pattern => $key) {
            if (preg_match($pattern, $english, $m) === 1) {
                return $this->t('plan.reason.'.$key, ['date' => $m[1]]);
            }
        }

        if ($english === 'something went wrong while saving, so none of the days were saved.') {
            return $this->t('plan.reason.went_wrong');
        }

        return $this->loadReasons($english) ?? $this->t('plan.reason.unknown');
    }

    /** For the weekly-plan API: the load guard's refusals said in this language, or null (English, or any not a known wording). */
    public function recognisedLoadRefusal(string $english): ?string
    {
        return $this->isEnglish() ? null : $this->loadReasons($english);
    }

    /** The load guard's refusals (one or several, in English) as plain sentences; null if any is not a known one. */
    private function loadReasons(string $english): ?string
    {
        $sentences = [];

        foreach (preg_split('/(?=planned_weight_kg )/u', trim($english), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if (preg_match('/^planned_weight_kg ([\d.]+) for "(.*?)" exceeds the maximum permitted change: the heaviest load recorded for it in the last (\d+) days is ([\d.]+) kg, and one plan change may raise it by at most (\d+)% \(([\d.]+) kg\)\. That limit caps how large a change may be; it does not mean a load up to it is appropriate\. Use [\d.]+ kg or less, and tell the user why\.$/us', $chunk, $m) === 1) {
                $sentences[] = $this->t('plan.reason.above_limit', ['load' => $m[1], 'exercise' => $m[2], 'days' => $m[3], 'recorded' => $m[4], 'percent' => $m[5], 'maximum' => $m[6]]);
            } elseif (preg_match('/^planned_weight_kg ([\d.]+) for "(.*?)" on ('.self::DATE.') would raise its load above the ([\d.]+) kg the user last performed or already has planned for that day\. Loads only go up when the user approves a calculated suggestion or explicitly asks for the change, so nothing was changed\. Use [\d.]+ kg or less; if a heavier load looks right, say that a suggestion can be reviewed on the Weekly Plan screen\.$/us', $chunk, $m) === 1) {
                $sentences[] = $this->t('plan.reason.above_baseline', ['load' => $m[1], 'exercise' => $m[2], 'date' => $m[3], 'baseline' => $m[4]]);
            } else {
                return null;
            }
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    // ---- other surfaces -----------------------------------------------------------------------------------------------------------------

    public function weeklyNoChange(): string
    {
        return $this->t('coach.weekly.no_change');
    }

    public function weeklyNotDetermined(): string
    {
        return $this->t('coach.weekly.not_determined');
    }

    public function dashboardFallback(): string
    {
        return $this->t('coach.dashboard_fallback');
    }
}
