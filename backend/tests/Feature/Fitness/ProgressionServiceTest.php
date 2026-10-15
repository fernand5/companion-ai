<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessMemory;
use App\Models\RecoveryCheckin;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Fitness\Progression\ProgressionDecision as D;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Services\Fitness\Progression\ProgressionService;
use App\Services\Fitness\Progression\ReasonCode as R;
use Illuminate\Support\Facades\DB;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * The progression service on history built through the application's own endpoints
 * (the per-set performance endpoint, the one-tap status endpoint, the recovery
 * check-in endpoint), so what the engine decides from is what a real user's data is.
 */
class ProgressionServiceTest extends PerformanceEvalCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->newUser();
    }

    private function service(): ProgressionService
    {
        return app(ProgressionService::class);
    }

    /** @return array<int, array{reps: int, weight_kg: float|int}> */
    private function setsOf(int $count, int $reps, float $kg): array
    {
        return array_fill(0, $count, ['reps' => $reps, 'weight_kg' => $kg]);
    }

    /** Three entered sessions that each reached 3x10 at $kg. */
    private function threeGoodSessions(User $user, string $name = 'Bench Press', float $kg = 40): void
    {
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($user, $daysAgo, $name, [3, 10, $kg], $this->setsOf(3, 10, $kg));
        }
    }

    /** History recovered from older rows, with the planned snapshot a migration would keep. */
    private function migratedSession(User $user, int $daysAgo, string $name, float $kg, array $reps): void
    {
        $session = WorkoutSession::factory()->for($user)->create(['logged_date' => $this->d($daysAgo)]);
        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create([
            'exercise_name' => $name, 'recorded_as' => 'migrated',
            'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => $kg,
        ]);

        foreach ($reps as $i => $r) {
            WorkoutSet::factory()->for($exercise, 'exercise')->create(['set_number' => $i + 1, 'reps' => $r, 'weight_kg' => $kg]);
        }
    }

    /** @return array<int, string> the data-changing statements run inside $work */
    private function writesDuring(callable $work): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $work();

        return $writes;
    }

    private function queriesDuring(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $work();

        return $count;
    }

    // ---- real histories ----------------------------------------------------------------------

    public function test_three_entered_sessions_recorded_through_the_endpoint_support_an_increase(): void
    {
        $this->threeGoodSessions($this->user);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::Increase, $result->decision);
        $this->assertSame(42.5, $result->proposedTarget['weight_kg']);
        $this->assertSame('bench-press', $result->exerciseSlug);
        $this->assertSame('last_session_plan', $result->currentTarget['source']);
        $this->assertCount(3, $result->supportingSessions);
        $this->assertSame('entered', $result->supportingSessions[0]['recorded_as']);
    }

    public function test_the_upcoming_planned_target_is_the_current_target(): void
    {
        $this->threeGoodSessions($this->user);
        $this->upcoming($this->user, 2, 'Bench Press', [3, 10, 40]);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame('next_planned', $result->currentTarget['source']);
        $this->assertSame(D::Increase, $result->decision);
    }

    public function test_a_planned_60_kg_after_40_kg_history_is_flagged_for_a_person(): void
    {
        $this->threeGoodSessions($this->user);
        $this->upcoming($this->user, 2, 'Bench Press', [3, 10, 60]);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::CurrentTargetExceedsMaximumChange));
        $this->assertNull($result->proposedTarget);
    }

    public function test_repeated_misses_recorded_through_the_endpoint_support_a_decrease(): void
    {
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Squat', [3, 8, 60], [['reps' => 8, 'weight_kg' => 60], ['reps' => 7, 'weight_kg' => 60], ['reps' => 6, 'weight_kg' => 60]]);
        }

        $result = $this->service()->recommendFor($this->user, 'Squat');

        $this->assertSame(D::Decrease, $result->decision);
        $this->assertSame(57.5, $result->proposedTarget['weight_kg']);
    }

    public function test_an_incomplete_set_entered_through_the_endpoint_blocks_an_increase(): void
    {
        $this->logSets($this->user, 9, 'Bench Press', [3, 10, 40], $this->setsOf(3, 10, 40));
        $this->logSets($this->user, 5, 'Bench Press', [3, 10, 40], $this->setsOf(3, 10, 40));
        $this->logSets($this->user, 2, 'Bench Press', [3, 10, 40], [['reps' => 10, 'weight_kg' => 40], ['reps' => 10, 'weight_kg' => 40], ['reps' => 10, 'weight_kg' => 40, 'completed' => false]]);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::Maintain, $result->decision);
        $this->assertNull($result->proposedTarget);
    }

    public function test_one_tap_completions_never_count_even_when_there_are_many(): void
    {
        foreach ([13, 9, 5, 2] as $daysAgo) {
            $this->tap($this->user, $daysAgo, 'Bench Press', [3, 10, 40]);
        }

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::NoMeasuredHistory));
        $this->assertTrue($result->has(R::OnlyAssumedCompletions));
        $this->assertNull($result->proposedTarget);
    }

    public function test_migrated_history_cannot_justify_an_increase(): void
    {
        foreach ([9, 5, 2] as $daysAgo) {
            $this->migratedSession($this->user, $daysAgo, 'Bench Press', 40, [10, 10, 10]);
        }

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::MigratedEvidence));
    }

    public function test_similar_but_different_exercise_names_are_never_merged(): void
    {
        $this->logSets($this->user, 9, 'Dumbbell Row', [3, 10, 20], $this->setsOf(3, 10, 20));
        $this->logSets($this->user, 5, 'Dumbbell Rows', [3, 10, 20], $this->setsOf(3, 10, 20));
        $this->logSets($this->user, 2, 'Dumbbell Row', [3, 10, 20], $this->setsOf(3, 10, 20));
        $this->logSets($this->user, 1, 'Dumbbell Rows', [3, 10, 20], $this->setsOf(3, 10, 20));

        $row = $this->service()->recommendFor($this->user, 'Dumbbell Row');
        $rows = $this->service()->recommendFor($this->user, 'Dumbbell Rows');

        foreach ([$row, $rows] as $result) {
            $this->assertSame(D::InsufficientData, $result->decision, 'Two sessions each: merged they would be four.');
            $this->assertTrue($result->has(R::NeedMoreSessions));
            $this->assertCount(2, $result->supportingSessions);
        }
    }

    public function test_a_duration_only_exercise_is_left_to_a_person(): void
    {
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Plank', [2, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45]]);
        }

        $result = $this->service()->recommendFor($this->user, 'Plank');

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::TimedExerciseUnsupported));
    }

    public function test_a_cardio_plan_is_not_strength_history(): void
    {
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Bench Press', [3, 10, 40], $this->setsOf(3, 10, 40), 'recovery');
        }

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::InsufficientData, $result->decision);
        $this->assertTrue($result->has(R::NoMeasuredHistory));
    }

    // ---- safety context ------------------------------------------------------------------------

    public function test_a_pain_note_in_a_recovery_checkin_stops_the_increase(): void
    {
        $this->threeGoodSessions($this->user);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', [
            'checkin_date' => $this->d(1), 'energy' => 3, 'soreness' => 2, 'motivation' => 3, 'pain_notes' => 'Left shoulder hurts when pressing',
        ])->assertSuccessful();

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::PainReportedRecently));
        $this->assertStringNotContainsString('shoulder', json_encode($result->toArray()), 'The note itself is never copied into a recommendation.');
    }

    public function test_a_note_that_says_there_is_no_pain_does_not_count_as_pain(): void
    {
        $this->threeGoodSessions($this->user);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', [
            'checkin_date' => $this->d(1), 'energy' => 4, 'soreness' => 2, 'motivation' => 4, 'pain_notes' => 'None',
        ])->assertSuccessful();

        $this->assertSame(D::Increase, $this->service()->recommendFor($this->user, 'Bench Press')->decision);
    }

    public function test_an_old_pain_note_is_outside_the_window(): void
    {
        $this->threeGoodSessions($this->user);
        RecoveryCheckin::factory()->for($this->user)->create(['checkin_date' => $this->d(ProgressionPolicy::PAIN_WINDOW_DAYS + 6), 'soreness' => 2, 'pain_notes' => 'Knee hurt']);

        $this->assertSame(D::Increase, $this->service()->recommendFor($this->user, 'Bench Press')->decision);
    }

    public function test_high_soreness_in_the_latest_checkin_stops_the_increase(): void
    {
        $this->threeGoodSessions($this->user);
        RecoveryCheckin::factory()->for($this->user)->create(['checkin_date' => $this->d(1), 'soreness' => 5, 'pain_notes' => null]);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::HighSoreness));
    }

    public function test_a_saved_constraint_or_injury_memory_stops_the_increase_but_a_preference_does_not(): void
    {
        $this->threeGoodSessions($this->user);
        FitnessMemory::factory()->for($this->user)->create(['content' => 'Prefers morning workouts', 'category' => 'preference']);

        $this->assertSame(D::Increase, $this->service()->recommendFor($this->user, 'Bench Press')->decision);

        FitnessMemory::factory()->for($this->user)->create(['content' => 'Had shoulder surgery in 2024, avoid heavy overhead work', 'category' => 'constraint']);

        $result = $this->service()->recommendFor($this->user, 'Bench Press');
        $this->assertSame(D::ManualReview, $result->decision);
        $this->assertTrue($result->has(R::RestrictionOnRecord));
    }

    // ---- isolation and purity --------------------------------------------------------------------------

    public function test_one_users_history_and_safety_context_never_reach_another(): void
    {
        $other = $this->newUser();
        $this->threeGoodSessions($other);
        $this->threeGoodSessions($this->user, 'Bench Press', 30);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', [
            'checkin_date' => $this->d(1), 'energy' => 3, 'soreness' => 2, 'motivation' => 3, 'pain_notes' => 'Elbow pain',
        ])->assertSuccessful();

        $mine = $this->service()->recommendFor($this->user, 'Bench Press');
        $theirs = $this->service()->recommendFor($other, 'Bench Press');

        $this->assertSame(D::ManualReview, $mine->decision, 'My pain note applies to me...');
        $this->assertSame(D::Increase, $theirs->decision, '...and not to the other user.');
        $this->assertSame(40.0, $theirs->proposedTarget['from'], "The other user's loads are theirs alone.");
        $this->assertSame(30.0, $mine->supportingSessions[0]['top_load_kg']);

        $stranger = $this->newUser();
        $this->assertSame(D::InsufficientData, $this->service()->recommendFor($stranger, 'Bench Press')->decision);
        $this->assertSame([], $this->service()->recommendAll($stranger));
    }

    public function test_recommendations_write_nothing_to_the_database(): void
    {
        $this->threeGoodSessions($this->user);
        $this->logSets($this->user, 3, 'Squat', [3, 8, 60], $this->setsOf(3, 8, 60));
        $this->upcoming($this->user, 2, 'Bench Press', [3, 10, 40]);
        $before = $this->snapshot();

        $writes = $this->writesDuring(function () {
            $this->service()->recommendFor($this->user, 'Bench Press');
            $this->service()->recommendFor($this->user, 'Nothing Logged');
            $this->service()->recommendAll($this->user);
        });

        $this->assertSame([], $writes, 'No insert, update or delete statement ran.');
        $this->assertSame($before, $this->snapshot(), 'Plans, targets, sessions, sets, logs, memories and profiles are unchanged.');
    }

    public function test_a_recommendation_is_not_applied_to_the_plan(): void
    {
        $this->threeGoodSessions($this->user);
        $plan = $this->upcoming($this->user, 2, 'Bench Press', [3, 10, 40]);

        $this->service()->recommendFor($this->user, 'Bench Press');

        $this->assertSame('40.00', (string) $plan->exercises[0]->fresh()->planned_weight_kg, 'The target the engine would raise is untouched.');
    }

    // ---- cost ------------------------------------------------------------------------------------------

    public function test_asking_about_many_exercises_costs_a_fixed_number_of_queries(): void
    {
        // A user has one plan per day, so each exercise gets its own three days.
        foreach (['Bench Press', 'Squat', 'Deadlift', 'Overhead Press', 'Barbell Row', 'Lunge', 'Curl', 'Dip'] as $i => $name) {
            foreach ([0, 1, 2] as $j) {
                $this->logSets($this->user, 1 + $i * 3 + $j, $name, [3, 10, 40], $this->setsOf(3, 10, 40));
            }
        }

        $one = $this->queriesDuring(fn () => $this->service()->recommendFor($this->user, 'Bench Press'));
        $all = $this->queriesDuring(function () use (&$results) {
            $results = $this->service()->recommendAll($this->user);
        });

        $this->assertCount(8, $results);
        $this->assertLessThanOrEqual(14, $all, "recommendAll used {$all} queries for 8 exercises");
        $this->assertLessThanOrEqual($one + 2, $all, 'Eight exercises cost about the same as one.');
    }

    public function test_recommend_all_is_bounded(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->logSets($this->user, $i, "Exercise {$i}", [3, 10, 20], $this->setsOf(3, 10, 20));
        }

        $this->assertCount(ProgressionService::MAX_EXERCISES, $this->service()->recommendAll($this->user, 500));
        $this->assertCount(8, $this->service()->recommendAll($this->user));
    }
}
