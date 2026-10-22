<?php

namespace App\Services\Fitness;

use App\Models\WorkoutExercise;
use App\Models\WorkoutPlanExercise;

/**
 * One historical exercise performance, shaped for an AI reader (and reusable
 * for any compact history rendering). It keeps three things strictly apart:
 *
 *  - PLANNED: the target snapshotted with that workout exercise when it was
 *    logged. It is never read from the current plan, so replacing or editing a
 *    plan cannot change what was planned back then.
 *  - ACTUAL: the per-set rows from workout_sets, exposed ONLY when they are real
 *    evidence (`entered` or `migrated`). One-tap completions (`as_planned`)
 *    expose no actual numbers at all — the plan's targets were merely assumed,
 *    so they are not performance — and say so with performance_measured=false.
 *  - OUTCOME: whether it was done at all, so "skipped", "partial", "completed as
 *    planned" and "no measurable performance recorded" cannot be confused with a
 *    measured completion.
 *
 * Needs `performedSets` and `planExercise` loaded on the exercise.
 */
final class ExercisePerformanceRecord
{
    /** Done, and the sets that were done are on record. */
    public const OUTCOME_COMPLETED = 'completed';

    /** Marked done with one tap: assumed, not measured. */
    public const OUTCOME_COMPLETED_AS_PLANNED = 'completed_as_planned';

    public const OUTCOME_PARTIAL = 'partial';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_PENDING = 'pending';

    /** A record exists but nothing about what was done can be established. */
    public const OUTCOME_NO_MEASURABLE_PERFORMANCE = 'no_measurable_performance';

    /**
     * @param  array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int}  $planned
     * @param  array<int, array{set_number: int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>  $sets
     */
    private function __construct(
        public readonly ?string $date,
        public readonly int $workoutSessionId,
        public readonly string $exerciseName,
        public readonly ?string $exerciseSlug,
        public readonly string $recordedAs,
        public readonly bool $performanceMeasured,
        public readonly string $outcome,
        public readonly array $planned,
        public readonly array $sets,
    ) {}

    public static function from(WorkoutExercise $exercise, ?string $date = null): self
    {
        $recordedAs = $exercise->recorded_as ?? WorkoutExercise::RECORDED_MIGRATED;
        $isEvidence = $recordedAs !== WorkoutExercise::RECORDED_AS_PLANNED;

        $allSets = $exercise->performedSets->map(fn ($set) => [
            'set_number' => (int) $set->set_number,
            'reps' => $set->reps,
            'weight_kg' => $set->weight_kg !== null ? (float) $set->weight_kg : null,
            'duration_seconds' => $set->duration_seconds,
            'completed' => (bool) $set->completed,
        ])->values()->all();

        $completedCount = count(array_filter($allSets, fn ($set) => $set['completed']));
        $measured = $isEvidence && $completedCount > 0;

        return new self(
            date: $date,
            workoutSessionId: (int) $exercise->workout_session_id,
            exerciseName: $exercise->exercise_name,
            exerciseSlug: $exercise->exercise_slug,
            recordedAs: $recordedAs,
            performanceMeasured: $measured,
            outcome: self::outcomeFor($isEvidence, $completedCount, count($allSets), $exercise->planExercise?->status),
            planned: [
                'sets' => $exercise->planned_sets,
                'reps' => $exercise->planned_reps,
                'weight_kg' => $exercise->planned_weight_kg !== null ? (float) $exercise->planned_weight_kg : null,
                'duration_seconds' => $exercise->planned_duration_seconds,
            ],
            // Only genuine evidence is ever exposed as what was performed.
            sets: $isEvidence ? $allSets : [],
        );
    }

    private static function outcomeFor(bool $isEvidence, int $completedSets, int $totalSets, ?string $planStatus): string
    {
        if ($planStatus === WorkoutPlanExercise::STATUS_SKIPPED) {
            return self::OUTCOME_SKIPPED;
        }

        if ($planStatus === WorkoutPlanExercise::STATUS_PENDING) {
            return self::OUTCOME_PENDING;
        }

        if (! $isEvidence) {
            return match ($planStatus) {
                WorkoutPlanExercise::STATUS_COMPLETED => self::OUTCOME_COMPLETED_AS_PLANNED,
                WorkoutPlanExercise::STATUS_PARTIAL => self::OUTCOME_PARTIAL,
                // No plan row to say whether it was done: do not assume it was.
                default => self::OUTCOME_NO_MEASURABLE_PERFORMANCE,
            };
        }

        if ($completedSets === 0) {
            return $planStatus === WorkoutPlanExercise::STATUS_PARTIAL
                ? self::OUTCOME_PARTIAL
                : self::OUTCOME_NO_MEASURABLE_PERFORMANCE;
        }

        return $planStatus === WorkoutPlanExercise::STATUS_PARTIAL || $completedSets < $totalSets
            ? self::OUTCOME_PARTIAL
            : self::OUTCOME_COMPLETED;
    }

    /**
     * Did the recorded sets reach the target that was set at the time? Computed from
     * the stored snapshot and the exact sets, never from the plan as it is now.
     *
     * A set reaches the target when it meets every part of the target that was set
     * (at least the planned reps, at least the planned load, at least the planned
     * duration). The denominator is the planned number of sets (or, with none
     * planned, the sets actually done), so a missing set counts as not reached.
     *
     * Null when it cannot be said: nothing measured, no target, or a completed set
     * that lacks a field the target needs (unknown stays unknown, it is not "not met").
     *
     * @return array{met: bool, sets_reaching: int, of: int}|null
     */
    public function targetAttainment(): ?array
    {
        if (! $this->performanceMeasured) {
            return null;
        }

        $target = $this->planned;

        if ($target['reps'] === null && $target['weight_kg'] === null && $target['duration_seconds'] === null && $target['sets'] === null) {
            return null;
        }

        $done = array_values(array_filter($this->sets, fn (array $set) => $set['completed']));
        $reaching = 0;

        foreach ($done as $set) {
            $checks = [];

            if ($target['reps'] !== null) {
                $checks[] = [$set['reps'], $target['reps']];
            }

            if ($target['weight_kg'] !== null) {
                $checks[] = [$set['weight_kg'], $target['weight_kg']];
            }

            if ($target['duration_seconds'] !== null && $target['reps'] === null) {
                $checks[] = [$set['duration_seconds'], $target['duration_seconds']];
            }

            foreach ($checks as [$actual]) {
                if ($actual === null) {
                    return null;
                }
            }

            if (array_reduce($checks, fn (bool $ok, array $check) => $ok && $check[0] >= $check[1], true)) {
                $reaching++;
            }
        }

        $of = $target['sets'] ?? count($done);

        return ['met' => $of > 0 && $reaching >= $of, 'sets_reaching' => min($reaching, $of), 'of' => $of];
    }

    /**
     * True when the exercise is described only by a duration: no reps and no load,
     * either planned or (for real evidence) performed. A warm-up on a treadmill, a
     * mobility flow or a plank hold look like this; so does anything else timed.
     *
     * This is a structural fact about the data, not a judgement from the exercise's
     * name: it says "this is a duration, not resistance work", and no more. Where
     * nothing about the exercise is known it is NOT timed (unknown stays unknown).
     */
    public function isTimedOnly(): bool
    {
        $resistance = $this->planned['reps'] !== null || $this->planned['weight_kg'] !== null;
        $timed = $this->planned['duration_seconds'] !== null;

        foreach ($this->sets as $set) {
            $resistance = $resistance || $set['reps'] !== null || $set['weight_kg'] !== null;
            $timed = $timed || $set['duration_seconds'] !== null;
        }

        return $timed && ! $resistance;
    }

    /**
     * Structured form for tools. Planned and actual are separate keys; null
     * members are dropped inside a set so the payload stays small ("absent"
     * means "not recorded").
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeSession = true): array
    {
        $out = [];

        if ($includeSession) {
            $out['date'] = $this->date;
            $out['workout_session_id'] = $this->workoutSessionId;
        }

        return $out + [
            'exercise_name' => $this->exerciseName,
            'exercise_slug' => $this->exerciseSlug,
            'recorded_as' => $this->recordedAs,
            'performance_measured' => $this->performanceMeasured,
            'outcome' => $this->outcome,
            'planned_sets' => $this->planned['sets'],
            'planned_reps' => $this->planned['reps'],
            'planned_weight_kg' => $this->planned['weight_kg'],
            'planned_duration_seconds' => $this->planned['duration_seconds'],
            'target_attainment' => $this->targetAttainment(),
            'performed_summary' => $this->performanceMeasured ? self::describeSets($this->sets) : null,
            'performed_sets' => $this->performanceMeasured
                ? array_map(fn (array $set) => array_filter($set, fn ($value) => $value !== null), $this->sets)
                : null,
        ];
    }

    /**
     * The one-line form for a compact history (the exercise name is the caller's
     * heading). What was performed, the target at the time and whether it was
     * reached, and how far to trust it:
     *   2026-10-05: performed 10,10,9 @ 42.5 kg | target 3×10 @ 42.5 kg — NOT reached (2 of 3 sets did) | entered
     *   2026-09-16: completed as planned | performance not measured | plan only: 3×12 @ 22.5 kg (not what was lifted)
     */
    public function toCompactLine(): string
    {
        $planned = self::describePlanned($this->planned);
        $target = $planned !== null ? "target {$planned}".$this->attainmentNote() : null;
        // A one-tap completion has no performance, so its numbers are only the plan: label them so,
        // because "target" beside a number is easily read as something achieved.
        $planOnly = $planned !== null ? "plan only: {$planned} (not what was lifted)" : null;

        $parts = match ($this->outcome) {
            self::OUTCOME_COMPLETED => ['performed '.self::describeSets($this->sets), $target, $this->recordedAs],
            self::OUTCOME_PARTIAL => $this->performanceMeasured
                ? ['performed '.self::describeSets($this->sets), $target, $this->recordedAs, 'partial']
                : ['partial', 'performance not measured', $planOnly],
            self::OUTCOME_COMPLETED_AS_PLANNED => ['completed as planned', 'performance not measured', $planOnly],
            self::OUTCOME_SKIPPED => ['skipped', $target],
            self::OUTCOME_PENDING => ['not done', $target],
            default => ['no measurable performance recorded', $target],
        };

        return ($this->date !== null ? $this->date.': ' : '').implode(' | ', array_filter($parts, fn ($part) => $part !== null && $part !== ''));
    }

    /**
     * " — reached" or " — NOT reached (1 of 3 sets did)", only when it can be said.
     * Worded flatly on purpose: a quick reader must not take the target for the
     * performance, nor "1 of 3" for a pass.
     */
    private function attainmentNote(): string
    {
        $attainment = $this->targetAttainment();

        if ($attainment === null) {
            return '';
        }

        return $attainment['met'] ? ' — reached' : " — NOT reached ({$attainment['sets_reaching']} of {$attainment['of']} sets did)";
    }

    /**
     * "8,8,7 @ 45 kg", "8@45, 8@47.5, 7@50 kg", "45s,45s,45s", "10,10,10 reps".
     * Sets that were not completed are not listed, only counted.
     *
     * @param  array<int, array{reps: ?int, weight_kg: ?float, duration_seconds: ?int, completed: bool}>  $sets
     */
    public static function describeSets(array $sets): string
    {
        $done = array_values(array_filter($sets, fn (array $set) => $set['completed']));
        $missed = count($sets) - count($done);

        if ($done === []) {
            return $sets === [] ? '' : 'no sets completed';
        }

        $tokens = array_map(fn (array $set) => $set['reps'] !== null
            ? (string) $set['reps']
            : ($set['duration_seconds'] !== null ? $set['duration_seconds'].'s' : '–'), $done);

        $weights = array_map(fn (array $set) => $set['weight_kg'], $done);
        $distinct = array_values(array_unique(array_map(fn ($w) => $w === null ? 'null' : (string) $w, $weights)));
        $allReps = count(array_filter($done, fn (array $set) => $set['reps'] !== null)) === count($done);

        if ($distinct === ['null']) {
            $text = implode(',', $tokens).($allReps ? ' reps' : '');
        } elseif (count($distinct) === 1) {
            $text = implode(',', $tokens).' @ '.self::kg($weights[0]).' kg';
        } else {
            $text = implode(', ', array_map(
                fn (string $token, ?float $w) => $w === null ? $token : $token.'@'.self::kg($w),
                $tokens,
                $weights,
            )).' kg';
        }

        return $text.($missed > 0 ? " (+{$missed} not completed)" : '');
    }

    /**
     * @param  array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int}  $planned
     */
    public static function describePlanned(array $planned): ?string
    {
        $volume = null;

        if ($planned['sets'] !== null && $planned['reps'] !== null) {
            $volume = $planned['sets'].'×'.$planned['reps'];
        } elseif ($planned['sets'] !== null && $planned['duration_seconds'] !== null) {
            $volume = $planned['sets'].'×'.$planned['duration_seconds'].'s';
        } elseif ($planned['reps'] !== null) {
            $volume = $planned['reps'].' reps';
        } elseif ($planned['duration_seconds'] !== null) {
            $volume = $planned['duration_seconds'].'s';
        } elseif ($planned['sets'] !== null) {
            $volume = $planned['sets'].' sets';
        }

        $weight = $planned['weight_kg'] !== null ? '@ '.self::kg($planned['weight_kg']).' kg' : null;

        $text = trim(($volume ?? '').' '.($weight ?? ''));

        return $text === '' ? null : $text;
    }

    private static function kg(float $weight): string
    {
        return rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');
    }
}
