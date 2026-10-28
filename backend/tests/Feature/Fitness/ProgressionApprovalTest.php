<?php

namespace Tests\Feature\Fitness;

use App\Models\FitnessMemory;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\Progression\ProgressionApprovalToken;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * User-approved progression, through the real endpoints. The server issues one sealed
 * proposal per actionable recommendation; the user approves it; the server recomputes,
 * re-checks and changes exactly one future load. Everything else must stay as it was.
 */
class ProgressionApprovalTest extends PerformanceEvalCase
{
    private User $user;

    private string $day;

    private string $laterDay;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->user = $this->newUser();
        $this->day = $this->d(-2);
        $this->laterDay = $this->d(-5);

        // Three entered bench sessions that each reached 3x10 @ 40 kg: an INCREASE to 42.5 kg.
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }

        // The next sessions: bench plus an unrelated squat, with plan metadata that must survive.
        foreach ([$this->day, $this->laterDay] as $date) {
            $plan = app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => "Strength {$date}", 'duration_minutes' => 50, 'notes' => 'Keep this note',
                'reasoning' => 'Because.', 'reasoning_factors' => ['Fresh'],
                'exercises' => [
                    ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40],
                    ['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 60],
                ],
            ]);
            $plan->update(['notes' => 'Keep this note']);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function recommendations(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertOk()->json('data');
    }

    private function item(string $name, ?User $as = null): ?array
    {
        return collect($this->recommendations($as))->first(fn ($i) => $i['exercise']['name'] === $name);
    }

    private function proposalFor(string $name = 'Bench Press', ?User $as = null): string
    {
        $item = $this->item($name, $as);
        $this->assertNotNull($item['proposal'] ?? null, "Precondition: {$name} is actionable.");

        return $item['proposal']['id'];
    }

    private function approve(string $proposal, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => $proposal] + $extra);
    }

    private function row(string $slug, string $date): WorkoutPlanExercise
    {
        return WorkoutPlanExercise::query()
            ->whereHas('workoutPlan', fn ($q) => $q->where('user_id', $this->user->id)->whereDate('planned_date', $date))
            ->where('exercise_slug', $slug)->firstOrFail();
    }

    private function benchWeight(string $date): float
    {
        return (float) $this->row('bench-press', $date)->planned_weight_kg;
    }

    /** Everything that records history or belongs to other exercises, days or users. */
    private function untouched(): array
    {
        return [
            'sessions' => md5(DB::table('workout_sessions')->orderBy('id')->get()->toJson()),
            'exercises (historical snapshots)' => md5(DB::table('workout_exercises')->orderBy('id')->get()->toJson()),
            'sets' => md5(DB::table('workout_sets')->orderBy('id')->get()->toJson()),
            'activity' => md5(DB::table('activity_logs')->orderBy('id')->get()->toJson()),
            'squat rows' => md5(DB::table('workout_plan_exercises')->where('exercise_slug', 'squat')->orderBy('id')->get()->toJson()),
            'later bench' => md5(json_encode($this->row('bench-press', $this->laterDay)->getAttributes())),
            'memories' => md5(DB::table('fitness_memories')->get()->toJson()),
        ];
    }

    // ---- recommendations ----------------------------------------------------------------------

    public function test_recommendations_require_authentication(): void
    {
        $this->app['auth']->forgetGuards(); // setUp acted as the user to build their history

        $this->getJson('/api/progression/recommendations')->assertUnauthorized();
        $this->postJson('/api/progression/approvals', ['proposal' => 'x'])->assertUnauthorized();
        $this->postJson('/api/progression/rejections', ['proposal' => 'x'])->assertUnauthorized();
    }

    public function test_the_list_carries_decisions_reasons_targets_evidence_and_an_opaque_proposal(): void
    {
        $item = $this->item('Bench Press');

        $this->assertSame('INCREASE', $item['decision']);
        $this->assertSame(['TARGETS_REACHED_CONSECUTIVELY'], $item['reason_codes']);
        $this->assertEquals(40.0, $item['current_target']['weight_kg']);
        $this->assertSame('next_planned', $item['current_target']['source']);
        $this->assertEquals(42.5, $item['proposed_target']['weight_kg']);
        $this->assertSame('strong', $item['evidence_quality']);
        $this->assertCount(3, $item['supporting_sessions']);
        $this->assertSame('10,10,10 @ 40 kg', $item['supporting_sessions'][0]['performed']);
        $this->assertNotEmpty($item['safety_caveats']);
        $this->assertSame(['date' => $this->day, 'title' => "Strength {$this->day}", 'plan_status' => 'planned'], $item['applies_to']);
        $this->assertTrue($item['actionable']);
        $this->assertNull($item['not_actionable_reason']);
        $this->assertIsString($item['proposal']['id']);
        $this->assertStringNotContainsString('42.5', $item['proposal']['id'], 'The proposal is opaque.');
        $this->assertStringNotContainsString('bench', strtolower($item['proposal']['id']));
        $this->assertFalse($item['applied']);
    }

    public function test_only_actionable_recommendations_get_a_proposal(): void
    {
        $this->logSets($this->user, 3, 'Pull-up', [3, 8, 10], array_fill(0, 3, ['reps' => 8, 'weight_kg' => 10]));
        foreach ([4, 7, 11] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Plank', [2, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45]]);
        }

        $pullUp = $this->item('Pull-up');
        $this->assertSame('INSUFFICIENT_DATA', $pullUp['decision']);
        $this->assertFalse($pullUp['actionable']);
        $this->assertNull($pullUp['proposal']);
        $this->assertSame('not_a_change', $pullUp['not_actionable_reason']);

        $plank = $this->item('Plank');
        $this->assertSame('MANUAL_REVIEW', $plank['decision']);
        $this->assertNull($plank['proposal']);
    }

    public function test_an_increase_with_no_upcoming_session_to_apply_it_to_is_not_actionable(): void
    {
        WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', '>', now())->get()->each->delete();

        $item = $this->item('Bench Press');

        $this->assertSame('INCREASE', $item['decision']);
        $this->assertFalse($item['actionable']);
        $this->assertSame('no_upcoming_session', $item['not_actionable_reason']);
        $this->assertNull($item['proposal']);
        $this->assertNull($item['applies_to']);
    }

    public function test_listing_writes_nothing_and_never_calls_the_model(): void
    {
        $fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $fake);
        $before = $this->snapshot();
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $this->recommendations();
        $this->recommendations();

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $fake->calls, 'Recommendations are calculated; no model is involved.');
    }

    public function test_the_list_is_bounded_in_size_and_in_queries(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->logSets($this->user, 10 + $i, "Exercise {$i}", [3, 10, 20], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 20]));
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $data = $this->recommendations();

        $this->assertLessThanOrEqual(8, count($data));
        foreach ($data as $item) {
            $this->assertLessThanOrEqual(5, count($item['supporting_sessions']));
        }
        $this->assertLessThanOrEqual(40, $queries, "The list used {$queries} queries.");
    }

    public function test_the_list_is_scoped_to_the_authenticated_user(): void
    {
        $other = $this->newUser();
        $this->logSets($other, 2, 'Deadlift', [3, 5, 100], array_fill(0, 3, ['reps' => 5, 'weight_kg' => 100]));

        $mine = collect($this->recommendations())->pluck('exercise.name');
        $theirs = collect($this->recommendations($other))->pluck('exercise.name');

        $this->assertNotContains('Deadlift', $mine);
        $this->assertContains('Deadlift', $theirs);
        $this->assertNotContains('Bench Press', $theirs);
        // (The sealed proposals are random text, so look at the meaningful fields, not at raw substrings.)
        $mine = array_map(fn ($i) => ['name' => $i['exercise']['name'], 'current' => $i['current_target'], 'sessions' => $i['supporting_sessions']], $this->recommendations());
        $this->assertStringNotContainsString('Deadlift', json_encode($mine), 'No trace of the other user.');
        $this->assertStringNotContainsString('"top_load_kg":100', json_encode($mine));
        $this->assertStringNotContainsString('"weight_kg":100', json_encode($mine));
    }

    // ---- exact binding ---------------------------------------------------------------------------

    public function test_the_proposal_is_bound_to_the_exact_user_row_loads_and_expiry(): void
    {
        $token = ProgressionApprovalToken::open($this->proposalFor());
        $row = $this->row('bench-press', $this->day);

        $this->assertSame($this->user->id, $token->userId);
        $this->assertSame($row->id, $token->planExerciseId);
        $this->assertSame($row->workout_plan_id, $token->planId);
        $this->assertSame('bench-press', $token->exerciseSlug);
        $this->assertSame([40.0, 42.5, $this->day, 'INCREASE'], [$token->fromKg, $token->toKg, $token->date, $token->decision]);
        $this->assertSame((string) $row->getRawOriginal('updated_at'), $token->rowRevision);
        $this->assertEquals(30, CarbonImmutable::parse($token->issuedAt)->diffInMinutes(CarbonImmutable::parse($token->expiresAt)));
    }

    // ---- approval -------------------------------------------------------------------------------

    public function test_approving_changes_exactly_one_future_load_and_nothing_else(): void
    {
        $proposal = $this->proposalFor();
        $untouched = $this->untouched();
        $planBefore = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->day)->first();
        $rowBefore = $this->row('bench-press', $this->day);

        $this->approve($proposal)->assertOk()->assertJson(['applied' => true, 'date' => $this->day, 'from_kg' => 40.0, 'to_kg' => 42.5, 'exercise' => ['name' => 'Bench Press']]);

        $row = $this->row('bench-press', $this->day);
        $this->assertSame(42.5, (float) $row->planned_weight_kg);
        $this->assertSame([3, 10, 'pending', $rowBefore->position, $rowBefore->exercise_name], [$row->planned_sets, $row->planned_reps, $row->status, $row->position, $row->exercise_name]);

        $plan = $planBefore->fresh();
        $this->assertSame(['Strength '.$this->day, 'Keep this note', 50, 'Because.', ['Fresh'], 'planned', 'ai'], [$plan->title, $plan->notes, $plan->duration_minutes, $plan->reasoning, $plan->reasoning_factors, $plan->status, $plan->source]);
        $this->assertSame($planBefore->training_schedule_id, $plan->training_schedule_id);
        $this->assertSame($planBefore->workout_session_id, $plan->workout_session_id);
        $this->assertSame(60.0, (float) $this->row('squat', $this->day)->planned_weight_kg, 'Unrelated exercises keep their targets.');
        $this->assertSame($untouched, $this->untouched(), 'History, snapshots, sets, other exercises and the other future day are unchanged.');
        $this->assertEquals(40.0, $this->benchWeight($this->laterDay), 'Only the next session moves, never several.');
        $this->assertTrue($plan->updated_at->greaterThanOrEqualTo($planBefore->updated_at), 'Cached views of the plan see the change.');
    }

    public function test_the_applied_change_shows_in_the_next_listing(): void
    {
        $this->approve($this->proposalFor())->assertOk();

        $item = $this->item('Bench Press');
        $this->assertSame('MAINTAIN', $item['decision'], 'It is now at the proposed load; the evidence does not ask for another step.');
        $this->assertNull($item['proposal']);
        $this->assertSame(42.5, $item['current_target']['weight_kg']);
    }

    public function test_repeated_misses_support_an_approved_decrease(): void
    {
        foreach ([11, 7, 3] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Deadlift', [3, 8, 100], [['reps' => 8, 'weight_kg' => 100], ['reps' => 7, 'weight_kg' => 100], ['reps' => 6, 'weight_kg' => 100]]);
        }
        $this->upcoming($this->user, 6, 'Deadlift', [3, 8, 100]);

        $item = $this->item('Deadlift');
        $this->assertSame('DECREASE', $item['decision']);
        $this->approve($item['proposal']['id'])->assertOk()->assertJson(['from_kg' => 100.0, 'to_kg' => 97.5]);
        $this->assertSame(97.5, (float) $this->row('deadlift', $this->d(-6))->planned_weight_kg);
    }

    // ---- stale, expired, replayed, concurrent --------------------------------------------------------

    public function test_a_plan_edited_since_the_recommendation_needs_a_fresh_one(): void
    {
        $proposal = $this->proposalFor();
        $this->row('bench-press', $this->day)->update(['planned_weight_kg' => 41]);
        $before = $this->untouched();

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);

        $this->assertSame(41.0, $this->benchWeight($this->day));
        $this->assertSame($before, $this->untouched());
    }

    public function test_a_plan_that_was_replaced_needs_a_fresh_recommendation(): void
    {
        $proposal = $this->proposalFor();
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->day, 'activity_type' => 'strength', 'title' => 'Replaced',
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
        ], replaceExisting: true);

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_new_evidence_since_the_recommendation_makes_it_stale(): void
    {
        $proposal = $this->proposalFor();
        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_an_expired_proposal_is_refused(): void
    {
        $proposal = $this->proposalFor();

        $this->travel(31)->minutes();
        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'expired']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_a_proposal_just_inside_its_window_still_works(): void
    {
        $proposal = $this->proposalFor();

        $this->travel(29)->minutes();
        $this->approve($proposal)->assertOk();
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_a_replayed_approval_changes_nothing_more(): void
    {
        $proposal = $this->proposalFor();
        $this->approve($proposal)->assertOk();

        $this->approve($proposal)->assertStatus(409);
        $this->approve($proposal)->assertStatus(409);

        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_a_replay_is_refused_even_if_the_plan_is_put_back_exactly_as_it_was(): void
    {
        $proposal = $this->proposalFor();
        $token = ProgressionApprovalToken::open($proposal);
        $this->approve($proposal)->assertOk();

        // The row is returned to the exact state the proposal started from, revision included.
        DB::table('workout_plan_exercises')->where('id', $token->planExerciseId)->update(['planned_weight_kg' => 40, 'updated_at' => $token->rowRevision]);

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'already_used']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_a_concurrent_approval_that_lost_the_race_has_no_effect(): void
    {
        $proposal = $this->proposalFor();
        $token = ProgressionApprovalToken::open($proposal);

        // The other request has just taken the proposal; this one arrives after its checks passed.
        Cache::add($token->useKey(), 1, now()->addMinutes(10));
        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'already_used']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_the_database_write_itself_lets_only_one_of_two_attempts_through(): void
    {
        $token = ProgressionApprovalToken::open($this->proposalFor());
        $plans = app(WorkoutPlanService::class);
        $args = [$this->user, $token->planId, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision];

        $this->assertTrue($plans->applyApprovedLoad(...$args));
        $this->assertFalse($plans->applyApprovedLoad(...$args), 'Same revision, same start load: the second finds nothing to change.');
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_the_database_write_refuses_a_row_that_changed_even_if_the_load_is_the_same(): void
    {
        $token = ProgressionApprovalToken::open($this->proposalFor());
        $this->travel(5)->seconds();
        $this->row('bench-press', $this->day)->update(['notes' => 'edited']); // same load, new revision

        $applied = app(WorkoutPlanService::class)->applyApprovedLoad($this->user, $token->planId, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision);

        $this->assertFalse($applied);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_the_database_write_refuses_another_users_row_and_a_wrong_exercise(): void
    {
        $token = ProgressionApprovalToken::open($this->proposalFor());
        $plans = app(WorkoutPlanService::class);

        $this->assertFalse($plans->applyApprovedLoad($this->newUser(), $token->planId, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision));
        $this->assertFalse($plans->applyApprovedLoad($this->user, $token->planId, $token->planExerciseId, 'squat', $token->fromKg, $token->toKg, $token->rowRevision));
        $this->assertFalse($plans->applyApprovedLoad($this->user, $token->planId + 1, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision), 'Another plan.');
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_the_database_write_refuses_a_plan_that_is_skipped_completed_or_in_the_past(): void
    {
        $token = ProgressionApprovalToken::open($this->proposalFor());
        $plans = app(WorkoutPlanService::class);
        $args = [$this->user, $token->planId, $token->planExerciseId, $token->exerciseSlug, $token->fromKg, $token->toKg, $token->rowRevision];

        foreach (['skipped', 'completed', 'partial'] as $status) {
            WorkoutPlan::whereKey($token->planId)->update(['status' => $status]);
            $this->assertFalse($plans->applyApprovedLoad(...$args), "A {$status} plan is not changed.");
        }

        WorkoutPlan::whereKey($token->planId)->update(['status' => 'planned', 'planned_date' => $this->d(1)]);
        $this->assertFalse($plans->applyApprovedLoad(...$args), 'A past day is not an upcoming session.');

        $this->assertSame(40.0, (float) WorkoutPlanExercise::find($token->planExerciseId)->planned_weight_kg);
    }

    // ---- users and payloads ------------------------------------------------------------------------

    public function test_another_users_proposal_is_refused(): void
    {
        $proposal = $this->proposalFor();
        $intruder = $this->newUser();
        $this->logSets($intruder, 2, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        $this->approve($proposal, $intruder)->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingAs($intruder, 'sanctum')->postJson('/api/progression/rejections', ['proposal' => $proposal])->assertForbidden();

        $this->assertSame(40.0, $this->benchWeight($this->day));
        $this->approve($proposal)->assertOk();
    }

    public function test_client_supplied_values_are_never_authority(): void
    {
        $proposal = $this->proposalFor();
        $row = $this->row('bench-press', $this->day);

        foreach ([['weight_kg' => 100], ['to_kg' => 100], ['plan_exercise_id' => $this->row('bench-press', $this->laterDay)->id], ['exercise' => 'Squat'], ['date' => $this->laterDay]] as $extra) {
            $this->approve($proposal, null, $extra)->assertStatus(422);
        }

        $this->assertSame(40.0, (float) $row->fresh()->planned_weight_kg);
        $this->assertSame(40.0, $this->benchWeight($this->laterDay));
        $this->approve($proposal)->assertOk();
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_altered_foreign_and_malformed_proposals_are_rejected(): void
    {
        $proposal = $this->proposalFor();
        $flipped = substr($proposal, 0, -4).(substr($proposal, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
        $otherPurpose = Crypt::encryptString(json_encode(['purpose' => 'something_else', 'user_id' => $this->user->id]));
        $unsealed = base64_encode(json_encode(['user_id' => $this->user->id, 'to_kg' => 100]));

        foreach ([$flipped, substr($proposal, 0, 40), $otherPurpose, $unsealed, 'not-a-proposal', str_repeat('A', 3000)] as $bad) {
            $this->approve($bad)->assertStatus(422)->assertJson(['code' => 'invalid_proposal']);
        }

        $this->approve('')->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', [])->assertStatus(422);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_a_validly_sealed_proposal_cannot_exceed_what_the_engine_calculates(): void
    {
        $row = $this->row('bench-press', $this->day);
        // Sealed correctly by the application key, but asking for 50 kg: more than any recommendation supports.
        $overreach = ProgressionApprovalToken::issue($this->user->id, $row->workout_plan_id, $row->id, (string) $row->getRawOriginal('updated_at'), $this->day, 'bench-press', 'Bench Press', 'INCREASE', 40, 50, 'whatever')->seal();

        $this->approve($overreach)->assertStatus(409)->assertJson(['code' => 'stale']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    // ---- safety changes after issue ----------------------------------------------------------------------

    public function test_a_pain_note_after_the_recommendation_blocks_the_approval(): void
    {
        $proposal = $this->proposalFor();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', ['checkin_date' => $this->d(0), 'energy' => 3, 'soreness' => 2, 'motivation' => 3, 'pain_notes' => 'Left shoulder hurts when pressing'])->assertSuccessful();

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame(40.0, $this->benchWeight($this->day));
        $this->assertSame('MANUAL_REVIEW', $this->item('Bench Press')['decision']);
        $this->assertNull($this->item('Bench Press')['proposal']);
    }

    public function test_a_saved_restriction_after_the_recommendation_blocks_the_approval(): void
    {
        $proposal = $this->proposalFor();
        FitnessMemory::factory()->for($this->user)->create(['content' => 'Doctor said avoid heavy pressing', 'category' => 'constraint']);

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_high_soreness_after_the_recommendation_blocks_the_approval(): void
    {
        $proposal = $this->proposalFor();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', ['checkin_date' => $this->d(0), 'energy' => 2, 'soreness' => 5, 'motivation' => 2])->assertSuccessful();

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    // ---- completed and started workouts ------------------------------------------------------------------------

    public function test_a_workout_started_since_the_recommendation_is_not_changed(): void
    {
        $proposal = $this->proposalFor();
        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->day)->first();
        $squat = $this->row('squat', $this->day);
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$squat->id}", ['status' => 'completed', 'actual_sets' => 3, 'actual_reps' => 8, 'actual_weight_kg' => 60])->assertOk();

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame(40.0, $this->benchWeight($this->day), 'The day already has recorded performance.');
        $this->assertNull($this->item('Bench Press')['proposal'], 'And it is no longer offered.');
    }

    public function test_the_exercise_itself_marked_done_is_not_changed(): void
    {
        $proposal = $this->proposalFor();
        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->day)->first();
        $bench = $this->row('bench-press', $this->day);
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/workout-plans/{$plan->id}/exercises/{$bench->id}", ['status' => 'skipped'])->assertOk();

        $this->approve($proposal)->assertStatus(409);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_a_past_session_is_never_a_target(): void
    {
        $this->travel(3)->days();

        $item = $this->item('Bench Press');
        $this->assertNotSame($this->day, $item['applies_to']['date'] ?? null, 'The day has passed; it is not an upcoming session.');
    }

    // ---- keeping the current target (durable, exact, reversible by new evidence) -------------------------

    private function decline(string $proposal, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/progression/rejections', ['proposal' => $proposal] + $extra);
    }

    private function dismissals(?User $for = null): int
    {
        return DB::table('progression_dismissals')->where('user_id', ($for ?? $this->user)->id)->count();
    }

    public function test_declining_is_remembered_and_the_same_recommendation_is_not_offered_again(): void
    {
        $proposal = $this->proposalFor();
        $before = $this->snapshot() + $this->untouched();

        $this->decline($proposal)->assertOk()->assertJson(['kept' => true]);

        // "Refreshing the page": a brand new request for the list.
        $item = $this->item('Bench Press');
        $this->assertFalse($item['actionable']);
        $this->assertSame('dismissed', $item['not_actionable_reason']);
        $this->assertNull($item['proposal']);
        $this->assertNotNull($item['dismissed']['at']);
        $this->assertSame('INCREASE', $item['decision'], 'The calculation is unchanged; only the offer is withdrawn.');

        $this->assertSame(40.0, $this->benchWeight($this->day), 'Declining never changes a target.');
        $this->assertSame($before, $this->snapshot() + $this->untouched());
        $this->assertSame(1, $this->dismissals());
        $this->assertEquals(['user_id' => $this->user->id, 'exercise_slug' => 'bench-press', 'workout_plan_exercise_id' => $this->row('bench-press', $this->day)->id], collect(DB::table('progression_dismissals')->first())->only(['user_id', 'exercise_slug', 'workout_plan_exercise_id'])->all());
    }

    public function test_a_declined_proposal_cannot_be_approved_later_from_another_tab(): void
    {
        $proposal = $this->proposalFor();
        $this->decline($proposal)->assertOk();

        $this->approve($proposal)->assertStatus(409)->assertJson(['code' => 'dismissed']);

        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_declining_one_exercise_does_not_suppress_progression_for_others(): void
    {
        foreach ([11, 7, 3] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Deadlift', [3, 8, 100], [['reps' => 8, 'weight_kg' => 100], ['reps' => 7, 'weight_kg' => 100], ['reps' => 6, 'weight_kg' => 100]]);
        }
        $this->upcoming($this->user, 6, 'Deadlift', [3, 8, 100]);

        $this->decline($this->proposalFor('Bench Press'))->assertOk();

        $this->assertFalse($this->item('Bench Press')['actionable']);
        $this->assertTrue($this->item('Deadlift')['actionable']);
    }

    public function test_repeated_declines_are_idempotent(): void
    {
        $proposal = $this->proposalFor();

        foreach (range(1, 4) as $_) {
            $this->decline($proposal)->assertOk()->assertJson(['kept' => true]);
        }

        $this->assertSame(1, $this->dismissals(), 'Four declines, one record.');
        $this->assertSame('dismissed', $this->item('Bench Press')['not_actionable_reason']);
        $this->assertSame(40.0, $this->benchWeight($this->day));
    }

    public function test_simultaneous_declines_cannot_make_two_records(): void
    {
        $proposal = $this->proposalFor();
        $token = ProgressionApprovalToken::open($proposal);

        // The database refuses a second record for the same user and plan row, whoever writes it.
        $this->decline($proposal)->assertOk();
        $this->expectException(QueryException::class);
        DB::table('progression_dismissals')->insert(['user_id' => $this->user->id, 'workout_plan_exercise_id' => $token->planExerciseId, 'exercise_slug' => 'bench-press', 'fingerprint' => 'x', 'dismissed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_new_measured_performance_makes_a_fresh_recommendation_eligible(): void
    {
        $old = $this->proposalFor();
        $this->decline($old)->assertOk();
        $this->assertFalse($this->item('Bench Press')['actionable']);

        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        $item = $this->item('Bench Press');
        $this->assertTrue($item['actionable'], 'New evidence is a materially new recommendation.');
        $this->assertCount(4, $item['supporting_sessions']);
        $this->assertNotSame($old, $item['proposal']['id']);
        $this->approve($item['proposal']['id'])->assertOk();
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_an_edited_target_makes_the_old_dismissal_stop_matching(): void
    {
        $this->decline($this->proposalFor())->assertOk();
        $this->row('bench-press', $this->day)->update(['planned_weight_kg' => 41]);

        $item = $this->item('Bench Press');

        $this->assertTrue($item['actionable']);
        $this->assertNull($item['dismissed']);
        $this->assertSame(1, $this->dismissals(), 'The old record stays but no longer matches anything.');
    }

    public function test_a_replaced_plan_invalidates_the_dismissal(): void
    {
        $this->decline($this->proposalFor())->assertOk();
        $this->assertSame(1, $this->dismissals());

        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->day, 'activity_type' => 'strength', 'title' => 'Replaced',
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
        ], replaceExisting: true);

        $this->assertSame(0, $this->dismissals(), 'The record goes with the plan row it was about.');
        $this->assertTrue($this->item('Bench Press')['actionable']);
    }

    public function test_the_dismissal_ends_with_the_session_it_was_about(): void
    {
        $this->decline($this->proposalFor())->assertOk();

        // That day passes: the next upcoming bench session is a different row, so nothing is carried over.
        $this->travel(3)->days();
        $item = $this->item('Bench Press');

        $this->assertNotSame('dismissed', $item['not_actionable_reason']);
        $this->assertNotSame($this->day, $item['applies_to']['date'] ?? null);
    }

    public function test_another_users_dismissals_and_proposals_are_isolated(): void
    {
        $proposal = $this->proposalFor();
        $other = $this->newUser();
        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($other, $daysAgo, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->upcoming($other, 2, 'Bench Press', [3, 10, 40]);

        $this->decline($proposal)->assertOk();
        $this->assertTrue($this->item('Bench Press', $other)['actionable'], 'The other user is unaffected.');

        $this->decline($proposal, $other)->assertForbidden();
        $this->assertSame(0, $this->dismissals($other), 'And cannot decline on anyone else\'s behalf.');
        $this->assertSame(1, $this->dismissals());

        $this->decline($this->proposalFor('Bench Press', $other), $other)->assertOk();
        $this->assertSame(1, $this->dismissals($other));
        $this->assertSame('dismissed', $this->item('Bench Press')['not_actionable_reason'], 'Their decline did not touch mine.');
    }

    public function test_declining_a_proposal_that_is_no_longer_current_records_nothing(): void
    {
        $proposal = $this->proposalFor();
        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        $this->decline($proposal)->assertStatus(409)->assertJson(['code' => 'stale']);

        $this->assertSame(0, $this->dismissals());
        $this->assertTrue($this->item('Bench Press')['actionable'], 'The fresh recommendation is still on offer.');
    }

    public function test_a_proposal_that_expired_but_is_still_current_can_still_be_declined(): void
    {
        $proposal = $this->proposalFor();

        $this->travel(31)->minutes();
        $this->decline($proposal)->assertOk();

        $this->assertSame('dismissed', $this->item('Bench Press')['not_actionable_reason']);
    }

    public function test_declining_rejects_client_values_and_garbage(): void
    {
        $proposal = $this->proposalFor();

        $this->decline($proposal, null, ['exercise' => 'Squat', 'plan_exercise_id' => 1])->assertStatus(422);
        $this->decline('garbage')->assertStatus(422);
        $this->decline('')->assertStatus(422);

        $this->assertSame(0, $this->dismissals());
    }

    // ---- the coach cannot reach it ------------------------------------------------------------------------------------

    public function test_the_coach_has_no_way_to_issue_or_approve_a_proposal(): void
    {
        config(['ai.progression_tool_enabled' => true]);
        $this->app->forgetInstance(ToolRegistry::class);
        $registry = app(ToolRegistry::class);

        foreach ($registry->all() as $tool) {
            $this->assertDoesNotMatchRegularExpression('/approv|proposal|apply_progression/i', $tool->name());
        }

        $result = $registry->call('get_progression_recommendation', ['exercise_name' => 'Bench Press'], $this->user);
        $json = json_encode($result);

        $this->assertStringNotContainsString('"proposal"', $json);
        $this->assertSame('INCREASE', $result['recommendations'][0]['decision']);
        $this->assertSame(40.0, $this->benchWeight($this->day), 'Reading a recommendation applies nothing.');
    }
}
