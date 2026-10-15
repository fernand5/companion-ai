<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;

/**
 * Small, pure comparisons between recorded sessions, computed in application
 * code so the model quotes facts instead of doing arithmetic.
 *
 * It states what changed and when the two sessions are not like-for-like. It
 * never says "better", "stronger" or "fitter", never reads a planned or assumed
 * number as performed, and never merges exercises: only records that are
 * measured evidence (entered / migrated), for the exact same exercise, and of the
 * same kind (repetition-based or duration-based) are compared at all.
 *
 * Read-only: no database access and nothing that changes a plan or a load.
 */
final class PerformanceComparison
{
    /**
     * What the completed sets of one measured record add up to.
     *
     * @return array{completed_sets: int, total_reps: ?int, top_load_kg: ?float, volume_kg: ?float, total_seconds: ?int, top_seconds: ?int, timed: bool, incomplete: bool}
     */
    public static function metrics(ExercisePerformanceRecord $record): array
    {
        $done = array_values(array_filter($record->sets, fn (array $set) => $set['completed']));

        $weights = array_filter(array_column($done, 'weight_kg'), fn ($w) => $w !== null);
        $durations = array_filter(array_column($done, 'duration_seconds'), fn ($d) => $d !== null);
        $hasReps = array_filter(array_column($done, 'reps'), fn ($r) => $r !== null) !== [];

        $volume = null;
        if ($hasReps && $done !== [] && count($weights) === count($done)) {
            $volume = round(array_sum(array_map(fn (array $set) => ($set['reps'] ?? 0) * $set['weight_kg'], $done)), 1);
        }

        return [
            'completed_sets' => count($done),
            'total_reps' => $hasReps ? (int) array_sum(array_map(fn (array $set) => $set['reps'] ?? 0, $done)) : null,
            'top_load_kg' => $weights !== [] ? (float) max($weights) : null,
            'volume_kg' => $volume,
            'total_seconds' => $durations !== [] ? (int) array_sum($durations) : null,
            'top_seconds' => $durations !== [] ? (int) max($durations) : null,
            'timed' => $record->isTimedOnly(),
            'incomplete' => $record->outcome === ExercisePerformanceRecord::OUTCOME_PARTIAL || count($record->sets) > count($done),
        ];
    }

    /**
     * Compare two sessions of the same exercise (previous, then latest).
     *
     * `like_for_like` is false when the two can be compared but their totals cannot be
     * set side by side (an incomplete session, a different number of sets, load recorded
     * in only one); such a pair can be described but never supports a trend.
     *
     * @return array{comparable: bool, like_for_like: bool, reasons: string[], caveats: string[], previous: array, latest: array, change: array}
     */
    public static function strength(ExercisePerformanceRecord $previous, ExercisePerformanceRecord $latest): array
    {
        $before = self::metrics($previous);
        $after = self::metrics($latest);

        $reasons = [];

        if ($previous->exerciseSlug === null || $previous->exerciseSlug !== $latest->exerciseSlug) {
            $reasons[] = 'different exercises';
        }

        if (! $previous->performanceMeasured) {
            $reasons[] = 'the earlier session has no measured performance';
        }

        if (! $latest->performanceMeasured) {
            $reasons[] = 'the later session has no measured performance';
        }

        if ($reasons === [] && $before['timed'] !== $after['timed']) {
            $reasons[] = 'one is duration-based and the other repetition-based';
        }

        $caveats = [];
        $structural = false;

        if ($reasons === []) {
            if ($before['incomplete'] || $after['incomplete']) {
                $caveats[] = 'a session was incomplete, so totals are not like-for-like';
                $structural = true;
            }

            if ($before['completed_sets'] !== $after['completed_sets']) {
                $caveats[] = "the number of sets differs ({$before['completed_sets']} vs {$after['completed_sets']}), so totals are not like-for-like";
                $structural = true;
            }

            if ($previous->recordedAs === 'migrated' || $latest->recordedAs === 'migrated') {
                $caveats[] = 'migrated data may be incomplete';
            }

            if (! $after['timed'] && ($before['top_load_kg'] === null) !== ($after['top_load_kg'] === null)) {
                $caveats[] = 'load was not recorded in both sessions';
                $structural = true;
            }
        }

        $diff = fn (?float $a, ?float $b) => $a !== null && $b !== null ? round($b - $a, 1) : null;

        return [
            'comparable' => $reasons === [],
            'reasons' => $reasons,
            'caveats' => $caveats,
            'like_for_like' => $reasons === [] && ! $structural,
            'previous' => $before + ['date' => $previous->date],
            'latest' => $after + ['date' => $latest->date],
            'change' => $reasons !== [] ? [] : [
                'completed_sets' => $after['completed_sets'] - $before['completed_sets'],
                'total_reps' => $diff($before['total_reps'], $after['total_reps']),
                'top_load_kg' => $diff($before['top_load_kg'], $after['top_load_kg']),
                'volume_kg' => $diff($before['volume_kg'], $after['volume_kg']),
                'total_seconds' => $diff($before['total_seconds'], $after['total_seconds']),
                'top_seconds' => $diff($before['top_seconds'], $after['top_seconds']),
            ],
        ];
    }

    /**
     * One exercise's history (newest first) as: how many sessions are real
     * evidence, how the latest two compare, and whether a direction is consistent
     * enough to call a trend. One or two sessions are never a trend; three or more
     * need the last two comparisons to move the same way, on volume (reps x load)
     * where load is recorded and on total reps (or time) where it is not.
     *
     * @param  array<int, ExercisePerformanceRecord>  $records  newest first
     * @return array{measured_sessions: int, comparison: array|null, consistent_direction: string|null, sustained_trend_supported: bool, trend: string, summary: string|null}
     */
    public static function series(array $records): array
    {
        $measured = array_values(array_filter($records, fn (ExercisePerformanceRecord $r) => $r->performanceMeasured));
        $n = count($measured);

        if ($n < 2) {
            return [
                'measured_sessions' => $n,
                'comparison' => null,
                'consistent_direction' => null,
                'sustained_trend_supported' => false,
                'trend' => $n === 0 ? 'no measured sessions' : 'only 1 measured session: no comparison or trend',
                'summary' => null,
            ];
        }

        $latest = self::strength($measured[1], $measured[0]);
        $direction = null;
        $trend = "{$n} measured sessions: a comparison, not a trend";

        if ($n >= 3) {
            $earlier = self::strength($measured[2], $measured[1]);

            if (! $latest['like_for_like'] || ! $earlier['like_for_like']) {
                $trend = "{$n} measured sessions, but the recent ones are not like-for-like (incomplete, a different number of sets, or not comparable): no trend";
            } else {
                [$signal, $unit] = self::signal($latest, $earlier);
                $a = $latest['change'][$signal];
                $b = $earlier['change'][$signal];

                if ($a !== null && $b !== null && $a != 0 && $b != 0 && ($a > 0) === ($b > 0)) {
                    $direction = $a > 0 ? 'up' : 'down';
                    $trend = "{$unit} {$direction} in each of the last 2 comparisons ({$n} measured sessions)";
                } else {
                    $trend = "{$n} measured sessions with mixed or flat changes: no consistent trend";
                }
            }
        }

        return [
            'measured_sessions' => $n,
            'comparison' => $latest,
            'consistent_direction' => $direction,
            'sustained_trend_supported' => $direction !== null,
            'trend' => $trend,
            'summary' => self::describeStrength($latest),
        ];
    }

    /** @return array{0: string, 1: string} the change key and a label, shared by both comparisons */
    private static function signal(array $latest, array $earlier): array
    {
        if ($latest['previous']['timed']) {
            return ['total_seconds', 'total time'];
        }

        $volume = $latest['change']['volume_kg'] !== null && $earlier['change']['volume_kg'] !== null;

        return $volume ? ['volume_kg', 'volume'] : ['total_reps', 'total reps'];
    }

    /** One line stating what changed between two comparable sessions (or why they cannot be compared). */
    public static function describeStrength(array $comparison): string
    {
        if (! $comparison['comparable']) {
            return 'not comparable: '.implode('; ', $comparison['reasons']);
        }

        [$a, $b, $c] = [$comparison['previous'], $comparison['latest'], $comparison['change']];
        $parts = [];

        if ($b['timed']) {
            $parts[] = 'top hold '.self::pair($a['top_seconds'], $b['top_seconds'], $c['top_seconds'], ' s', 0);
            $parts[] = 'total time '.self::pair($a['total_seconds'], $b['total_seconds'], $c['total_seconds'], ' s', 0);
        } else {
            $parts[] = "sets {$a['completed_sets']}→{$b['completed_sets']}";
            $parts[] = 'total reps '.self::pair($a['total_reps'], $b['total_reps'], $c['total_reps'], '', 0);

            if ($a['top_load_kg'] !== null && $b['top_load_kg'] !== null) {
                $parts[] = 'top load '.self::pair($a['top_load_kg'], $b['top_load_kg'], $c['top_load_kg'], ' kg', 1);
            }

            if ($a['volume_kg'] !== null && $b['volume_kg'] !== null) {
                $parts[] = 'volume (reps × kg) '.self::pair($a['volume_kg'], $b['volume_kg'], $c['volume_kg'], ' kg', 1);
            }
        }

        $line = implode(' | ', $parts);

        return $comparison['caveats'] !== [] ? $line.' [limited: '.implode('; ', $comparison['caveats']).']' : $line;
    }

    /**
     * Compare two treadmill logs (previous, then latest). Only runs the user
     * logged with both a distance and a duration are comparable: a plan-completion
     * entry measured nothing, and a missing distance is unknown, not zero.
     *
     * @return array{comparable: bool, reasons: string[], caveats: string[], previous: array, latest: array, change: array}
     */
    public static function treadmill(ActivityLog $previous, ActivityLog $latest): array
    {
        $reasons = [];

        foreach (['the earlier' => $previous, 'the later' => $latest] as $which => $log) {
            if ($log->type !== ActivityLog::TYPE_TREADMILL) {
                $reasons[] = "{$which} entry is not a treadmill run";
            } elseif ($log->isPlanCompletion()) {
                $reasons[] = "{$which} entry only marks a plan as completed; nothing was measured";
            } elseif ($log->distance_km === null) {
                $reasons[] = "{$which} run has no distance recorded";
            } elseif (! $log->duration_minutes) {
                $reasons[] = "{$which} run has no duration recorded";
            }
        }

        $snapshot = fn (ActivityLog $log) => [
            'date' => $log->logged_date->toDateString(),
            'distance_km' => $log->distance_km !== null ? (float) $log->distance_km : null,
            'duration_minutes' => $log->duration_minutes,
            'speed_kmh' => $log->speedKmh(),
            'pace_seconds_per_km' => $log->paceSecondsPerKm(),
        ];

        $before = $snapshot($previous);
        $after = $snapshot($latest);
        $caveats = [];

        if ($reasons === []) {
            $intervals = fn (ActivityLog $log) => is_array($log->metadata['intervals'] ?? null) && count($log->metadata['intervals']) > 1;

            if ($intervals($previous) || $intervals($latest)) {
                $caveats[] = 'an interval session: average speed and pace blend the segments';
            }

            $ratio = max($before['duration_minutes'], $after['duration_minutes']) / max(1, min($before['duration_minutes'], $after['duration_minutes']));

            if ($ratio > 1.5) {
                $caveats[] = "durations differ a lot ({$before['duration_minutes']} vs {$after['duration_minutes']} min)";
            }
        }

        return [
            'comparable' => $reasons === [],
            'reasons' => $reasons,
            'caveats' => $caveats,
            'previous' => $before,
            'latest' => $after,
            'change' => $reasons !== [] ? [] : [
                'distance_km' => round($after['distance_km'] - $before['distance_km'], 2),
                'duration_minutes' => $after['duration_minutes'] - $before['duration_minutes'],
                'speed_kmh' => round($after['speed_kmh'] - $before['speed_kmh'], 1),
                'pace_seconds_per_km' => $after['pace_seconds_per_km'] - $before['pace_seconds_per_km'],
            ],
        ];
    }

    public static function describeTreadmill(array $comparison): string
    {
        if (! $comparison['comparable']) {
            return 'not comparable: '.implode('; ', $comparison['reasons']);
        }

        [$a, $b, $c] = [$comparison['previous'], $comparison['latest'], $comparison['change']];
        $pace = fn (int $s) => sprintf('%d:%02d', intdiv($s, 60), $s % 60);
        $paceChange = $c['pace_seconds_per_km'] === 0 ? 'same pace' : abs($c['pace_seconds_per_km']).' s/km '.($c['pace_seconds_per_km'] < 0 ? 'faster' : 'slower');

        $line = implode(' | ', [
            self::pair($a['distance_km'], $b['distance_km'], $c['distance_km'], ' km', 1),
            self::pair($a['duration_minutes'], $b['duration_minutes'], $c['duration_minutes'], ' min', 0),
            self::pair($a['speed_kmh'], $b['speed_kmh'], $c['speed_kmh'], ' km/h', 1),
            "{$pace($a['pace_seconds_per_km'])}→{$pace($b['pace_seconds_per_km'])}/km ({$paceChange})",
        ]);

        return $comparison['caveats'] !== [] ? $line.' [limited: '.implode('; ', $comparison['caveats']).']' : $line;
    }

    /** "27→29 (+2)" with a unit and fixed decimals; "unknown" when either side was not recorded. */
    private static function pair(float|int|null $from, float|int|null $to, float|int|null $change, string $unit, int $decimals): string
    {
        if ($from === null || $to === null || $change === null) {
            return 'not recorded in both';
        }

        $fmt = fn (float|int $v) => number_format((float) $v, $decimals, '.', '');
        $trim = $decimals > 0 && $unit !== ' km' && $unit !== ' km/h' ? fn (string $v) => rtrim(rtrim($v, '0'), '.') : fn (string $v) => $v;
        $signed = ($change > 0 ? '+' : '').$trim($fmt($change));

        return $trim($fmt($from)).'→'.$trim($fmt($to)).$unit.' ('.$signed.')';
    }
}
