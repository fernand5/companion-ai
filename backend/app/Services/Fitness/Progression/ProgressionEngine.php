<?php

namespace App\Services\Fitness\Progression;

use App\Models\WorkoutExercise;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Services\Fitness\PerformanceComparison;
use App\Support\ExerciseSlug;
use Carbon\Carbon;

/**
 * Turns the measured history of ONE exact exercise into a conservative,
 * explainable recommendation under ProgressionPolicy. Pure: it receives records
 * and returns a value. It reads no database and changes nothing, so a
 * recommendation is never a plan change and can be recalculated at will.
 *
 * Order of reasoning, each step able to end it:
 *   1. identity (never merge exercises), 2. is there measured history, 3. is it
 *   recent, 4. is there enough of it, 5. can the exercise be judged (a load, a
 *   target, not duration-only), 6. is the plan already past the maximum permitted change,
 *   7. what do the sessions at the current load and target say, 8. is the
 *   evidence reliable enough to act on (migrated, latest session measured),
 *   9. do recovery and limits allow an increase, 10. is the step small enough.
 *
 * It defaults to MAINTAIN. It only proposes an increase when three consecutive
 * measured sessions at the same load and target all reached it, on three
 * different days, from entered (not assumed or migrated) records.
 */
class ProgressionEngine
{
    /**
     * @param  array<int, ExercisePerformanceRecord>  $records  this exercise's sessions, newest first, measured or not
     * @param  array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, source?: string}|null  $currentTarget  the upcoming planned target, if there is one
     */
    public function evaluate(
        string $exerciseName,
        ?string $exerciseSlug,
        array $records,
        ?array $currentTarget,
        SafetyContext $safety,
        string $today,
    ): ProgressionRecommendation {
        $records = array_values($records);

        $finish = function (ProgressionDecision $decision, EvidenceQuality $quality, array $reasons, array $sessions = [], ?array $proposed = null, ?array $target = null, array $caveats = []) use ($exerciseName, $exerciseSlug, $today, $safety, $currentTarget) {
            return new ProgressionRecommendation(
                exerciseName: $exerciseName,
                exerciseSlug: $exerciseSlug,
                decision: $decision,
                currentTarget: $target ?? $this->normalizeTarget($currentTarget, $currentTarget['source'] ?? 'next_planned'),
                proposedTarget: $proposed,
                supportingSessions: array_map(fn (array $s) => $this->describeSession($s), $sessions),
                evidenceQuality: $quality,
                reasons: array_values(array_unique($reasons, SORT_REGULAR)),
                safetyCaveats: array_values(array_unique([...$caveats, ...$this->standingCaveats($decision, $safety)])),
                asOf: $today,
            );
        };

        // 1. Identity: one exact exercise, never a merge of similar names.
        $slugs = array_unique(array_map(fn (ExercisePerformanceRecord $r) => $r->exerciseSlug ?? ExerciseSlug::from($r->exerciseName) ?? '', $records));

        if (count($slugs) > 1 || ($records !== [] && $slugs === [''])) {
            return $finish(ProgressionDecision::ManualReview, EvidenceQuality::Insufficient, [ReasonCode::MixedExerciseIdentities]);
        }

        // 2. Is there measured history at all?
        $measured = array_values(array_filter($records, fn (ExercisePerformanceRecord $r) => $r->performanceMeasured));

        if ($measured === []) {
            $assumed = array_filter($records, fn (ExercisePerformanceRecord $r) => $r->recordedAs === WorkoutExercise::RECORDED_AS_PLANNED);

            return $finish(
                ProgressionDecision::InsufficientData,
                EvidenceQuality::None,
                [ReasonCode::NoMeasuredHistory, ...($assumed !== [] ? [ReasonCode::OnlyAssumedCompletions] : [])],
            );
        }

        $sessions = array_map(fn (ExercisePerformanceRecord $r) => $this->facts($r), $measured);
        $latest = $sessions[0];
        $shown = array_slice($sessions, 0, 5);

        // 3. Recent enough to say anything about today.
        if ($latest['record']->date === null || $this->daysBetween($latest['record']->date, $today) > ProgressionPolicy::STALE_AFTER_DAYS) {
            return $finish(ProgressionDecision::InsufficientData, EvidenceQuality::Insufficient, [ReasonCode::StaleHistory], $shown);
        }

        $unmeasuredNewer = ! $records[0]->performanceMeasured;
        $extra = $unmeasuredNewer ? [ReasonCode::LatestSessionNotMeasured] : [];

        // 4. Enough of it.
        if (count($sessions) < ProgressionPolicy::SESSIONS_REQUIRED) {
            $quality = in_array(true, array_column($sessions, 'migrated'), true) ? EvidenceQuality::Limited : EvidenceQuality::Insufficient;

            return $finish(ProgressionDecision::InsufficientData, $quality, [ReasonCode::NeedMoreSessions, ...$extra], $shown);
        }

        // 5. Can this exercise be judged by load and target?
        if ($latest['record']->isTimedOnly()) {
            return $finish(ProgressionDecision::ManualReview, EvidenceQuality::Limited, [ReasonCode::TimedExerciseUnsupported, ...$extra], $shown);
        }

        $loads = array_map(fn (array $set) => $set['weight_kg'], array_filter($latest['record']->sets, fn (array $set) => $set['completed']));
        $recorded = count(array_filter($loads, fn ($w) => $w !== null));

        if ($recorded === 0 || (float) $latest['top'] <= 0.0) {
            return $finish(ProgressionDecision::ManualReview, EvidenceQuality::Limited, [ReasonCode::NoLoadRecorded, ...$extra], $shown);
        }

        if ($recorded < count($loads)) {
            return $finish(ProgressionDecision::ManualReview, EvidenceQuality::Limited, [ReasonCode::LoadPartiallyRecorded, ...$extra], $shown);
        }

        if ($latest['attainment'] === null) {
            return $finish(ProgressionDecision::InsufficientData, EvidenceQuality::Limited, [ReasonCode::NoTargetRecorded, ...$extra], $shown);
        }

        // The target the next session would carry: the plan's, else the last session's own.
        $target = $this->normalizeTarget($currentTarget, $currentTarget['source'] ?? 'next_planned')
            ?? $this->normalizeTarget($latest['record']->planned, 'last_session_plan');
        $currentWeight = $target['weight_kg'] ?? null;
        $demonstrated = max(array_filter(array_column($sessions, 'top'), fn ($t) => $t !== null));

        // 6. A plan already beyond the maximum permitted change is a person's call, whatever the history says.
        if ($currentWeight !== null && $currentWeight > ProgressionPolicy::ceilingKg($demonstrated) + 1e-9) {
            return $finish(ProgressionDecision::ManualReview, EvidenceQuality::Limited, [ReasonCode::CurrentTargetExceedsMaximumChange, ...$extra], $shown, null, $target, [
                sprintf('The current target (%s kg) is more than %d%% above the heaviest load recorded for this exercise (%s kg), the most one change may add. That is a cap, not a judgment of what suits the person.', $this->kg($currentWeight), (int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100), $this->kg($demonstrated)),
            ]);
        }

        // 7. What do the sessions at the current load and target say?
        $streak = [$latest];

        foreach (array_slice($sessions, 1) as $session) {
            if ($session['key'] !== $latest['key'] || $session['attainment'] === null) {
                break;
            }

            $streak[] = $session;
        }

        $n = count($streak);
        $days = count(array_unique(array_map(fn (array $s) => $s['record']->date, $streak)));
        $met = array_map(fn (array $s) => $s['attainment']['met'], $streak);
        $leadingMisses = 0;

        foreach ($met as $m) {
            if ($m) {
                break;
            }
            $leadingMisses++;
        }

        $candidate = ProgressionDecision::Maintain;
        $reasons = [];

        if (! $met[0]) {
            if ($latest['incomplete']) {
                $reasons[] = ReasonCode::IncompleteSession;
            }

            if ($leadingMisses >= ProgressionPolicy::MISSES_FOR_DECREASE && $days >= ProgressionPolicy::MISSES_FOR_DECREASE) {
                $candidate = ProgressionDecision::Decrease;
                $reasons[] = ReasonCode::MissesAtSameLoad;
            } elseif ($leadingMisses >= 2) {
                $reasons[] = ReasonCode::RepeatedMisses;
            } else {
                $reasons[] = ReasonCode::TargetNotReached;
            }
        } elseif ($n >= ProgressionPolicy::SESSIONS_REQUIRED && ! in_array(false, $met, true)) {
            if ($days >= ProgressionPolicy::SESSIONS_REQUIRED) {
                $candidate = ProgressionDecision::Increase;
                $reasons[] = ReasonCode::TargetsReachedConsecutively;
            } else {
                $reasons[] = ReasonCode::SessionsOnTooFewDays;
            }
        } elseif ($n >= ProgressionPolicy::SESSIONS_REQUIRED) {
            $reasons[] = ReasonCode::InconsistentResults;
        } else {
            $reasons[] = ReasonCode::ConsolidateAtCurrentLoad;

            if (isset($sessions[$n]) && $sessions[$n]['top'] !== $latest['top']) {
                $reasons[] = ReasonCode::LoadChangedRecently;
            }
        }

        $supporting = array_slice($streak, 0, 5);

        if (! $candidate->proposesChange()) {
            $quality = $this->qualityOf($streak, $n);

            return $finish($candidate, $quality, [...$reasons, ...$extra], $supporting, null, $target, $this->conditionCaveats($safety));
        }

        // 8. Reliable enough to act on: entered records, and the latest session was actually measured.
        if (in_array(true, array_column($streak, 'migrated'), true)) {
            return $finish(ProgressionDecision::InsufficientData, EvidenceQuality::Limited, [ReasonCode::MigratedEvidence, ...$extra], $supporting, null, $target);
        }

        if ($unmeasuredNewer) {
            return $finish(ProgressionDecision::Maintain, EvidenceQuality::Limited, [...$extra], $supporting, null, $target, $this->conditionCaveats($safety));
        }

        // 9. Recovery and limits can stop an increase; they never stop a reduction.
        if ($candidate === ProgressionDecision::Increase) {
            $blockers = [];

            if ($safety->restrictionOnRecord) {
                $blockers[] = ReasonCode::RestrictionOnRecord;
            }

            if ($safety->painReportedRecently) {
                $blockers[] = ReasonCode::PainReportedRecently;
            }

            if ($safety->highSoreness()) {
                $blockers[] = ReasonCode::HighSoreness;
            }

            if ($blockers !== []) {
                return $finish(ProgressionDecision::ManualReview, $this->qualityOf($streak, $n), [...$blockers, ...$reasons], $supporting, null, $target, $this->conditionCaveats($safety));
            }
        }

        // 10. The smallest step this exercise has shown, within the policy's ceiling.
        $load = (float) $latest['top'];
        $step = $this->stepKg($sessions, $load);

        if ($step / $load > ProgressionPolicy::MAX_INCREASE_FRACTION + 1e-9) {
            return $finish(ProgressionDecision::ManualReview, $this->qualityOf($streak, $n), [ReasonCode::LoadStepExceedsLimit, ...$reasons], $supporting, null, $target, [
                sprintf('The smallest load change available here (%s kg) is %.1f%% of %s kg, above the %d%% limit.', $this->kg($step), $step / $load * 100, $this->kg($load), (int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100)),
            ]);
        }

        $to = $candidate === ProgressionDecision::Increase ? $load + $step : $load - $step;

        if ($to <= 0) {
            return $finish(ProgressionDecision::ManualReview, $this->qualityOf($streak, $n), [ReasonCode::LoadStepExceedsLimit, ...$reasons], $supporting, null, $target);
        }

        if ($candidate === ProgressionDecision::Increase && $currentWeight !== null) {
            if ($currentWeight >= $to - 1e-9) {
                return $finish(ProgressionDecision::Maintain, $this->qualityOf($streak, $n), [ReasonCode::TargetAlreadyAtProposed, ...$reasons], $supporting, null, $target, $this->conditionCaveats($safety));
            }

            if (($to - $currentWeight) / $currentWeight > ProgressionPolicy::MAX_INCREASE_FRACTION + 1e-9) {
                return $finish(ProgressionDecision::ManualReview, $this->qualityOf($streak, $n), [ReasonCode::LoadStepExceedsLimit, ...$reasons], $supporting, null, $target);
            }
        }

        $proposed = [
            'sets' => $target['sets'] ?? $latest['record']->planned['sets'],
            'reps' => $target['reps'] ?? $latest['record']->planned['reps'],
            'weight_kg' => round($to, 2),
            'duration_seconds' => null,
            'dimension' => 'load_kg',
            'from' => round($load, 2),
            'to' => round($to, 2),
            'step_kg' => round($step, 2),
            'change_percent' => round(($to - $load) / $load * 100, 1),
        ];

        return $finish($candidate, $this->qualityOf($streak, $n), $reasons, $supporting, $proposed, $target, $this->conditionCaveats($safety));
    }

    /**
     * @return array{record: ExercisePerformanceRecord, top: ?float, key: string, attainment: ?array{met: bool, sets_reaching: int, of: int}, incomplete: bool, migrated: bool}
     */
    private function facts(ExercisePerformanceRecord $record): array
    {
        $metrics = PerformanceComparison::metrics($record);

        return [
            'record' => $record,
            'top' => $metrics['top_load_kg'],
            // The same demand: the same planned sets and repetitions, at the same top load.
            'key' => json_encode([$record->planned['sets'], $record->planned['reps'], $metrics['top_load_kg']]),
            'attainment' => $record->targetAttainment(),
            'incomplete' => $metrics['incomplete'],
            'migrated' => $record->recordedAs === WorkoutExercise::RECORDED_MIGRATED,
        ];
    }

    private function qualityOf(array $streak, int $n): EvidenceQuality
    {
        $entered = ! in_array(true, array_column($streak, 'migrated'), true);
        $judged = ! in_array(null, array_column($streak, 'attainment'), true);

        return $entered && $judged && $n >= ProgressionPolicy::SESSIONS_REQUIRED ? EvidenceQuality::Strong : EvidenceQuality::Limited;
    }

    /**
     * The load step: the smallest difference between two different loads this exact
     * exercise has actually been done at (a step the person demonstrably has), else the
     * policy's fallback for a load of this size.
     *
     * @param  array<int, array{top: ?float}>  $sessions
     */
    private function stepKg(array $sessions, float $load): float
    {
        $tops = array_values(array_unique(array_filter(array_column($sessions, 'top'), fn ($t) => $t !== null)));
        sort($tops);

        $smallest = null;

        for ($i = 1; $i < count($tops); $i++) {
            $gap = round($tops[$i] - $tops[$i - 1], 2);

            if ($gap >= ProgressionPolicy::LOAD_QUANTUM_KG && ($smallest === null || $gap < $smallest)) {
                $smallest = $gap;
            }
        }

        return $smallest ?? ProgressionPolicy::fallbackStepKg($load);
    }

    /** @return array<int, string> */
    private function standingCaveats(ProgressionDecision $decision, SafetyContext $safety): array
    {
        if (! $decision->proposesChange()) {
            return [];
        }

        $caveats = [
            'Effort (RPE), repetitions in reserve and technique are not recorded: a reached target does not show the load felt manageable.',
            'This is a calculation from a training log, not medical clearance or a readiness assessment.',
        ];

        if (! $safety->recoveryInformationIsCurrent()) {
            $caveats[] = 'No recovery check-in in the last '.ProgressionPolicy::RECOVERY_CURRENT_DAYS.' days, so recovery is unknown.';
        }

        return $caveats;
    }

    /** @return array<int, string> */
    private function conditionCaveats(SafetyContext $safety): array
    {
        $caveats = [];

        if ($safety->painReportedRecently) {
            $caveats[] = 'A recent recovery check-in mentions pain. Do not add load without checking with a qualified professional if it persists.';
        }

        if ($safety->highSoreness()) {
            $caveats[] = 'The latest recovery check-in reports high soreness.';
        }

        if ($safety->restrictionOnRecord) {
            $caveats[] = 'A saved injury, restriction or limitation could not be matched to a specific exercise, so a person should check it applies or not.';
        }

        return $caveats;
    }

    /**
     * @param  array<string, mixed>|null  $target
     * @return array{sets: ?int, reps: ?int, weight_kg: ?float, duration_seconds: ?int, source: string}|null
     */
    private function normalizeTarget(?array $target, string $source): ?array
    {
        if ($target === null) {
            return null;
        }

        $normalized = [
            'sets' => $target['sets'] ?? null,
            'reps' => $target['reps'] ?? null,
            'weight_kg' => isset($target['weight_kg']) ? (float) $target['weight_kg'] : null,
            'duration_seconds' => $target['duration_seconds'] ?? null,
        ];

        return array_filter($normalized, fn ($v) => $v !== null) === [] ? null : $normalized + ['source' => $source];
    }

    /**
     * @param  array{record: ExercisePerformanceRecord, top: ?float, attainment: ?array{met: bool, sets_reaching: int, of: int}}  $session
     * @return array<string, mixed>
     */
    private function describeSession(array $session): array
    {
        $record = $session['record'];

        return [
            'date' => $record->date,
            'workout_session_id' => $record->workoutSessionId,
            'recorded_as' => $record->recordedAs,
            'outcome' => $record->outcome,
            'performed' => ExercisePerformanceRecord::describeSets($record->sets),
            'target' => ExercisePerformanceRecord::describePlanned($record->planned),
            'target_reached' => $session['attainment']['met'] ?? null,
            'sets_reaching_target' => $session['attainment']['sets_reaching'] ?? null,
            'planned_sets' => $session['attainment']['of'] ?? null,
            'top_load_kg' => $session['top'],
        ];
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) abs(Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), false));
    }

    private function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
