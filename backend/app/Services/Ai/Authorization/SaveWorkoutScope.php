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

    /** Sets, reps and durations may only be numbers that are on the workout's own line. */
    private function amountsOutsideTheLine(array $exercise, string $line): ?string
    {
        preg_match_all('/\d+/u', $line, $m);
        $numbers = array_map('intval', $m[0]);
        $minutes = (bool) preg_match('/\bmin(?:ute)?s?\b/iu', $line);

        foreach (['planned_sets' => 'sets', 'planned_reps' => 'reps'] as $field => $label) {
            $value = $exercise[$field] ?? null;

            if ($value !== null && $value !== '' && (! is_numeric($value) || ! in_array((int) $value, $numbers, true))) {
                return "{$label} must be as written in the workout (\"".mb_substr($line, 0, 80).'").';
            }
        }

        $seconds = $exercise['planned_duration_seconds'] ?? null;

        if ($seconds !== null && $seconds !== '') {
            $allowed = $minutes ? [...$numbers, ...array_map(fn (int $n) => $n * 60, $numbers)] : $numbers;

            if (! is_numeric($seconds) || ! in_array((int) $seconds, $allowed, true)) {
                return 'the duration must be as written in the workout ("'.mb_substr($line, 0, 80).'").';
            }
        }

        return null;
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
