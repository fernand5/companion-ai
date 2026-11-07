<?php

namespace App\Services\Ai\Authorization;

/**
 * Which multi-day plan "add this plan to my dashboard" means, as far as the conversation can establish it: either the
 * exact days (each a date, and a workout or a rest day), or the reason it cannot be established. Never a guess.
 */
final class WeekPlan
{
    /** Exactly the days to save, every one with a date, and a workout or a rest day. */
    public const FOUND = 'found';

    /** The text is not a plan of several days: the single-workout path reads it. */
    public const NOT_A_WEEK = 'not_a_week';

    /** A day has no usable content (nothing listed and not a rest day, cut off, or labelled a rest day yet listing work). */
    public const UNCLEAR_DAY = 'unclear_day';

    /** An exercise is "A or B" and the user has not said which. */
    public const CHOICE = 'choice';

    /** Some of the days have already passed, so the user has to say which days to add. */
    public const PAST_DAYS = 'past_days';

    /** The days cannot be put on dates: a date that is not that weekday, a day twice, "next week" with no dates, too far away. */
    public const DATES = 'dates';

    /** Somebody else's words, quoted. */
    public const QUOTED = 'quoted';

    /** More days than one proposal covers. */
    public const TOO_MANY = 'too_many';

    public const KIND_WORKOUT = 'workout';

    public const KIND_REST = 'rest';

    /**
     * @param  array<int, array{date: string, label: string, kind: string, items: array<int, string>}>  $days  by date
     * @param  array<int, array{item: string, options: array<int, string>, day: string}>  $choices
     * @param  array<int, string>  $problems  what the question to the user names (day labels, dates)
     * @param  array<int, string>  $omitted  days of the plan that the user's own words left out
     */
    private function __construct(
        public readonly string $status,
        public readonly array $days = [],
        public readonly array $choices = [],
        public readonly array $problems = [],
        public readonly string $detail = '',
        public readonly string $source = '',
        public readonly array $omitted = [],
    ) {}

    /**
     * @param  array<int, array{date: string, label: string, kind: string, items: array<int, string>}>  $days
     * @param  array<int, string>  $omitted
     */
    public static function found(array $days, string $source, array $omitted = []): self
    {
        return new self(self::FOUND, $days, source: $source, omitted: $omitted);
    }

    public static function notAWeek(): self
    {
        return new self(self::NOT_A_WEEK);
    }

    /** @param array<int, string> $problems */
    public static function unresolved(string $status, array $problems = [], string $detail = ''): self
    {
        return new self($status, problems: $problems, detail: $detail);
    }

    /** @param array<int, array{item: string, options: array<int, string>, day: string}> $choices */
    public static function needsChoice(array $choices): self
    {
        return new self(self::CHOICE, choices: $choices);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    public function isAWeek(): bool
    {
        return $this->status !== self::NOT_A_WEEK;
    }
}
