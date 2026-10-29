<?php

namespace App\Services\Ai\Authorization;

use Carbon\CarbonImmutable;

/**
 * "Add this workout to my dashboard" authorizes ONE thing: this workout, on this day. Resolved by the application
 * from the user's words and the conversation before the model is called, and immutable: the model cannot widen it.
 *
 * It states what a create_workout_plan call may contain. The day is the application's, not the model's; every
 * exercise must be one of the lines of the referenced workout, with sets, reps and durations that appear on that
 * line; and none of the workout's lines may be dropped. Loads are not in this: they stay under PlanLoadGuard.
 */
final class SaveWorkoutScope
{
    /** Words that are not part of an exercise's name. */
    private const FILLER = ['the', 'a', 'an', 'of', 'with', 'and', 'or', 'on', 'in', 'to', 'per', 'each', 'side', 'hold', 'for', 'x', 'set', 'sets', 'rep', 'reps'];

    /** @param array<int, string> $items the exercise lines of the referenced workout */
    public function __construct(
        public readonly int $conversationId,
        public readonly string $date,
        public readonly string $source,
        public readonly array $items,
        public readonly bool $replacementRequested,
    ) {}

    /** Null when $arguments is within this scope; otherwise what is outside it. */
    public function mismatch(array $arguments): ?string
    {
        $given = $arguments['planned_date'] ?? null;

        if ($given !== null && $given !== '') {
            try {
                $normalised = is_string($given) ? CarbonImmutable::parse($given)->toDateString() : null;
            } catch (\Throwable) {
                $normalised = null;
            }

            if ($normalised !== $this->date) {
                return 'planned_date must be '.$this->date.', the day the user asked for.';
            }
        }

        $exercises = $arguments['exercises'] ?? [];

        if (! is_array($exercises)) {
            return 'exercises must be a list.';
        }

        if ($exercises === []) {
            return count($this->items) > 1 ? 'list every exercise of the workout, and only those.' : null;
        }

        $unused = $this->items;

        foreach ($exercises as $i => $exercise) {
            if (! is_array($exercise) || ! is_string($exercise['exercise_name'] ?? null)) {
                return "exercise {$i} is malformed.";
            }

            $matched = null;

            foreach ($unused as $key => $item) {
                if ($this->nameIsOn($exercise['exercise_name'], $item)) {
                    $matched = $key;

                    break;
                }
            }

            if ($matched === null) {
                return "\"{$exercise['exercise_name']}\" is not one of the exercises in the workout the user asked to save.";
            }

            if (($problem = $this->amountsOutsideTheLine($exercise, $unused[$matched])) !== null) {
                return "{$exercise['exercise_name']}: {$problem}";
            }

            unset($unused[$matched]);
        }

        if ($unused !== []) {
            return 'the workout also has: '.implode(' | ', array_map(fn (string $line) => mb_substr($line, 0, 80), array_values($unused))).'. Include every exercise of the workout.';
        }

        return null;
    }

    /** The lines a call must cover, for telling the model what to send. */
    public function describeItems(): string
    {
        return implode(' | ', array_map(fn (string $line) => mb_substr($line, 0, 80), $this->items));
    }

    private function nameIsOn(string $name, string $line): bool
    {
        $name = (string) preg_replace('/\([^)]*\)/u', ' ', $name);
        $tokens = array_values(array_filter(
            array_map([self::class, 'stem'], $this->words($name)),
            fn (string $t) => $t !== '' && ! in_array($t, self::FILLER, true),
        ));

        if ($tokens === []) {
            return false;
        }

        $lineStems = array_map([self::class, 'stem'], $this->words($line));
        $squashed = (string) preg_replace('/[^\p{L}]+/u', '', mb_strtolower($line));

        foreach ($tokens as $token) {
            if (! in_array($token, $lineStems, true) && (mb_strlen($token) < 4 || ! str_contains($squashed, $token))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sets, reps and durations must be what the workout's own line prescribes. Where it gives one number, that number.
     * Where it gives a range ("3 sets of 8-12 reps"), anything inside it is accepted from the model and then replaced by
     * the application's own value (see normalise()); a number outside it is rejected.
     */
    private function amountsOutsideTheLine(array $exercise, string $line): ?string
    {
        $prescribed = self::prescription($line);

        preg_match_all('/\d+/u', $line, $m);
        $numbers = array_map('intval', $m[0]);
        $minutes = (bool) preg_match('/\bmin(?:ute)?s?\b/iu', $line);

        foreach (['planned_sets' => ['sets', 'sets'], 'planned_reps' => ['reps', 'reps'], 'planned_duration_seconds' => ['seconds', 'duration']] as $field => [$key, $label]) {
            $value = $exercise[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $range = $prescribed[$key];

            if ($range !== null) {
                if (! is_numeric($value) || (int) $value < $range[0] || (int) $value > $range[1]) {
                    return "the {$label} must be ".($range[0] === $range[1] ? $range[0] : "{$range[0]}-{$range[1]}").' as written in the workout ("'.mb_substr($line, 0, 80).'").';
                }

                continue;
            }

            // The line does not say which of its numbers is which: the value has to be one of them.
            $scale = $key === 'seconds' && $minutes ? 60 : 1;

            if (! is_numeric($value) || ! (in_array((int) $value, $numbers, true) || in_array((int) $value, array_map(fn (int $n) => $n * $scale, $numbers), true))) {
                return "the {$label} must be as written in the workout (\"".mb_substr($line, 0, 80).'").';
            }
        }

        return null;
    }

    /**
     * The plan stores one whole number for sets, reps and seconds, so a range cannot be kept as written. The policy is
     * fixed and the application's, never the model's: a range is stored as its LOW end, whatever the model sent for it
     * (inside the range) or left out. A number outside the range was already refused by mismatch().
     */
    public function normalise(array $arguments): array
    {
        $exercises = $arguments['exercises'] ?? null;

        if (! is_array($exercises)) {
            return $arguments;
        }

        foreach ($this->matched($exercises) as $index => $key) {
            foreach (['planned_sets' => 'sets', 'planned_reps' => 'reps', 'planned_duration_seconds' => 'seconds'] as $field => $part) {
                $range = self::prescription($this->items[$key])[$part];

                if ($range !== null && $range[0] !== $range[1]) {
                    $arguments['exercises'][$index][$field] = $range[0];
                }
            }
        }

        return $arguments;
    }

    /**
     * What the user is told when a range had to be stored as one number, or null when nothing was a range.
     * Said by the application in its own words, from the arguments that were actually saved.
     */
    public function rangeNote(array $arguments): ?string
    {
        $exercises = $arguments['exercises'] ?? null;
        $parts = [];

        if (! is_array($exercises)) {
            return null;
        }

        foreach ($this->matched($exercises) as $index => $key) {
            $prescribed = self::prescription($this->items[$key]);
            $said = [];

            foreach (['planned_sets' => ['sets', 'sets'], 'planned_reps' => ['reps', 'reps'], 'planned_duration_seconds' => ['seconds', 'seconds']] as $field => [$part, $unit]) {
                $range = $prescribed[$part];

                if ($range !== null && $range[0] !== $range[1] && isset($exercises[$index][$field])) {
                    $said[] = "{$range[0]} {$unit} (written {$range[0]}-{$range[1]})";
                }
            }

            if ($said !== []) {
                $parts[] = self::nameOf($this->items[$key]).' '.implode(', ', $said);
            }
        }

        if ($parts === []) {
            return null;
        }

        return 'Your plan keeps one number for each of these, so I saved the low end of every range: '.implode('; ', $parts).'. You can change them in the plan.';
    }

    /**
     * The sets, reps and seconds a workout line prescribes, each as [low, high] (equal when it is one number) or null
     * when the line does not say. "3 x 8-12 reps", "2-3 sets x 10-12 reps", "3 x 45 seconds", "3 sets of 10 squats",
     * "30-45s", "2 minutes".
     *
     * @return array{sets: ?array{0: int, 1: int}, reps: ?array{0: int, 1: int}, seconds: ?array{0: int, 1: int}}
     */
    public static function prescription(string $line): array
    {
        $text = ' '.mb_strtolower(str_replace(['–', '—', '−', '×', '✕', '*'], ['-', '-', '-', 'x', 'x', ''], $line)).' ';
        $found = ['sets' => null, 'reps' => null, 'seconds' => null];
        $range = static fn (string $low, string $high = '', int $scale = 1): array => [
            min((int) $low, (int) ($high === '' ? $low : $high)) * $scale,
            max((int) $low, (int) ($high === '' ? $low : $high)) * $scale,
        ];
        $number = '(\d+)(?:\s*(?:-|to)\s*(\d+))?';
        $seconds = 'seconds?|secs?|s';
        $minutes = 'minutes?|mins?';
        $isMinutes = static fn (string $unit): bool => (bool) preg_match("/^(?:{$minutes})$/u", $unit);

        // 3 x 8-12 (reps), 3 x 45 seconds, 3 x 2 minutes
        $text = (string) preg_replace_callback("/(?<![\d.]){$number}\s*x\s*{$number}(?:[\s-]*({$seconds}|{$minutes})\b)?/u", function (array $m) use (&$found, $range, $isMinutes) {
            $found['sets'] = $range($m[1], $m[2] ?? '');
            $unit = $m[5] ?? '';

            if ($unit === '') {
                $found['reps'] = $range($m[3], $m[4] ?? '');
            } else {
                $found['seconds'] = $range($m[3], $m[4] ?? '', $isMinutes($unit) ? 60 : 1);
            }

            return ' ';
        }, $text, 1);

        if ($found['seconds'] === null) {
            $text = (string) preg_replace_callback("/(?<![\d.]){$number}[\s-]*({$seconds}|{$minutes})\b/u", function (array $m) use (&$found, $range, $isMinutes) {
                $found['seconds'] = $range($m[1], $m[2] ?? '', $isMinutes($m[3]) ? 60 : 1);

                return ' ';
            }, $text, 1);
        }

        if ($found['reps'] === null) {
            $text = (string) preg_replace_callback("/(?<![\d.]){$number}\s*(?:reps?|repetitions?)\b/u", function (array $m) use (&$found, $range) {
                $found['reps'] = $range($m[1], $m[2] ?? '');

                return ' ';
            }, $text, 1);
        }

        if ($found['sets'] === null) {
            // "3 sets", "3 sets of 10 squats" (the number after "of" is the reps, when nothing else said so)
            preg_replace_callback("/(?<![\d.]){$number}\s*sets?\b(?:\s*of\s*{$number}\b)?/u", function (array $m) use (&$found, $range) {
                $found['sets'] = $range($m[1], $m[2] ?? '');

                if (isset($m[3]) && $m[3] !== '' && $found['reps'] === null) {
                    $found['reps'] = $range($m[3], $m[4] ?? '');
                }

                return ' ';
            }, $text, 1);
        }

        return $found;
    }

    /** The exercises in $exercises that match a line of the workout, each to the line it matched: [exercise index => line key]. */
    private function matched(array $exercises): array
    {
        $unused = $this->items;
        $matched = [];

        foreach ($exercises as $i => $exercise) {
            if (! is_array($exercise) || ! is_string($exercise['exercise_name'] ?? null)) {
                continue;
            }

            foreach ($unused as $key => $item) {
                if ($this->nameIsOn($exercise['exercise_name'], $item)) {
                    $matched[$i] = $key;
                    unset($unused[$key]);

                    break;
                }
            }
        }

        return $matched;
    }

    /** "Dumbbell Floor Press: 3 sets of 8-12 reps" and "Plank 3 x 30-45 s" are named "Dumbbell Floor Press" and "Plank". */
    private static function nameOf(string $line): string
    {
        $name = trim((string) preg_replace('/\s*(?::|\d).*$/su', '', str_replace('*', '', $line)), " \t-–—•.");

        return $name !== '' ? $name : mb_substr($line, 0, 40);
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        return preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function stem(string $word): string
    {
        if (str_ends_with($word, 'sses')) {
            return substr($word, 0, -2);
        }

        return mb_strlen($word) > 2 && ! str_ends_with($word, 'ss') ? rtrim($word, 's') : $word;
    }
}
