<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessMemory;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Progression in the context of the upcoming workouts: what the planning view proposes, what it
 * deliberately leaves alone, and that nothing in it changes a plan without a separate, explicit
 * approval for each exercise.
 */
class AdaptivePlanningTest extends PerformanceEvalCase
{
    private User $user;

    private string $tuesday;

    private string $thursday;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->tuesday = $this->d(-2);
        $this->thursday = $this->d(-4);

        // Bench: three reached sessions (an increase). Deadlift: three misses (a decrease).
        // Squat: reached, missed, reached (inconsistent: no change). Pull-up: one session. Plank: duration only.
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        foreach ([13, 10, 7] as $ago) {
            $this->logSets($this->user, $ago, 'Deadlift', [3, 8, 100], [['reps' => 8, 'weight_kg' => 100], ['reps' => 7, 'weight_kg' => 100], ['reps' => 6, 'weight_kg' => 100]]);
        }
        foreach ([14 => true, 11 => false, 8 => true] as $ago => $reached) {
            $this->logSets($this->user, $ago, 'Squat', [3, 8, 60], $reached ? array_fill(0, 3, ['reps' => 8, 'weight_kg' => 60]) : [['reps' => 8, 'weight_kg' => 60], ['reps' => 8, 'weight_kg' => 60], ['reps' => 6, 'weight_kg' => 60]]);
        }
        $this->logSets($this->user, 12, 'Pull-up', [3, 8, 10], array_fill(0, 3, ['reps' => 8, 'weight_kg' => 10]));
        foreach ([17, 16, 15] as $ago) {
            $this->logSets($this->user, $ago, 'Plank', [2, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45]]);
        }

        foreach ([$this->tuesday => 'Upper', $this->thursday => 'Lower'] as $date => $title) {
            app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => $title,
                'exercises' => [
                    ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40],
                    ['exercise_name' => 'Deadlift', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 100],
                    ['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 60],
                ],
            ]);
        }
    }

    // ---- helpers -------------------------------------------------------------------------------------

    private function plan(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertOk()->json();
    }

    private function item(string $name, ?User $as = null): ?array
    {
        return collect($this->plan($as)['data'])->first(fn ($i) => $i['exercise']['name'] === $name);
    }

    private function row(string $slug, string $date): WorkoutPlanExercise
    {
        return WorkoutPlanExercise::query()
            ->whereHas('workoutPlan', fn ($q) => $q->where('user_id', $this->user->id)->whereDate('planned_date', $date))
            ->where('exercise_slug', $slug)->firstOrFail();
    }

    private function load(string $slug, string $date): float
    {
        return (float) $this->row($slug, $date)->planned_weight_kg;
    }

    private function loads(): array
    {
        return WorkoutPlanExercise::query()->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->exercise_slug, (string) $r->planned_weight_kg, $r->planned_sets, $r->planned_reps, $r->status])->all();
    }

    private function approve(string $proposal, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => $proposal]);
    }

    // ---- what is proposed ---------------------------------------------------------------------------

    public function test_eligible_progression_is_proposed_for_the_next_session_of_each_exercise(): void
    {
        $plan = $this->plan();

        $bench = $this->item('Bench Press');
        $this->assertSame(['suggested', 'INCREASE', 42.5], [$bench['state'], $bench['decision'], $bench['proposed_target']['weight_kg']]);
        $this->assertSame($this->tuesday, $bench['applies_to']['date']);
        $this->assertSame('Upper', $bench['applies_to']['title']);
        $this->assertEquals(40.0, $bench['current_target']['weight_kg']);
        $this->assertContains('TARGETS_REACHED_CONSECUTIVELY', $bench['reason_codes']);
        $this->assertIsString($bench['proposal']['id']);

        $deadlift = $this->item('Deadlift');
        $this->assertSame(['suggested', 'DECREASE', 97.5], [$deadlift['state'], $deadlift['decision'], $deadlift['proposed_target']['weight_kg']]);

        $this->assertSame(2, $plan['summary']['suggested']);
        $this->assertSame($this->tuesday, $plan['sessions'][0]['date']);
        $this->assertSame(['Upper', 2], [$plan['sessions'][0]['title'], $plan['sessions'][0]['suggested']]);
    }

    public function test_ineligible_progression_is_excluded_and_leaves_the_plan_alone(): void
    {
        $squat = $this->item('Squat');
        $this->assertSame(['no_change', 'MAINTAIN', null, false], [$squat['state'], $squat['decision'], $squat['proposal'], $squat['actionable']]);

        $pullUp = $this->item('Pull-up');
        $this->assertSame(['insufficient_data', 'INSUFFICIENT_DATA', null], [$pullUp['state'], $pullUp['decision'], $pullUp['proposal']]);

        $plank = $this->item('Plank');
        $this->assertSame(['needs_review', 'MANUAL_REVIEW', null], [$plank['state'], $plank['decision'], $plank['proposal']]);
    }

    public function test_exercises_with_different_evidence_are_each_judged_on_their_own(): void
    {
        $quality = collect($this->plan()['data'])->mapWithKeys(fn ($i) => [$i['exercise']['name'] => [$i['evidence_quality'], $i['state']]])->all();

        $this->assertSame(['strong', 'suggested'], $quality['Bench Press']);
        $this->assertSame(['strong', 'suggested'], $quality['Deadlift']);
        $this->assertSame(['strong', 'no_change'], $quality['Squat']);
        $this->assertSame(['insufficient', 'insufficient_data'], $quality['Pull-up']);
        $this->assertSame(['limited', 'needs_review'], $quality['Plank']);
    }

    public function test_the_summary_counts_every_state_once(): void
    {
        $summary = $this->plan()['summary'];

        $this->assertSame(['suggested' => 2, 'kept' => 0, 'needs_review' => 1, 'insufficient_data' => 1, 'no_change' => 1, 'unavailable' => 0], $summary);
        $this->assertSame(count($this->plan()['data']), array_sum($summary));
    }

    public function test_with_nothing_eligible_the_plan_is_unchanged(): void
    {
        $quiet = $this->newUser();
        $this->logSets($quiet, 3, 'Pull-up', [3, 8, 10], array_fill(0, 3, ['reps' => 8, 'weight_kg' => 10]));
        $before = $this->snapshot();

        $plan = $this->plan($quiet);

        $this->assertSame(0, $plan['summary']['suggested']);
        $this->assertSame([], array_filter($plan['data'], fn ($i) => $i['proposal'] !== null));
        $this->assertSame($before, $this->snapshot());
    }

    // ---- no duplicates, no silent multiplication ----------------------------------------------------------------

    public function test_there_is_exactly_one_item_per_exercise_and_at_most_one_suggestion_per_plan_row(): void
    {
        $data = $this->plan()['data'];

        $slugs = array_column(array_column($data, 'exercise'), 'slug');
        $this->assertSame($slugs, array_values(array_unique($slugs)), 'No exercise appears twice.');

        $suggested = array_filter($data, fn ($i) => $i['proposal'] !== null);
        $rowKeys = array_map(fn ($i) => $i['exercise']['slug'].'@'.$i['applies_to']['date'], $suggested);
        $this->assertSame($rowKeys, array_values(array_unique($rowKeys)), 'No plan row has two suggestions.');
    }

    public function test_later_sessions_with_the_same_exercise_are_listed_as_untouched_never_changed(): void
    {
        $bench = $this->item('Bench Press');

        $this->assertSame([['date' => $this->thursday, 'title' => 'Lower']], $bench['later_sessions']);

        $this->approve($bench['proposal']['id'])->assertOk();

        $this->assertSame(42.5, $this->load('bench-press', $this->tuesday));
        $this->assertEquals(40.0, $this->load('bench-press', $this->thursday), 'The later session is not changed with the next one.');
    }

    public function test_sessions_beyond_the_horizon_are_not_listed_as_later_sessions(): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->d(-40), 'activity_type' => 'strength', 'title' => 'Far',
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
        ]);

        $this->assertNotContains($this->d(-40), array_column($this->item('Bench Press')['later_sessions'], 'date'));
    }

    // ---- approval is separate and per exercise -----------------------------------------------------------------------------

    public function test_viewing_the_plan_adjustments_never_changes_anything(): void
    {
        $before = $this->loads();
        $dbBefore = $this->snapshot();
        $fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $fake);
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $this->plan();
        $this->plan();

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->loads());
        $this->assertSame($dbBefore, $this->snapshot());
        $this->assertSame([], $fake->calls, 'No model is involved in calculating or proposing.');
    }

    public function test_there_is_no_way_to_approve_several_exercises_at_once(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'progression'))->map(fn ($r) => $r->methods()[0].' '.$r->uri())->sort()->values()->all();

        $this->assertSame(['GET api/progression/recommendations', 'POST api/progression/approvals', 'POST api/progression/rejections'], $routes);

        $bench = $this->item('Bench Press')['proposal']['id'];
        $deadlift = $this->item('Deadlift')['proposal']['id'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => [$bench, $deadlift]])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposals' => [$bench, $deadlift]])->assertStatus(422);
    }

    public function test_approving_one_exercise_does_not_approve_another(): void
    {
        $bench = $this->item('Bench Press')['proposal']['id'];
        $deadlift = $this->item('Deadlift')['proposal']['id'];

        $this->approve($bench)->assertOk();

        $this->assertSame(42.5, $this->load('bench-press', $this->tuesday));
        $this->assertEquals(100.0, $this->load('deadlift', $this->tuesday), 'Approving bench did not approve deadlift.');
        $this->assertSame('suggested', $this->item('Deadlift')['state'], 'Deadlift is still waiting for its own approval.');

        $this->approve($deadlift)->assertOk();
        $this->assertSame(97.5, $this->load('deadlift', $this->tuesday));
        $this->assertEquals(60.0, $this->load('squat', $this->tuesday), 'The unrelated exercise was never touched.');
    }

    public function test_a_declined_recommendation_is_respected_and_the_others_are_not_affected(): void
    {
        $bench = $this->item('Bench Press')['proposal']['id'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/rejections', ['proposal' => $bench])->assertOk();

        $plan = $this->plan();
        $kept = $this->item('Bench Press');

        $this->assertSame(['kept', null], [$kept['state'], $kept['proposal']]);
        $this->assertSame(1, $plan['summary']['kept']);
        $this->assertSame(1, $plan['summary']['suggested']);
        $this->approve($bench)->assertStatus(409)->assertJson(['code' => 'dismissed']);
        $this->assertEquals(40.0, $this->load('bench-press', $this->tuesday));
    }

    // ---- stale, replaced, started and completed -------------------------------------------------------------------------------

    public function test_new_evidence_makes_an_issued_proposal_stale(): void
    {
        $proposal = $this->item('Bench Press')['proposal']['id'];
        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);

        $this->assertEquals(40.0, $this->load('bench-press', $this->tuesday));
        $this->assertNotSame($proposal, $this->item('Bench Press')['proposal']['id'], 'A fresh recommendation takes its place.');
    }

    public function test_a_replaced_plan_invalidates_the_proposal(): void
    {
        $proposal = $this->item('Bench Press')['proposal']['id'];
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->tuesday, 'activity_type' => 'strength', 'title' => 'Replaced',
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
        ]);

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);
        $this->assertEquals(40.0, $this->load('bench-press', $this->tuesday));
        $this->assertSame('Replaced', $this->item('Bench Press')['applies_to']['title']);
    }

    public function test_a_started_workout_is_never_adjusted(): void
    {
        $proposal = $this->item('Bench Press')['proposal']['id'];
        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->tuesday)->first();
        $squat = $this->row('squat', $this->tuesday);
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$squat->id}", ['status' => 'completed', 'actual_sets' => 3, 'actual_reps' => 8, 'actual_weight_kg' => 60])->assertOk();
        $before = $this->loads();

        $item = $this->item('Bench Press');
        $this->assertSame(['unavailable', 'workout_already_started', null], [$item['state'], $item['not_actionable_reason'], $item['proposal']]);
        $this->assertSame('in_progress', $item['applies_to']['plan_status']);

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame($before, $this->loads());
    }

    public function test_a_completed_workout_is_never_adjusted(): void
    {
        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->tuesday)->first();
        $proposal = $this->item('Bench Press')['proposal']['id'];
        foreach (['bench-press', 'deadlift', 'squat'] as $slug) {
            $row = $this->row($slug, $this->tuesday);
            $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$row->id}", ['status' => 'completed', 'actual_sets' => 3, 'actual_reps' => 8, 'actual_weight_kg' => 40])->assertOk();
        }
        $before = $this->loads();

        $this->approve($proposal)->assertStatus(409);

        $this->assertSame($before, $this->loads());
        $this->assertSame(1, WorkoutPlan::whereDate('planned_date', $this->tuesday)->where('user_id', $this->user->id)->where('status', 'completed')->count());
    }

    public function test_changed_safety_conditions_withdraw_every_increase(): void
    {
        $bench = $this->item('Bench Press')['proposal']['id'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', ['checkin_date' => $this->d(0), 'energy' => 3, 'soreness' => 2, 'motivation' => 3, 'pain_notes' => 'Elbow pain'])->assertSuccessful();

        $plan = $this->plan();

        $this->assertSame(1, $plan['summary']['suggested'], 'Pain stops every increase; only the reduction remains on offer.');
        $this->assertSame('needs_review', $this->item('Bench Press')['state']);
        $this->approve($bench)->assertStatus(409);
        $this->assertEquals(40.0, $this->load('bench-press', $this->tuesday));
        $this->assertSame('suggested', $this->item('Deadlift')['state'], 'A reduction is never blocked by pain.');
    }

    public function test_a_saved_restriction_withdraws_increases_but_not_reductions(): void
    {
        FitnessMemory::factory()->for($this->user)->create(['content' => 'Avoid heavy pressing, shoulder surgery', 'category' => 'constraint']);

        $this->assertSame('needs_review', $this->item('Bench Press')['state']);
        $this->assertSame('suggested', $this->item('Deadlift')['state']);
    }

    // ---- concurrency, users, cost ------------------------------------------------------------------------------------------------

    public function test_approving_the_same_proposal_twice_changes_it_once(): void
    {
        $proposal = $this->item('Bench Press')['proposal']['id'];

        $this->approve($proposal)->assertOk();
        $this->approve($proposal)->assertStatus(409);

        $this->assertSame(42.5, $this->load('bench-press', $this->tuesday));
    }

    public function test_approvals_for_different_exercises_are_independent_and_both_apply(): void
    {
        $bench = $this->item('Bench Press')['proposal']['id'];
        $deadlift = $this->item('Deadlift')['proposal']['id'];

        $this->approve($deadlift)->assertOk();
        $this->approve($bench)->assertOk();

        $this->assertSame([42.5, 97.5], [$this->load('bench-press', $this->tuesday), $this->load('deadlift', $this->tuesday)]);
    }

    public function test_another_users_plan_and_proposals_are_isolated(): void
    {
        $other = $this->newUser();
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($other, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        app(WorkoutPlanService::class)->createOrReplace($other, ['planned_date' => $this->d(-3), 'activity_type' => 'strength', 'title' => 'Theirs', 'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]]]);

        $mine = $this->item('Bench Press');
        $theirs = $this->item('Bench Press', $other);

        $this->assertSame('Upper', $mine['applies_to']['title']);
        $this->assertSame('Theirs', $theirs['applies_to']['title']);
        $this->assertSame([], $theirs['later_sessions']);
        $this->assertSame(1, count($this->plan($other)['data']));
        $this->approve($mine['proposal']['id'], $other)->assertForbidden();
        $this->assertEquals(40.0, $this->load('bench-press', $this->tuesday));
    }

    public function test_the_planning_view_costs_a_bounded_number_of_queries(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->plan();

        $this->assertLessThanOrEqual(20, $queries, "The planning view used {$queries} queries for 5 exercises.");
    }
}
