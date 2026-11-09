<?php

namespace App\Services\Ai\Authorization;

use Carbon\CarbonImmutable;

/**
 * "Add this plan to my dashboard" authorizes ONE thing: these days, with this content, in this conversation. Resolved by
 * the application from the user's words and the plan they were shown (see WeekPlanReference) before the model is
 * called, and immutable: the model cannot add a day, drop a day, move a day, or change what is on one.
 *
 * It states what a propose_weekly_plan_changes call may contain. Exactly the plan's dates, once each; a workout day is
 * a "replace" whose exercises are exactly the lines of that day (the same check as a single saved workout, see
 * SaveWorkoutScope, with sets, reps and seconds as written and a range stored as its low end); a rest day is a
 * "clear" and has no exercises. Loads are not in this: they stay under PlanLoadGuard.
 */
final class WeekSaveScope
{
    /**
     * @param  array<int, array{date: string, label: string, kind: string, items: array<int, string>}>  $days  by date
     * @param  array<int, string>  $omitted  days of the plan the user's own words left out, said back to them when the rest is saved
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $days,
        public readonly string $source,
        public readonly array $omitted = [],
    ) {}

    /** @return array<int, string> */
    public function dates(): array
    {
        return array_column($this->days, 'date');
    }

    /** Null when $changes are within this scope; otherwise what is outside it. */
    public function mismatch(mixed $changes): ?string
    {
        if (! is_array($changes) || $changes === []) {
            return 'changes must list every day of the plan.';
        }

        $byDate = [];

        foreach ($changes as $i => $change) {
            $date = is_array($change) ? self::dateOf($change['date'] ?? null) : null;

            if ($date === null) {
                return "change {$i} has no valid date.";
            }

            if (isset($byDate[$date])) {
                return "{$date} is listed twice.";
            }

            if (! in_array($date, $this->dates(), true)) {
                return "{$date} is not one of the days the user asked to save (".implode(', ', $this->dates()).').';
            }

            $byDate[$date] = $change;
        }

        foreach ($this->days as $day) {
            $change = $byDate[$day['date']] ?? null;

            if ($change === null) {
                return "{$day['date']} ({$day['label']}) is missing: include every day of the plan, and only those.";
            }

            if ($day['kind'] === WeekPlan::KIND_REST) {
                if (($change['action'] ?? null) !== 'clear' || ! empty($change['exercises'])) {
                    return "{$day['date']} ({$day['label']}) is a rest day: action must be \"clear\", with no exercises.";
                }

                continue;
            }

            if (($change['action'] ?? null) !== 'replace') {
                return "{$day['date']} ({$day['label']}) is a workout: action must be \"replace\".";
            }

            if (! is_array($change['exercises'] ?? null) || $change['exercises'] === []) {
                return "{$day['date']} ({$day['label']}): list its exercises, exactly as written: ".implode(' | ', array_map(fn (string $l) => mb_substr($l, 0, 80), $day['items'])).'.';
            }

            if (($problem = $this->dayScope($day)->mismatch(['planned_date' => $day['date'], 'exercises' => $change['exercises']])) !== null) {
                return "{$day['date']} ({$day['label']}): {$problem}";
            }
        }

        return null;
    }

    /**
     * The changes with the application's own values: each date as Y-m-d, and a range stored as its low end.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public function normalise(array $changes): array
    {
        $out = [];

        foreach ($changes as $change) {
            $date = (string) self::dateOf($change['date'] ?? null);
            $change['date'] = $date;

            foreach ($this->days as $day) {
                if ($day['date'] === $date && $day['kind'] === WeekPlan::KIND_WORKOUT) {
                    $change['exercises'] = $this->dayScope($day)->normalise(['exercises' => $change['exercises'] ?? []])['exercises'] ?? [];
                }
            }

            $out[] = $change;
        }

        return $out;
    }

    /** What to tell the model to send: each day, its action and its exercises as written. */
    public function describe(): string
    {
        return implode(' || ', array_map(
            fn (array $d) => $d['date'].' ('.$d['label'].'): '.($d['kind'] === WeekPlan::KIND_REST
                ? 'action "clear" (a rest day, no exercises)'
                : 'action "replace" with exactly these exercises, each as written: '.implode(' | ', array_map(fn (string $l) => mb_substr($l, 0, 80), $d['items']))),
            $this->days,
        ));
    }

    /** What the user is told about ranges stored as one number across the days saved, or null. */
    public function rangeNote(array $changes): ?string
    {
        $parts = [];

        foreach ($changes as $change) {
            foreach ($this->days as $day) {
                if ($day['date'] === ($change['date'] ?? null) && $day['kind'] === WeekPlan::KIND_WORKOUT) {
                    foreach ($this->dayScope($day)->rangeParts(['exercises' => $change['exercises'] ?? []]) as $part) {
                        $parts[] = $day['date'].' '.$part;
                    }
                }
            }
        }

        return $parts === [] ? null : 'Your plan keeps one number for each of these, so I saved the low end of every range: '.implode('; ', $parts).'. You can change them in the plan.';
    }

    /** @param array{date: string, label: string, kind: string, items: array<int, string>} $day */
    private function dayScope(array $day): SaveWorkoutScope
    {
        return new SaveWorkoutScope($this->conversationId, $day['date'], $this->source, $day['items'], false);
    }

    private static function dateOf(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
