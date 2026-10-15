<?php

namespace Tests\Unit\Fitness\Progression;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Fitness\ExercisePerformanceRecord;
use App\Services\Fitness\Progression\EvidenceQuality;
use App\Services\Fitness\Progression\ProgressionDecision as D;
use App\Services\Fitness\Progression\ProgressionEngine;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Services\Fitness\Progression\ProgressionRecommendation;
use App\Services\Fitness\Progression\ReasonCode as R;
use App\Services\Fitness\Progression\SafetyContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The progression policy, one rule at a time, on records that never touch a database.
 * Every record is built the way the application builds them (ExercisePerformanceRecord::from)
 * so the engine sees exactly what it would in production.
 */
class ProgressionEngineTest extends TestCase
{
    private const TODAY = '2026-10-15';

    private int $sessionId = 0;

    /**
     * One session of one exercise.
     *
     * @param  array<int, array{0: ?int, 1: float|int|null, 2?: bool}>  $sets  reps, kg, completed
     * @param  array{0: ?int, 1: ?int, 2: float|int|null}  $target  sets, reps, kg
     */
    private function visit(int $daysAgo, array $sets, array $target = [3, 10, 40], string $as = 'entered', string $name = 'Bench Press', ?int $durationSeconds = null): ExercisePerformanceRecord
    {
        $exercise = new WorkoutExercise([
            'workout_session_id' => ++$this->sessionId,
            'exercise_name' => $name,
            'planned_sets' => $target[0], 'planned_reps' => $target[1], 'planned_weight_kg' => $target[2],
            'recorded_as' => $as,
        ]);

        $exercise->setRelation('performedSets', collect(array_map(
            fn (array $set, int $i) => new WorkoutSet([
                'set_number' => $i + 1,
                'reps' => $set[0],
                'weight_kg' => $set[1],
                'duration_seconds' => $durationSeconds,
                'completed' => $set[2] ?? true,
            ]),
            $sets,
            array_keys($sets),
        )));
        $exercise->setRelation('planExercise', null);

        return ExercisePerformanceRecord::from($exercise, date('Y-m-d', strtotime(self::TODAY." -{$daysAgo} days")));
    }

    /** Three sets of 10 at $kg: the target of 3x10 is reached. */
    private function reached(int $daysAgo, float $kg = 40, string $as = 'entered'): ExercisePerformanceRecord
    {
        return $this->visit($daysAgo, [[10, $kg], [10, $kg], [10, $kg]], [3, 10, $kg], $as);
    }

    /** 10, 10, 9: one rep short on the last set, so the target is NOT reached. */
    private function missed(int $daysAgo, float $kg = 40, string $as = 'entered'): ExercisePerformanceRecord
    {
        return $this->visit($daysAgo, [[10, $kg], [10, $kg], [9, $kg]], [3, 10, $kg], $as);
    }

    /** @param array<int, ExercisePerformanceRecord> $newestFirst */
    private function evaluate(array $newestFirst, ?array $target = null, ?SafetyContext $safety = null, string $name = 'Bench Press'): ProgressionRecommendation
    {
        return (new ProgressionEngine)->evaluate($name, 'bench-press', $newestFirst, $target, $safety ?? new SafetyContext(daysSinceLatestCheckin: 1, latestSoreness: 2), self::TODAY);
    }

    // ---- repeated success ----------------------------------------------------------------

    public function test_three_consecutive_reached_sessions_at_the_same_load_support_one_small_increase(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)]);

        $this->assertSame(D::Increase, $result->decision);
        $this->assertSame(EvidenceQuality::Strong, $result->evidenceQuality);
        $this->assertTrue($result->has(R::TargetsReachedConsecutively));
        $this->assertSame(40.0, $result->proposedTarget['from']);
        $this->assertSame(42.5, $result->proposedTarget['weight_kg'], 'The fallback step for a load of this size is 2.5 kg.');
        $this->assertSame(2.5, $result->proposedTarget['step_kg']);
        $this->assertSame(6.3, $result->proposedTarget['change_percent']);
        $this->assertSame(3, $result->proposedTarget['sets']);
        $this->assertSame(10, $result->proposedTarget['reps']);
        $this->assertCount(3, $result->supportingSessions);
        $this->assertSame('10,10,10 @ 40 kg', $result->supportingSessions[0]['performed']);
        $this->assertTrue($result->supportingSessions[0]['target_reached']);
    }

    public function test_an_increase_always_carries_its_safety_caveats_and_is_never_applied(): void
    {
        $array = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)])->toArray();

        $this->assertFalse($array['applied']);
        $this->assertSame(ProgressionPolicy::VERSION, $array['policy_version']);
        $this->assertNotEmpty(array_filter($array['safety_caveats'], fn ($c) => str_contains($c, 'not medical clearance')));
        $this->assertNotEmpty(array_filter($array['safety_caveats'], fn ($c) => str_contains($c, 'Effort (RPE)')));
        $this->assertSame('INCREASE', $array['decision']);
        $this->assertSame('bench-press', $array['exercise']['slug']);
    }

    public function test_one_or_two_successful_sessions_never_establish_a_progression(): void
    {
        foreach ([[$this->reached(2)], [$this->reached(2), $this->reached(6)]] as $history) {
            $result = $this->evaluate($history);

            $this->assertSame(D::InsufficientData, $result->decision);
            $this->assertTrue($result->has(R::NeedMoreSessions));
            $this->assertNull($result->proposedTarget);
        }
    }

    public function test_no_history_at_all_is_insufficient_data(): void
    {
        $result = $this->evaluate([]);

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertSame(EvidenceQuality::None, $result->evidenceQuality);
        $this->assertTrue($result->has(R::NoMeasuredHistory));
    }

    public function test_a_longer_streak_still_proposes_a_single_step(): void
    {
        $result = $this->evaluate([$this->reached(1), $this->reached(4), $this->reached(8), $this->reached(11), $this->reached(15)]);

        $this->assertSame(D::Increase, $result->decision);
        $this->assertSame(42.5, $result->proposedTarget['weight_kg'], 'Five good sessions do not buy a bigger jump.');
    }

    // ---- misses, inconsistency, incomplete sets ---------------------------------------------

    public function test_a_single_miss_keeps_the_target(): void
    {
        $result = $this->evaluate([$this->missed(2), $this->reached(5), $this->reached(9)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::TargetNotReached));
        $this->assertNull($result->proposedTarget);
    }

    public function test_two_consecutive_misses_keep_the_target_and_say_so(): void
    {
        $result = $this->evaluate([$this->missed(2), $this->missed(5), $this->reached(9)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::RepeatedMisses));
    }

    public function test_repeated_misses_at_the_same_load_support_a_small_decrease(): void
    {
        $result = $this->evaluate([$this->missed(2), $this->missed(5), $this->missed(9)]);

        $this->assertSame(D::Decrease, $result->decision);
        $this->assertTrue($result->has(R::MissesAtSameLoad));
        $this->assertSame(37.5, $result->proposedTarget['weight_kg']);
        $this->assertSame(-6.3, $result->proposedTarget['change_percent']);
    }

    public function test_misses_at_different_loads_are_not_repeated_misses_at_one_load(): void
    {
        $result = $this->evaluate([$this->missed(2, 45), $this->missed(5, 42.5), $this->missed(9, 40)]);

        $this->assertSame(D::Maintain, $result->decision, 'Three different loads are three first attempts.');
        $this->assertFalse($result->has(R::MissesAtSameLoad));
    }

    public function test_inconsistent_results_at_one_load_keep_the_target(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->missed(5), $this->reached(9)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::InconsistentResults));
    }

    public function test_incomplete_sets_are_a_miss_never_a_success(): void
    {
        $partial = $this->visit(2, [[10, 40], [10, 40], [null, null, false]], [3, 10, 40]);

        $result = $this->evaluate([$partial, $this->reached(5), $this->reached(9)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::IncompleteSession));
        $this->assertNull($result->proposedTarget);
    }

    public function test_a_session_with_fewer_sets_than_planned_is_not_a_success(): void
    {
        $short = $this->visit(2, [[10, 40], [10, 40]], [3, 10, 40]);

        $this->assertSame(D::Maintain, $this->evaluate([$short, $this->reached(5), $this->reached(9)])->decision);
    }

    // ---- load and rep changes -----------------------------------------------------------------

    public function test_a_heavier_load_with_fewer_repetitions_is_a_new_demand_not_a_streak(): void
    {
        $history = [
            $this->visit(2, [[8, 45], [7, 45], [6, 45]], [3, 10, 45]),
            $this->visit(6, [[9, 42.5], [9, 42.5], [8, 42.5]], [3, 10, 42.5]),
            $this->visit(10, [[10, 40], [10, 40], [10, 40]], [3, 10, 40]),
        ];

        $result = $this->evaluate($history);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::TargetNotReached));
        $this->assertNull($result->proposedTarget);
    }

    public function test_a_load_that_just_went_up_is_consolidated_before_going_up_again(): void
    {
        $result = $this->evaluate([$this->reached(2, 42.5), $this->reached(6, 40), $this->reached(10, 40)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::ConsolidateAtCurrentLoad));
        $this->assertTrue($result->has(R::LoadChangedRecently));
    }

    public function test_a_changed_target_between_sessions_breaks_the_streak(): void
    {
        $newTarget = $this->visit(2, [[8, 40], [8, 40], [8, 40]], [3, 8, 40]);

        $result = $this->evaluate([$newTarget, $this->reached(5), $this->reached(9)]);

        $this->assertSame(D::Maintain, $result->decision, 'Reaching 3x8 says nothing about 3x10.');
    }

    public function test_the_load_step_is_the_smallest_step_this_exercise_has_shown(): void
    {
        $result = $this->evaluate([$this->reached(2, 41), $this->reached(5, 41), $this->reached(9, 41), $this->reached(14, 40)]);

        $this->assertSame(D::Increase, $result->decision);
        $this->assertSame(1.0, $result->proposedTarget['step_kg'], 'The person has gone 40 → 41 before; that is the step they have.');
        $this->assertSame(42.0, $result->proposedTarget['weight_kg']);
    }

    // ---- evidence ------------------------------------------------------------------------------

    public function test_one_tap_completions_are_not_measured_and_never_justify_an_increase(): void
    {
        $oneTap = fn (int $daysAgo) => $this->visit($daysAgo, [], [3, 10, 40], 'as_planned');

        $result = $this->evaluate([$oneTap(2), $oneTap(5), $oneTap(9), $oneTap(13)]);

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertSame(EvidenceQuality::None, $result->evidenceQuality);
        $this->assertTrue($result->has(R::NoMeasuredHistory));
        $this->assertTrue($result->has(R::OnlyAssumedCompletions));
        $this->assertNull($result->proposedTarget);
    }

    public function test_a_newer_one_tap_session_stops_a_proposal_resting_on_older_entered_ones(): void
    {
        $oneTap = $this->visit(1, [], [3, 10, 40], 'as_planned');

        $result = $this->evaluate([$oneTap, $this->reached(4), $this->reached(8), $this->reached(12)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::LatestSessionNotMeasured));
        $this->assertNull($result->proposedTarget);
    }

    public function test_migrated_history_alone_cannot_justify_an_increase_or_a_decrease(): void
    {
        foreach ([[$this->reached(...), 'reached'], [$this->missed(...), 'missed']] as [$make]) {
            $result = $this->evaluate([$make(2, 40, 'migrated'), $make(5, 40, 'migrated'), $make(9, 40, 'migrated')]);

            $this->assertSame(D::InsufficientData, $result->decision);
            $this->assertTrue($result->has(R::MigratedEvidence));
            $this->assertSame(EvidenceQuality::Limited, $result->evidenceQuality);
            $this->assertNull($result->proposedTarget);
        }
    }

    public function test_one_migrated_session_in_the_streak_is_enough_to_stop_an_increase(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9, 40, 'migrated')]);

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::MigratedEvidence));
    }

    public function test_old_migrated_history_does_not_block_a_streak_of_entered_sessions(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9), $this->reached(20, 37.5, 'migrated')]);

        $this->assertSame(D::Increase, $result->decision, 'The migrated session is outside the streak at this load.');
    }

    public function test_a_stale_history_says_nothing_about_today(): void
    {
        $result = $this->evaluate([$this->reached(40), $this->reached(44), $this->reached(48)]);

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::StaleHistory));
    }

    public function test_sessions_without_a_recorded_target_cannot_be_judged(): void
    {
        $free = fn (int $daysAgo) => $this->visit($daysAgo, [[10, 40], [10, 40], [10, 40]], [null, null, null]);

        $result = $this->evaluate([$free(2), $free(5), $free(9)]);

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::NoTargetRecorded));
    }

    public function test_three_sessions_on_one_day_are_one_day_of_evidence(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(2), $this->reached(2)]);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::SessionsOnTooFewDays));
    }

    // ---- identities and types -----------------------------------------------------------------

    public function test_different_exercise_identities_are_never_merged(): void
    {
        $row = $this->visit(2, [[10, 40], [10, 40], [10, 40]], [3, 10, 40], 'entered', 'Dumbbell Row');
        $rows = $this->visit(5, [[10, 40], [10, 40], [10, 40]], [3, 10, 40], 'entered', 'Dumbbell Rows');
        $row2 = $this->visit(9, [[10, 40], [10, 40], [10, 40]], [3, 10, 40], 'entered', 'Dumbbell Row');

        $result = (new ProgressionEngine)->evaluate('Dumbbell Row', 'dumbbell-row', [$row, $rows, $row2], null, new SafetyContext, self::TODAY);

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::MixedExerciseIdentities));
        $this->assertNull($result->proposedTarget);
    }

    public function test_a_duration_based_exercise_is_left_to_a_person(): void
    {
        $plank = fn (int $daysAgo) => $this->visit($daysAgo, [[null, null], [null, null]], [2, null, null], 'entered', 'Plank', durationSeconds: 45);

        $result = (new ProgressionEngine)->evaluate('Plank', 'plank', [$plank(2), $plank(5), $plank(9)], null, new SafetyContext, self::TODAY);

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::TimedExerciseUnsupported));
    }

    public function test_an_exercise_without_a_recorded_load_is_not_assumed_to_be_bodyweight(): void
    {
        $pushUps = fn (int $daysAgo) => $this->visit($daysAgo, [[15, null], [15, null], [15, null]], [3, 15, null], 'entered', 'Push-ups');

        $result = (new ProgressionEngine)->evaluate('Push-ups', 'push-ups', [$pushUps(2), $pushUps(5), $pushUps(9)], null, new SafetyContext, self::TODAY);

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::NoLoadRecorded));
        $this->assertNull($result->proposedTarget);
    }

    public function test_a_load_recorded_for_only_some_sets_stays_unknown(): void
    {
        $mixed = $this->visit(2, [[10, 40], [10, null], [10, 40]], [3, 10, 40]);

        $result = $this->evaluate([$mixed, $this->reached(5), $this->reached(9)]);

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::LoadPartiallyRecorded));
    }

    // ---- limits on the numbers --------------------------------------------------------------------

    public function test_a_plan_already_far_above_the_recorded_load_is_flagged_not_extended(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], ['sets' => 3, 'reps' => 10, 'weight_kg' => 60.0, 'source' => 'next_planned']);

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::CurrentTargetExceedsMaximumChange));
        $this->assertNull($result->proposedTarget);
        $this->assertSame(60.0, $result->currentTarget['weight_kg']);
    }

    public function test_a_plan_within_one_step_of_the_recorded_load_is_not_flagged(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], ['sets' => 3, 'reps' => 10, 'weight_kg' => 40.0, 'source' => 'next_planned']);

        $this->assertSame(D::Increase, $result->decision);
        $this->assertSame('next_planned', $result->currentTarget['source']);
    }

    public function test_a_plan_that_already_carries_the_increase_is_left_alone(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], ['sets' => 3, 'reps' => 10, 'weight_kg' => 42.5, 'source' => 'next_planned']);

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertTrue($result->has(R::TargetAlreadyAtProposed));
    }

    public function test_without_an_upcoming_plan_the_last_sessions_own_target_is_the_current_one(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)]);

        $this->assertSame('last_session_plan', $result->currentTarget['source']);
        $this->assertSame(40.0, $result->currentTarget['weight_kg']);
    }

    public function test_a_light_load_whose_smallest_step_is_too_large_a_jump_is_left_to_a_person(): void
    {
        $result = $this->evaluate([$this->reached(2, 12), $this->reached(5, 12), $this->reached(9, 12)]);

        $this->assertSame(D::ManualReview, $result->decision, '2 kg on 12 kg is 16.7%, above the 10% limit.');
        $this->assertTrue($result->has(R::LoadStepExceedsLimit));
        $this->assertNull($result->proposedTarget);
    }

    public function test_no_proposed_increase_ever_exceeds_the_ceiling_whatever_the_load(): void
    {
        $checked = 0;

        foreach ([1.0, 2.5, 5.0, 7.5, 10.0, 12.5, 15.0, 17.5, 20.0, 22.5, 24.0, 25.0, 30.0, 32.5, 40.0, 42.5, 60.0, 82.5, 100.0, 140.0, 200.0] as $kg) {
            $result = $this->evaluate([$this->reached(2, $kg), $this->reached(5, $kg), $this->reached(9, $kg)]);

            if ($result->decision === D::Increase) {
                $checked++;
                $this->assertLessThanOrEqual(10.0, $result->proposedTarget['change_percent'], "{$kg} kg");
                $this->assertLessThanOrEqual(ProgressionPolicy::ceilingKg($kg), $result->proposedTarget['weight_kg'], "{$kg} kg");
                $this->assertGreaterThan($kg, $result->proposedTarget['weight_kg']);
            } else {
                $this->assertContains($result->decision, [D::ManualReview], "{$kg} kg: either a safe step or a person decides");
                $this->assertNull($result->proposedTarget);
            }
        }

        $this->assertGreaterThan(5, $checked, 'The sweep must actually exercise the increase path.');
    }

    // ---- safety ------------------------------------------------------------------------------------

    public function test_recent_pain_turns_an_increase_into_a_manual_review(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], safety: new SafetyContext(painReportedRecently: true, daysSinceLatestCheckin: 2));

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::PainReportedRecently));
        $this->assertNull($result->proposedTarget);
        $this->assertNotEmpty(array_filter($result->safetyCaveats, fn ($c) => str_contains($c, 'pain')));
    }

    public function test_high_current_soreness_turns_an_increase_into_a_manual_review(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], safety: new SafetyContext(latestSoreness: 5, daysSinceLatestCheckin: 1));

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::HighSoreness));
    }

    public function test_old_soreness_does_not_block_an_increase(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], safety: new SafetyContext(latestSoreness: 5, daysSinceLatestCheckin: 12));

        $this->assertSame(D::Increase, $result->decision);
    }

    public function test_a_saved_restriction_turns_an_increase_into_a_manual_review(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], safety: new SafetyContext(restrictionOnRecord: true, daysSinceLatestCheckin: 1));

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::RestrictionOnRecord));
    }

    public function test_pain_never_blocks_a_reduction_but_is_carried_as_a_caveat(): void
    {
        $result = $this->evaluate([$this->missed(2), $this->missed(5), $this->missed(9)], safety: new SafetyContext(painReportedRecently: true, daysSinceLatestCheckin: 2));

        $this->assertSame(D::Decrease, $result->decision);
        $this->assertNotEmpty(array_filter($result->safetyCaveats, fn ($c) => str_contains($c, 'pain')));
    }

    public function test_missing_recovery_information_is_said_to_be_unknown_not_assumed_fine(): void
    {
        $result = $this->evaluate([$this->reached(2), $this->reached(5), $this->reached(9)], safety: new SafetyContext);

        $this->assertSame(D::Increase, $result->decision, 'Absent check-ins are not evidence of a problem...');
        $this->assertNotEmpty(array_filter($result->safetyCaveats, fn ($c) => str_contains($c, 'recovery is unknown')), '...but the caveat says recovery is unknown.');
    }

    // ---- purity ----------------------------------------------------------------------------------------

    public function test_the_engine_is_deterministic_and_does_not_touch_its_input_or_a_database(): void
    {
        $history = [$this->reached(2), $this->reached(5), $this->reached(9)];
        $before = serialize($history);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $first = $this->evaluate($history)->toArray();
        $second = $this->evaluate($history)->toArray();

        $this->assertSame($first, $second);
        $this->assertSame($before, serialize($history));
        $this->assertSame(0, $queries, 'The engine reads no database.');
    }
}
