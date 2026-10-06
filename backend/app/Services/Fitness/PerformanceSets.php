<?php

namespace App\Services\Fitness;

/**
 * Pure helpers for per-set performance data. Sets are the authoritative record;
 * the reps/weight columns on workout_exercises are only ever a summary derived
 * from them here.
 */
final class PerformanceSets
{
    public const MAX_SETS = 50;

    /**
     * Expand a "3 sets of 10 at 40 kg" tuple into one row per set. Returns no
     * rows when there is nothing to record, and never invents a set count:
     * when $sets is missing, $assumeOneSet decides (true only for NEW user
     * input such as "bench 40 x 10"; never for historical data).
     *
     * @return array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>
     */
    public static function expand(?int $sets, ?int $reps, ?float $weightKg, ?int $durationSeconds, bool $assumeOneSet = false): array
    {
        if ($reps === null && $weightKg === null && $durationSeconds === null) {
            return [];
        }

        $count = $sets ?? ($assumeOneSet ? 1 : 0);

        if ($count < 1) {
            return [];
        }

        return array_fill(0, min($count, self::MAX_SETS), [
            'reps' => $reps,
            'weight_kg' => $weightKg,
            'duration_seconds' => $durationSeconds,
            'completed' => true,
        ]);
    }

    /**
     * Normalize client-supplied sets (already validated) to a clean list.
     *
     * @param  array<int, array<string, mixed>>  $sets
     * @return array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>
     */
    public static function normalize(array $sets): array
    {
        $out = [];

        foreach (array_values($sets) as $set) {
            $out[] = [
                'reps' => isset($set['reps']) ? (int) $set['reps'] : null,
                'weight_kg' => isset($set['weight_kg']) ? (float) $set['weight_kg'] : null,
                'duration_seconds' => isset($set['duration_seconds']) ? (int) $set['duration_seconds'] : null,
                'completed' => (bool) ($set['completed'] ?? true),
            ];
        }

        return array_slice($out, 0, self::MAX_SETS);
    }

    /**
     * The summary kept on workout_exercises for backward compatibility: the
     * number of completed sets, and the top set's reps and weight (heaviest,
     * ties broken by reps), and the longest duration.
     *
     * @param  iterable<int, array<string, mixed>|object>  $sets
     * @return array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int}
     */
    public static function summarize(iterable $sets): array
    {
        $completed = [];

        foreach ($sets as $set) {
            $set = (array) (is_object($set) && method_exists($set, 'toArray') ? $set->toArray() : $set);

            if ($set['completed'] ?? true) {
                $completed[] = $set;
            }
        }

        if ($completed === []) {
            return ['sets' => null, 'reps' => null, 'weight_kg' => null, 'duration_seconds' => null];
        }

        usort($completed, fn ($a, $b) => [
            (float) ($b['weight_kg'] ?? -1), (int) ($b['reps'] ?? -1),
        ] <=> [
            (float) ($a['weight_kg'] ?? -1), (int) ($a['reps'] ?? -1),
        ]);

        $top = $completed[0];
        $durations = array_filter(array_map(fn ($s) => $s['duration_seconds'] ?? null, $completed), fn ($d) => $d !== null);

        return [
            'sets' => count($completed),
            'reps' => isset($top['reps']) ? (int) $top['reps'] : null,
            'weight_kg' => isset($top['weight_kg']) ? (float) $top['weight_kg'] : null,
            'duration_seconds' => $durations === [] ? null : (int) max($durations),
        ];
    }
}
