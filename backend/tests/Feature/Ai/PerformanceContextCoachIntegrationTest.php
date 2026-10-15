<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\FitnessContextBuilder;
use App\Services\Fitness\PerformanceContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Phase 4A: the bounded performance history reaches the coach in ordinary chat.
 * Every test goes through the real chat endpoint with a fake provider that
 * records exactly what the model is sent. The history is only for reading; none
 * of this lets the coach change a load, a rep target or a plan.
 */
class PerformanceContextCoachIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 17:00:00'); // 12:00 in Bogota

        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        $this->user = User::factory()->create(['timezone' => 'America/Bogota']);

        // Measured strength, through the real performance endpoint.
        $this->enter($this->user, $this->plan($this->user, '2026-10-01', [['Bench Press', 3, 10, 40]]), 0, [[10, 40], [9, 40], [8, 40]]);
        $this->enter($this->user, $this->plan($this->user, '2026-10-05', [['Bench Press', 3, 10, 42.5]]), 0, [[10, 42.5], [10, 42.5], [9, 42.5]]);

        // One-tap completions: a lift, and a timed warm-up inside the same (strength) plan.
        $mixed = $this->plan($this->user, '2026-10-03', [['Dumbbell Row', 3, 12, 22.5], ['Treadmill Warm-up', null, null, null, 600]]);
        $this->tap($this->user, $mixed, 0, 'completed');
        $this->tap($this->user, $mixed, 1, 'completed');

        // A treadmill PLAN completed with one tap, and a run the user logged themselves.
        $this->tap($this->user, $this->plan($this->user, '2026-10-04', [['Easy Treadmill', null, null, null, 900]], 'treadmill'), 0, 'completed');
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 4])->assertCreated();

        // Older history recovered from legacy rows.
        $session = WorkoutSession::factory()->for($this->user)->create(['logged_date' => '2026-09-20']);
        $legacy = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => 'Pull-ups', 'recorded_as' => 'migrated']);
        WorkoutSet::factory()->for($legacy, 'exercise')->create(['set_number' => 1, 'reps' => 5, 'weight_kg' => null]);

        // What is coming up.
        $this->plan($this->user, '2026-10-08', [['Bench Press', 3, 10, 45]]);
    }

    // ---- helpers: real user actions -----------------------------------------------

    /**
     * @param  array<int, array{0: string, 1: ?int, 2: ?int, 3: float|int|null, 4?: ?int}>  $exercises
     */
    private function plan(User $user, string $date, array $exercises, string $type = 'strength'): WorkoutPlan
    {
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => $date, 'activity_type' => $type, 'title' => 'Plan '.$date]);

        foreach ($exercises as $i => $e) {
            WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
                'exercise_name' => $e[0], 'position' => $i, 'planned_sets' => $e[1], 'planned_reps' => $e[2],
                'planned_weight_kg' => $e[3], 'planned_duration_seconds' => $e[4] ?? null,
            ]);
        }

        return $plan->load('exercises');
    }

    private function enter(User $as, WorkoutPlan $plan, int $position, array $sets): void
    {
        $this->actingAs($as, 'sanctum')->putJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}/performance",
            ['sets' => array_map(fn ($s) => ['reps' => $s[0], 'weight_kg' => $s[1]], $sets)],
        )->assertOk();
    }

    private function tap(User $as, WorkoutPlan $plan, int $position, string $status): void
    {
        $this->actingAs($as, 'sanctum')->patchJson(
            "/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[$position]->id}",
            ['status' => $status],
        )->assertOk();
    }

    /** One real chat turn; returns the system prompt exactly as the model received it. */
    private function chat(?User $as = null, string $message = 'How has my bench press been going?'): string
    {
        $as ??= $this->user;
        $this->fake->calls = [];

        $conversationId = $this->actingAs($as, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        $this->actingAs($as, 'sanctum')->postJson("/api/conversations/{$conversationId}/messages", ['content' => $message])->assertCreated();

        return $this->fake->calls[0]['messages'][0]['content'];
    }

    /** The "Recent performance" section of a system prompt, or null when it is absent. */
    private function section(string $prompt): ?string
    {
        return preg_match('/## Recent performance[^\n]*\n(.*?)\n\n## /s', $prompt, $m) ? $m[1] : null;
    }

    // ---- 1-3: the coach receives the history, exact, with its targets ------------------

    public function test_a_normal_coach_request_receives_the_performance_context_as_the_model_sees_it(): void
    {
        $section = $this->section($this->chat());

        $expected = <<<'TXT'
Bench Press
- 2026-10-01: performed 10,9,8 @ 40 kg | target 3×10 @ 40 kg — NOT reached (1 of 3 sets did) | entered
- 2026-10-05: performed 10,10,9 @ 42.5 kg | target 3×10 @ 42.5 kg — NOT reached (2 of 3 sets did) | entered
Change 2026-10-01 → 2026-10-05: sets 3→3 | total reps 27→29 (+2) | top load 40→42.5 kg (+2.5) | volume (reps × kg) 1080→1232.5 kg (+152.5) | trend: 2 measured sessions: a comparison, not a trend
Next planned (2026-10-08): 3×10 @ 45 kg

Dumbbell Row
- 2026-10-03: completed as planned | performance not measured | plan only: 3×12 @ 22.5 kg (not what was lifted)

Treadmill Warm-up (timed)
- 2026-10-03: completed as planned | performance not measured | plan only: 600s (not what was lifted)

Pull-ups
- 2026-09-20: performed 5 reps | migrated
Only 1 measured session: no comparison or trend.

Treadmill (comparison)
- Fewer than two logged runs with a distance and a duration: no comparison possible.
TXT;

        $this->assertSame($expected, $section);
    }

    public function test_exact_entered_sets_are_preserved_not_flattened(): void
    {
        $section = $this->section($this->chat());

        $this->assertStringContainsString('10,9,8 @ 40 kg', $section);
        $this->assertStringContainsString('10,10,9 @ 42.5 kg', $section);
        $this->assertDoesNotMatchRegularExpression('/: 3×10 @ 40 kg \| entered/', $section, 'The target is never listed as the performance.');
    }

    public function test_each_sessions_own_historical_target_is_used_even_after_the_plan_moves_on(): void
    {
        WorkoutPlanExercise::whereHas('workoutPlan', fn ($q) => $q->whereIn('planned_date', ['2026-10-01', '2026-10-05']))
            ->where('exercise_name', 'Bench Press')->update(['planned_reps' => 1, 'planned_weight_kg' => 120]);

        $section = $this->section($this->chat());

        $this->assertStringContainsString('10,9,8 @ 40 kg | target 3×10 @ 40 kg — NOT reached (1 of 3 sets did) | entered', $section);
        $this->assertStringContainsString('10,10,9 @ 42.5 kg | target 3×10 @ 42.5 kg — NOT reached (2 of 3 sets did) | entered', $section);
        $this->assertStringNotContainsString('120', $section);
    }

    // ---- 4-6: evidence and missing data ---------------------------------------------------

    public function test_as_planned_is_never_presented_as_measured(): void
    {
        $prompt = $this->chat();
        $section = $this->section($prompt);

        $line = collect(explode("\n", $section))->first(fn ($l) => str_contains($l, '2026-10-03') && str_contains($l, '22.5'));

        $this->assertSame('- 2026-10-03: completed as planned | performance not measured | plan only: 3×12 @ 22.5 kg (not what was lifted)', $line);
        $this->assertStringNotContainsString('12,12,12', $section, 'The plan\'s sets are never listed as performed.');
        $this->assertStringContainsString('never what was lifted', $prompt, 'The model is told how to read it.');
    }

    public function test_migrated_evidence_stays_distinguishable_from_entered(): void
    {
        $section = $this->section($this->chat());

        $this->assertStringContainsString('5 reps | migrated', $section);
        $this->assertSame(2, substr_count($section, '| entered'));
        $this->assertSame(1, substr_count($section, '| migrated'));
    }

    public function test_missing_data_is_not_invented(): void
    {
        // A freeform exercise with no target, and one logged with no weight.
        $session = WorkoutSession::factory()->for($this->user)->create(['logged_date' => '2026-10-04']);
        $bare = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => 'Push-ups', 'recorded_as' => 'entered']);
        WorkoutSet::factory()->for($bare, 'exercise')->create(['set_number' => 1, 'reps' => 20, 'weight_kg' => null]);

        $section = $this->section($this->chat());

        $this->assertStringContainsString("Push-ups\n- 2026-10-04: performed 20 reps | entered", $section, 'No target and no weight are made up.');
        $this->assertStringNotContainsString('20 reps | target', $section);
        $this->assertStringNotContainsString('20 @', $section);
    }

    public function test_a_user_with_no_history_gets_no_section_and_no_reading_rules(): void
    {
        $prompt = $this->chat(User::factory()->create(['timezone' => 'America/Bogota']));

        $this->assertNull($this->section($prompt));
        $this->assertStringNotContainsString('Recent performance', $prompt);
        $this->assertStringNotContainsString('Reading "Recent performance"', $prompt, 'Rules are only paid for when there is something to read.');
    }

    // ---- 7: isolation -----------------------------------------------------------------------

    public function test_another_users_history_never_reaches_this_users_prompt(): void
    {
        $other = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->enter($other, $this->plan($other, '2026-10-04', [['Bench Press', 5, 5, 100], ['Squat', 5, 5, 140]]), 0, [[5, 100], [5, 100], [5, 100]]);
        $this->enter($other, $this->plan($other, '2026-10-02', [['Squat', 5, 5, 140]]), 0, [[3, 140]]);
        $this->actingAs($other, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 60, 'distance_km' => 10])->assertCreated();

        $mine = $this->chat();
        foreach (['100 kg', '5,5,5', '140', 'Squat', '10 km', '"distance_km":10'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $mine, "Leaked: {$theirs}");
        }

        $theirPrompt = $this->chat($other);
        $theirSection = $this->section($theirPrompt);
        // (The reading rules quote example sets and exercise names, so these are checked in the section, not the whole prompt.)
        foreach (['10,9,8', '10,10,9', '42.5', 'Dumbbell Row', 'Pull-ups'] as $mineData) {
            $this->assertStringNotContainsString($mineData, $theirSection, "Leaked: {$mineData}");
        }
        $this->assertStringNotContainsString('"distance_km":4,', $theirPrompt);
        $this->assertStringContainsString('5,5,5 @ 100 kg | target 5×5 @ 100 kg — NOT reached (3 of 5 sets did) | entered | partial', $theirPrompt);
    }

    // ---- 8-9: treadmill --------------------------------------------------------------------------

    public function test_treadmill_distance_speed_and_pace_reach_the_model_accurately_once(): void
    {
        $prompt = $this->chat();

        preg_match('/## Recent activity[^\n]*\n(\[.*\])\n/', $prompt, $m);
        $run = collect(json_decode($m[1], true))->firstWhere('date', '2026-10-05');

        $this->assertEquals([30, 4, 8, 450, '7:30 /km'], [$run['duration_minutes'], $run['distance_km'], $run['speed_kmh'], $run['pace_seconds_per_km'], $run['pace']]);
        // Today's run already appears in "Today's activity so far" and "Recent activity" (existing
        // behaviour). The performance section must not add a third copy.
        $this->assertSame(2, substr_count($prompt, '"pace_seconds_per_km":450'));
        $this->assertStringNotContainsString('7:30', $this->section($prompt));
        $this->assertStringNotContainsString("Treadmill\n", $this->section($prompt), 'The performance section leaves treadmill to recent_activity.');
    }

    public function test_a_plan_completion_mirror_is_not_presented_as_a_measured_treadmill_session(): void
    {
        $prompt = $this->chat();

        preg_match('/## Recent activity[^\n]*\n(\[.*\])\n/', $prompt, $m);
        $activity = collect(json_decode($m[1], true));

        $mirror = $activity->firstWhere('date', '2026-10-04');
        $logged = $activity->firstWhere('date', '2026-10-05');

        $this->assertSame('treadmill', $mirror['type']);
        $this->assertSame('plan_completion', $mirror['source']);
        $this->assertNull($mirror['distance_km']);
        $this->assertNull($mirror['speed_kmh']);
        $this->assertNull($mirror['pace_seconds_per_km']);
        $this->assertNull($mirror['duration_minutes']);
        $this->assertStringContainsString('not measured', $mirror['note']);
        $this->assertArrayNotHasKey('distance_note', $mirror, 'It is not a run with a missing distance; it is not a run record at all.');

        $this->assertArrayNotHasKey('source', $logged, 'What the user logged themselves is not labelled as a plan completion.');
        $this->assertEquals(4, $logged['distance_km']);
    }

    // ---- 10: mixed plans --------------------------------------------------------------------------

    public function test_a_timed_warm_up_in_a_strength_plan_is_not_presented_as_resistance_performance(): void
    {
        $prompt = $this->chat();
        $section = $this->section($prompt);

        $this->assertStringContainsString('Treadmill Warm-up (timed)', $section);
        $this->assertStringNotContainsString('Dumbbell Row (timed)', $section, 'A lift with reps and a load is not marked timed.');
        $this->assertStringContainsString('"(timed)" = only a duration', $prompt);
        $this->assertStringNotContainsString('Easy Treadmill', $section, 'A treadmill PLAN\'s rows stay out of strength history.');
    }

    // ---- 11: bounds ----------------------------------------------------------------------------------

    public function test_the_context_limits_are_enforced_in_the_prompt(): void
    {
        $names = array_map(fn ($i) => 'Accessory '.chr(65 + $i), range(0, 14));
        $plan = $this->plan($this->user, '2026-09-30', array_map(fn ($n) => [$n, 3, 10, 20], $names));
        foreach (array_keys($names) as $i) {
            $this->enter($this->user, $plan, $i, [[10, 20]]);
        }
        foreach (['2026-09-25', '2026-09-27', '2026-09-29', '2026-10-02'] as $date) {
            $this->enter($this->user, $this->plan($this->user, $date, [['Bench Press', 3, 10, 40]]), 0, [[10, 40]]);
        }

        $section = $this->section($this->chat());

        $blocks = array_values(array_filter(explode("\n\n", $section), fn ($b) => ! str_starts_with($b, 'Treadmill (comparison)')));
        $this->assertCount(8, $blocks, 'At most the default number of exercises.');
        $bench = collect($blocks)->first(fn ($b) => str_starts_with($b, 'Bench Press'));
        $this->assertSame(3, substr_count($bench, "\n- "), 'At most three sessions per exercise, newest ones.');
        $this->assertLessThan(2400, strlen($section), 'Eight exercises with change lines still fit in roughly 600 tokens.');
    }

    // ---- 12: existing behaviour --------------------------------------------------------------------

    public function test_the_conversation_itself_is_unchanged(): void
    {
        $this->fake->queue(new AiResponse(text: 'Your bench is progressing.'));
        $conversationId = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->json('id');
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'How is my bench?'])
            ->assertCreated()->assertJsonPath('content', 'Your bench is progressing.');

        $messages = $this->fake->calls[0]['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'How is my bench?'], array_slice($messages, -1)[0]);
        $this->assertCount(2, $messages, 'System prompt plus the one user message: no extra messages were injected.');
        $this->assertStringContainsString('## Today\'s plan', $messages[0]['content']);
        $this->assertStringContainsString("## This week's plan", $messages[0]['content']);
        $this->assertStringContainsString('## Upcoming schedule', $messages[0]['content']);
    }

    public function test_the_performance_section_sits_inside_the_context_not_in_the_conversation(): void
    {
        $prompt = $this->chat();

        $this->assertLessThan(strpos($prompt, '## Upcoming schedule'), strpos($prompt, '## Recent performance'));
        $this->assertGreaterThan(strpos($prompt, '## Current context'), strpos($prompt, '## Recent performance'));
        $this->assertSame(1, substr_count($prompt, '## Recent performance'));
    }

    // ---- 13: other flows get nothing extra ---------------------------------------------------------

    public function test_weekly_adaptation_does_not_receive_the_performance_context_or_rules(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer on Friday.'])->assertOk();

        $prompt = $this->fake->calls[0]['messages'][0]['content'];

        $this->assertStringContainsString('Weekly plan adaptation mode', $prompt, 'Precondition: this is the weekly flow.');
        $this->assertStringNotContainsString('## Recent performance', $prompt);
        $this->assertStringNotContainsString('Reading "Recent performance"', $prompt);
        $this->assertStringNotContainsString('10,9,8', $prompt);
    }

    public function test_the_dashboard_recommendation_prompt_is_unchanged_by_it(): void
    {
        config(['ai.api_key' => 'test-key']);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $prompt = $this->fake->calls[0]['messages'][0]['content'] ?? '';

        $this->assertNotSame('', $prompt, 'Precondition: the dashboard asked the model.');
        $this->assertStringNotContainsString('## Recent performance', $prompt);
        $this->assertStringNotContainsString('Reading "Recent performance"', $prompt);
    }

    public function test_the_builder_only_adds_performance_when_asked(): void
    {
        $builder = app(FitnessContextBuilder::class);

        $this->assertNull($builder->build($this->user, 'hi')['performance']);
        $this->assertNull($builder->build($this->user, 'hi', includeWeekPlan: false)['performance']);
        $this->assertNotSame('', $builder->build($this->user, 'hi', includePerformance: true)['performance']);
    }

    // ---- 14: permissions ------------------------------------------------------------------------------

    public function test_the_tools_offered_and_their_permissions_are_unchanged(): void
    {
        $this->chat();
        $chatTools = array_column($this->fake->calls[0]['tools'], 'name');

        $this->assertEqualsCanonicalizing(array_map(fn ($t) => $t->name(), app(ToolRegistry::class)->all()), $chatTools, 'Chat gets exactly the registry: nothing added by this phase.');
        foreach ($chatTools as $name) {
            $this->assertDoesNotMatchRegularExpression('/progress|overload|increase|adjust_load/i', $name);
        }

        $this->fake->calls = [];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer on Friday.'])->assertOk();
        $weeklyTools = array_column($this->fake->calls[0]['tools'], 'name');

        $this->assertEqualsCanonicalizing([
            'get_todays_plan', 'get_upcoming_schedule', 'get_recent_activity', 'get_activity_for_date', 'get_activity_history',
            'get_today_activity', 'get_recent_workouts', 'get_weight_history', 'get_weekly_training_summary', 'get_adherence_summary',
            'get_user_profile', 'get_user_preferences', 'propose_weekly_plan_changes',
        ], $weeklyTools, 'The weekly flow\'s allow-list is exactly what it was.');
        $this->assertNotContains('get_exercise_history', $weeklyTools);
    }

    public function test_a_tool_call_from_the_model_is_still_scoped_to_the_authenticated_user(): void
    {
        $other = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->enter($other, $this->plan($other, '2026-10-04', [['Bench Press', 5, 5, 100]]), 0, [[5, 100]]);

        $result = app(ToolRegistry::class)->call('get_exercise_history', ['exercise' => 'Bench Press', 'user_id' => $other->id], $this->user);

        $this->assertSame(['10,9,8 @ 40 kg', '10,10,9 @ 42.5 kg'], array_reverse(array_column($result['sessions'], 'performed_summary')));
    }

    // ---- Phase 4B: interpretation, comparisons and boundaries ----------------------------------------------

    public function test_the_computed_change_between_sessions_reaches_the_model_exactly_once(): void
    {
        $prompt = $this->chat();

        $this->assertSame(1, substr_count($prompt, 'Change 2026-10-01 → 2026-10-05: sets 3→3 | total reps 27→29 (+2) | top load 40→42.5 kg (+2.5) | volume (reps × kg) 1080→1232.5 kg (+152.5)'));
        $this->assertSame(1, substr_count($prompt, 'trend: 2 measured sessions: a comparison, not a trend'), 'Two sessions are labelled a comparison, not a trend.');
        $this->assertSame(1, substr_count($prompt, 'Only 1 measured session: no comparison or trend.'), 'Pull-ups has one measured session; it says so.');
        $this->assertSame(1, substr_count($this->section($prompt), 'NOT reached (1 of 3 sets did)'));
        $this->assertSame(1, substr_count($this->section($prompt), 'NOT reached (2 of 3 sets did)'));
    }

    public function test_the_treadmill_comparison_reaches_the_model_as_computed_facts_and_not_as_raw_logs_twice(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 30, 'distance_km' => 3.5, 'logged_date' => '2026-09-28'])->assertCreated();

        $prompt = $this->chat();
        preg_match('/Treadmill \(comparison\)\n(- [^\n]*)/', $prompt, $m);

        $this->assertSame('- 2026-09-28 → 2026-10-05: 3.5→4.0 km (+0.5) | 30→30 min (0) | 7.0→8.0 km/h (+1.0) | 8:34→7:30/km (64 s/km faster)', $m[1]);
        $this->assertSame(1, substr_count($prompt, 'Treadmill (comparison)'));
        $this->assertStringNotContainsString("\nTreadmill\n", $prompt, 'The raw treadmill lines are not repeated in the performance section.');
    }

    public function test_a_missing_distance_is_reported_as_no_comparison_not_invented(): void
    {
        // Setup leaves one run with a distance (4 km) and a plan-completion mirror; add a run with none.
        $this->actingAs($this->user, 'sanctum')->postJson('/api/activity', ['type' => 'treadmill', 'duration_minutes' => 20, 'logged_date' => '2026-10-02'])->assertCreated();

        $prompt = $this->chat();

        $this->assertStringContainsString('Treadmill (comparison)', $prompt);
        $this->assertStringContainsString('Fewer than two logged runs with a distance and a duration: no comparison possible.', $prompt);
        $this->assertStringNotContainsString('km/h (+', $prompt);
    }

    public function test_the_instructions_teach_evidence_comparability_and_limits_without_granting_new_behaviour(): void
    {
        $prompt = $this->chat();
        $rules = substr($prompt, strpos($prompt, '## Reading "Recent performance"'));

        foreach ([
            'entered` = the user explicitly recorded it',
            'migrated` = recovered from older logs, possibly incomplete',
            '"performed" is what the user actually did. "target" is the plan.',
            'comes ONLY from the words "reached" or "NOT reached',
            'Never write "3 sets of 10" unless every set was 10',
            'never what was lifted',
            'your first words must be "That wasn\'t recorded" (never "Yes")',
            'unknown, not zero',
            'Same exercise name only',
            '"Dumbbell Row" and "Dumbbell Rows" are different exercises',
            'Compare exact sets, not averages',
            'keep target and actual separate',
            'describe each, don\'t call either "better"',
            'One or two sessions are a comparison, never a trend',
            'say "comparison", never "trend"',
            '"Am I getting stronger/fitter?" is not a yes/no question here',
            'Never invent RPE, fatigue, heart rate, recovery',
            'A plan-completion entry is not a measured run',
            'If the user\'s words conflict with the record, say what the record shows',
            'Never prescribe a maximal effort',
            'pushing harder because a target was missed',
            'put any pain or injury the user mentions first',
            'get_exercise_history',
            'get_activity_history',
        ] as $rule) {
            $this->assertStringContainsString($rule, $rules, "Missing rule: {$rule}");
        }

        $this->assertLessThan(4200, strlen($rules), 'The rules stay bounded (about 1,000 tokens at most; a shorter candidate was evaluated and rejected, see PromptVariants).');
    }

    public function test_the_read_only_override_is_scoped_and_resolves_the_conflict_with_the_base_rule(): void
    {
        $prompt = $this->chat();

        $base = strpos($prompt, 'Use create_workout_plan whenever you tell the user what to do next');
        $override = strpos($prompt, '(this overrides "use create_workout_plan whenever you tell the user what to do next")');

        $this->assertNotFalse($base, 'Precondition: the base rule is still there for everything else.');
        $this->assertNotFalse($override);
        $this->assertGreaterThan($base, $override, 'The override comes after the rule it narrows, so it is the later and more specific instruction.');
        $this->assertStringContainsString('unless the user explicitly asks to change their plan', $prompt);
    }

    public function test_answering_performance_questions_changes_nothing_in_the_database(): void
    {
        $tables = ['workout_plans', 'workout_plan_exercises', 'workout_sessions', 'workout_exercises', 'workout_sets', 'activity_logs', 'fitness_memories', 'training_schedules'];
        $snapshot = fn () => array_map(fn ($t) => md5(DB::table($t)->orderBy('id')->get()->toJson()), $tables);
        $before = $snapshot();

        // A model that checks the history with the tool and then answers in text.
        $this->fake->queue(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c1', name: 'get_exercise_history', arguments: ['exercise' => 'Bench Press', 'limit' => 5])]),
            new AiResponse(text: 'Over your last two bench sessions you added 2 reps and 2.5 kg.'),
        );
        $conversationId = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->json('id');
        $this->actingAs($this->user, 'sanctum')->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'Did I improve on Bench Press?'])->assertCreated();

        $this->assertSame($before, $snapshot(), 'Reading and explaining history writes nothing to plans, workouts or activity.');

        // And the tool result the model received carried the computed comparison.
        $toolMessage = collect($this->fake->calls[1]['messages'])->firstWhere('role', 'tool');
        $this->assertSame('get_exercise_history', $toolMessage['name']);
        $this->assertSame(
            'sets 3→3 | total reps 27→29 (+2) | top load 40→42.5 kg (+2.5) | volume (reps × kg) 1080→1232.5 kg (+152.5)',
            $toolMessage['content']['comparison']['summary'],
        );
    }

    public function test_the_history_tool_answers_beyond_the_default_window_and_is_still_bounded(): void
    {
        // Four older sessions, three sets each, a little lighter than the two recorded in setUp.
        foreach (['2026-09-10', '2026-09-12', '2026-09-14', '2026-09-16'] as $i => $date) {
            $this->enter($this->user, $this->plan($this->user, $date, [['Bench Press', 3, 10, 35]]), 0, [[5 + $i, 35], [5 + $i, 35], [5 + $i, 35]]);
        }

        $section = $this->section($this->chat());
        $bench = collect(array_filter(explode("\n\n", $section)))->first(fn ($b) => str_starts_with($b, 'Bench Press'));
        $this->assertSame(3, substr_count($bench, "\n- "), 'The context shows the latest 3 sessions only.');
        $this->assertStringContainsString('trend: volume up in each of the last 2 comparisons (3 measured sessions)', $bench);

        $tool = app(ToolRegistry::class)->call('get_exercise_history', ['exercise' => 'Bench Press', 'limit' => 50], $this->user);
        $this->assertCount(5, $tool['sessions'], 'The tool reaches back further, capped at 5.');
        $this->assertSame(5, $tool['comparison']['measured_sessions']);
        $this->assertTrue($tool['comparison']['sustained_trend_supported']);
    }

    public function test_the_history_tool_comparison_is_scoped_and_does_not_merge_similar_names(): void
    {
        $other = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->enter($other, $this->plan($other, '2026-10-03', [['Bench Press', 3, 5, 100]]), 0, [[5, 100]]);
        $this->enter($this->user, $this->plan($this->user, '2026-09-29', [['Bench Presses', 3, 10, 80]]), 0, [[10, 80]]);

        $mine = app(ToolRegistry::class)->call('get_exercise_history', ['exercise' => 'Bench Press', 'user_id' => $other->id], $this->user);
        $this->assertSame(2, $mine['comparison']['measured_sessions'], 'Only this user\'s two Bench Press sessions; not the other user\'s, not "Bench Presses".');
        $this->assertStringNotContainsString('"weight_kg":100', json_encode($mine));
        $this->assertStringNotContainsString('"weight_kg":80', json_encode($mine), 'Nor the similarly named exercise.');
    }

    public function test_phase_4b_adds_no_tool_and_no_plan_writing_path(): void
    {
        $declared = array_column(app(ToolRegistry::class)->declarations(), 'name');

        $writers = array_values(array_filter($declared, fn ($n) => preg_match('/^(create|log|update|propose|add|remember)_/', $n)));
        sort($writers);

        $this->assertSame(
            ['add_training_schedule_entry', 'create_workout_plan', 'log_activity', 'log_workout', 'propose_weekly_plan_changes', 'remember_preference', 'update_exercise_status', 'update_fitness_profile', 'update_weight'],
            $writers,
            'The set of tools that can write is exactly what it was before this phase.',
        );
        $this->assertContains('get_exercise_history', $declared);
    }

    // ---- cost -----------------------------------------------------------------------------------------

    public function test_a_user_without_history_costs_a_single_query(): void
    {
        $builder = app(PerformanceContext::class);
        $nobody = User::factory()->create(['timezone' => 'America/Bogota']);

        DB::enableQueryLog();
        $text = $builder->render($nobody, includeTreadmill: false);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('', $text);
        $this->assertSame(1, $queries);
    }

    public function test_the_query_count_is_bounded_by_the_limits_not_by_how_much_history_exists(): void
    {
        $names = array_map(fn ($i) => 'Accessory '.chr(65 + $i), range(0, 19));
        $plan = $this->plan($this->user, '2026-09-30', array_map(fn ($n) => [$n, 3, 10, 20], $names));
        foreach (array_keys($names) as $i) {
            $this->enter($this->user, $plan, $i, [[10, 20]]);
        }

        DB::enableQueryLog();
        app(PerformanceContext::class)->render($this->user, includeTreadmill: false);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 discovery + at most (1 history + 3 eager loads + 1 next-planned) for each of the 8 exercises.
        $this->assertLessThanOrEqual(1 + 8 * 5, $queries);
    }
}
