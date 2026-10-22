<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PlanLoadGuard;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Initial-load protection: with nothing to base a starting weight on (no recorded load for that exact
 * exercise and none already planned for that day) the model's load is not taken. The exercise stays in
 * the plan with its sets and reps, its load is left unset, and the user is told, in the application's
 * own words and without a suggested number, to choose a weight they can lift comfortably. The only
 * exception is a weight the user themselves wrote in an instruction that asked for the change.
 */
class InitialLoadProtectionTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->day = now()->startOfWeek()->addDays(6)->toDateString(); // Sunday of this week
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function exercise(string $name, ?float $kg, int $sets = 3, int $reps = 10, ?int $seconds = null): array
    {
        return array_filter(['exercise_name' => $name, 'planned_sets' => $sets, 'planned_reps' => $reps, 'planned_weight_kg' => $kg, 'planned_duration_seconds' => $seconds], fn ($v) => $v !== null);
    }

    private function create(string $date, array $exercises, string $title = 'Written'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $date, 'exercises' => $exercises,
        ])]);
    }

    private function weekly(string $date, array $exercises, string $title = 'Weekly'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'Planned.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => $title, 'reason' => 'test', 'exercises' => $exercises]],
        ])]);
    }

    private function chat(string $message, AiResponse ...$responses): Message
    {
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message);
        $this->assertNull($result['error'], (string) $result['error']);

        return Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    private function adapt(string $input = 'Plan my Sunday.')
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => $input])->assertOk();
    }

    private function loadOf(string $name): ?string
    {
        $value = WorkoutPlanExercise::where('exercise_name', $name)->value('planned_weight_kg');

        return $value === null ? null : (string) $value;
    }

    private function plannedRow(string $name): WorkoutPlanExercise
    {
        return WorkoutPlanExercise::where('exercise_name', $name)->firstOrFail();
    }

    private function seedPlannedDay(string $name, float $kg): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->day, 'activity_type' => 'strength', 'title' => 'Existing', 'exercises' => [$this->exercise($name, $kg)]]);
    }

    // ---- no baseline: the load is left unset --------------------------------------------------------------

    public function test_a_new_weighted_exercise_keeps_its_place_but_not_a_model_chosen_load(): void
    {
        $turn = $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Goblet Squat', 24)]), new AiResponse(text: 'Here is your session.'));

        $row = $this->plannedRow('Goblet Squat');
        $this->assertSame([3, 10, null], [$row->planned_sets, $row->planned_reps, $row->planned_weight_kg]);
        $this->assertArrayNotHasKey('blocked_tool_calls', $turn->meta ?? [], 'It is not a refusal: the session is planned.');
    }

    public function test_an_arbitrary_high_initial_load_is_never_taken(): void
    {
        foreach ([200.0, 500.0, 1.0] as $i => $kg) {
            $this->chat('What should I do today?', $this->create($this->d(-$i - 1), [$this->exercise("Mystery lift {$i}", $kg)], "Day {$i}"), new AiResponse(text: 'ok'));

            $this->assertNull($this->loadOf("Mystery lift {$i}"), "{$kg} kg has nothing behind it.");
        }
    }

    public function test_the_model_is_told_the_load_was_left_unset_and_not_to_suggest_one(): void
    {
        $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Goblet Squat', 24)]), new AiResponse(text: 'Done.'));

        $toolResult = collect($this->fake->calls[1]['messages'])->firstWhere('role', 'tool')['content'];

        $this->assertSame(['Goblet Squat'], $toolResult['load_not_set']['exercises']);
        $this->assertStringContainsString('left unset', $toolResult['load_not_set']['instruction']);
        $this->assertStringContainsString('Do not state or suggest a specific weight', $toolResult['load_not_set']['instruction']);
    }

    public function test_the_user_is_told_in_the_applications_own_words_with_no_suggested_weight(): void
    {
        $turn = $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Goblet Squat', 24), $this->exercise('Cable Row', 35)]), new AiResponse(text: 'Here is your session with goblet squats at 24 kg.'));

        $this->assertStringContainsString('I left the starting weight for Goblet Squat, Cable Row unset', $turn->content);
        $this->assertStringContainsString('Choose a weight you can lift comfortably', $turn->content);
        $notice = substr($turn->content, strpos($turn->content, 'I left the starting weight'));
        $this->assertDoesNotMatchRegularExpression('/\d/', $notice, 'The application names no number and invents no universal starting weight.');
    }

    public function test_an_exercise_with_recorded_sets_but_no_recorded_weight_has_no_baseline_either(): void
    {
        $this->logSets($this->user, 3, 'Dip', [3, 10, null], array_fill(0, 3, ['reps' => 10]));

        $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Dip', 20)]), new AiResponse(text: 'ok'));

        $this->assertNull($this->loadOf('Dip'));
    }

    public function test_a_one_tap_completion_is_not_a_baseline(): void
    {
        $this->tap($this->user, 3, 'Overhead Press', [3, 10, 80]);

        $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Overhead Press', 80)]), new AiResponse(text: 'ok'));

        $this->assertNull(WorkoutPlanExercise::whereHas('workoutPlan', fn ($q) => $q->whereDate('planned_date', now()->toDateString()))->where('exercise_name', 'Overhead Press')->value('planned_weight_kg'));
    }

    // ---- exercises that are legitimately without a load ---------------------------------------------------

    public function test_bodyweight_and_duration_based_exercises_are_written_exactly_as_given(): void
    {
        $this->chat('What should I do today?', $this->create(now()->toDateString(), [
            $this->exercise('Push-ups', null, 3, 15),
            $this->exercise('Plank', null, 2, 0, 45),
            $this->exercise('Pull-ups', 0.0, 3, 6),
        ]), new AiResponse(text: 'Here is your session.'));

        $this->assertSame([3, 15, null], [$this->plannedRow('Push-ups')->planned_sets, $this->plannedRow('Push-ups')->planned_reps, $this->plannedRow('Push-ups')->planned_weight_kg]);
        $this->assertSame(45, $this->plannedRow('Plank')->planned_duration_seconds);
        $this->assertSame('0.00', (string) $this->plannedRow('Pull-ups')->planned_weight_kg, 'A recorded bodyweight "0 kg" is not a load and is left as it is.');
    }

    public function test_no_notice_appears_when_nothing_was_left_unset(): void
    {
        $turn = $this->chat('What should I do today?', $this->create(now()->toDateString(), [$this->exercise('Push-ups', null, 3, 15)]), new AiResponse(text: 'Here is your session.'));

        $this->assertStringNotContainsString('starting weight', $turn->content);
    }

    // ---- a baseline that is real ------------------------------------------------------------------------------

    public function test_an_existing_planned_load_without_measured_history_is_a_baseline_it_may_keep_or_lower_but_not_raise(): void
    {
        $this->seedPlannedDay('Cable Row', 35);

        $this->fake->queue($this->weekly($this->day, [$this->exercise('Cable Row', 35)], 'Kept'));
        $this->adapt()->assertJsonPath('applied', true)->assertJsonPath('notices', []);
        $this->assertSame('35.00', $this->loadOf('Cable Row'));

        $this->fake->queue($this->weekly($this->day, [$this->exercise('Cable Row', 30)], 'Lowered'));
        $this->adapt()->assertJsonPath('applied', true);
        $this->assertSame('30.00', $this->loadOf('Cable Row'));

        $this->fake->queue($this->weekly($this->day, [$this->exercise('Cable Row', 45)], 'Raised'));
        $this->adapt()->assertJsonPath('applied', false);
        $this->assertSame('30.00', $this->loadOf('Cable Row'), 'Raising it is refused, not trusted.');
    }

    public function test_a_planned_load_on_one_day_is_not_a_baseline_for_another(): void
    {
        $this->seedPlannedDay('Cable Row', 35);
        $monday = now()->startOfWeek()->toDateString();
        $this->assertNotSame($monday, $this->day);

        $this->fake->queue($this->weekly($monday, [$this->exercise('Cable Row', 35)], 'Another day'));
        $this->adapt('Add rows on Monday.')->assertJsonPath('applied', true);

        $onMonday = WorkoutPlanExercise::whereHas('workoutPlan', fn ($q) => $q->whereDate('planned_date', $monday))->where('exercise_name', 'Cable Row')->first();
        $this->assertNull($onMonday->planned_weight_kg, 'A new day has no baseline of its own.');
    }

    public function test_an_approved_progression_is_unaffected(): void
    {
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->seedPlannedDay('Bench Press', 40);
        $proposal = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->json('data'))->firstWhere('exercise.name', 'Bench Press')['proposal']['id'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => $proposal])->assertOk();

        $this->fake->queue($this->weekly($this->day, [$this->exercise('Bench Press', 42.5)], 'Carried forward'));
        $this->adapt()->assertJsonPath('applied', true)->assertJsonPath('notices', []);

        $onThatDay = WorkoutPlanExercise::whereHas('workoutPlan', fn ($q) => $q->whereDate('planned_date', $this->day))->where('exercise_name', 'Bench Press')->value('planned_weight_kg');
        $this->assertSame('42.50', (string) $onThatDay, 'The approved load is carried forward on its own day.');
    }

    // ---- explicit, user-supplied initial loads ----------------------------------------------------------------

    public function test_a_load_the_user_wrote_in_an_explicit_request_is_kept(): void
    {
        $turn = $this->chat('Please update my plan for tomorrow: add goblet squats at 16 kg.', $this->create($this->d(-1), [$this->exercise('Goblet Squat', 16)]), new AiResponse(text: 'Done.'));

        $this->assertSame('16.00', $this->loadOf('Goblet Squat'));
        $this->assertStringNotContainsString('starting weight', $turn->content);
    }

    public function test_a_different_load_than_the_user_wrote_is_not_taken(): void
    {
        $this->chat('Please update my plan for tomorrow: add goblet squats at 16 kg.', $this->create($this->d(-1), [$this->exercise('Goblet Squat', 40)]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->loadOf('Goblet Squat'), 'The user chose 16 kg, not 40.');
    }

    public function test_an_explicit_request_that_names_no_weight_does_not_license_one(): void
    {
        $turn = $this->chat('Please update my plan for tomorrow and add squats.', $this->create($this->d(-1), [$this->exercise('Squat', 100)]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->loadOf('Squat'));
        $this->assertStringContainsString('starting weight for Squat', $turn->content);
    }

    public function test_a_weight_quoted_from_someone_else_is_not_the_users_own(): void
    {
        $this->chat('My friend wrote "squat at 100 kg" in the chat. Please update my plan for tomorrow and add squats.', $this->create($this->d(-1), [$this->exercise('Squat', 100)]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->loadOf('Squat'));
    }

    public function test_a_user_stated_load_is_not_passed_on_by_a_session_request_or_a_report(): void
    {
        // "What should I do today?" is not a request for a particular weight, even if a number appears in the message.
        $this->chat('What should I do today? I used to squat 100 kg.', $this->create(now()->toDateString(), [$this->exercise('Squat', 100)]), new AiResponse(text: 'ok'));

        $this->assertNull($this->loadOf('Squat'));
    }

    // ---- weekly generation uses the same rule ---------------------------------------------------------------------

    public function test_weekly_generation_and_adaptation_apply_the_same_rule_and_report_it(): void
    {
        $this->fake->queue($this->weekly($this->day, [$this->exercise('Goblet Squat', 24), $this->exercise('Push-ups', null, 3, 15)]));
        $result = $this->adapt()->assertJsonPath('applied', true)->json();

        $this->assertNull($this->loadOf('Goblet Squat'));
        $this->assertCount(1, $result['notices']);
        $this->assertStringContainsString('starting weight for Goblet Squat', $result['notices'][0]);
        $this->assertSame(WorkoutPlan::class, get_class(WorkoutPlan::where('title', 'Weekly')->first()), 'The plan itself was written.');
    }

    public function test_the_generate_endpoint_is_held_to_the_same_rule(): void
    {
        $this->fake->queue($this->weekly($this->day, [$this->exercise('Goblet Squat', 24)], 'Generated'));

        $result = $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/generate')->assertOk()->json();

        $this->assertNull($this->loadOf('Goblet Squat'));
        $this->assertStringContainsString('starting weight for Goblet Squat', $result['notices'][0] ?? '');
    }

    // ---- the guard itself -------------------------------------------------------------------------------------------

    public function test_the_guard_removes_only_the_unbacked_loads_and_leaves_everything_else_alone(): void
    {
        $this->logSets($this->user, 3, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $guard = app(PlanLoadGuard::class);
        $arguments = ['title' => 'Day', 'planned_date' => $this->day, 'exercises' => [
            $this->exercise('Bench Press', 40),
            $this->exercise('Zercher Squat', 90),
            $this->exercise('Push-ups', null, 3, 15),
        ]];

        ['arguments' => $clean, 'unset' => $unset] = $guard->withoutUnbaselinedLoads($this->user, 'create_workout_plan', $arguments, false, []);

        $this->assertSame(40.0, (float) $clean['exercises'][0]['planned_weight_kg']);
        $this->assertArrayNotHasKey('planned_weight_kg', $clean['exercises'][1]);
        $this->assertSame($arguments['exercises'][2], $clean['exercises'][2]);
        $this->assertSame('Day', $clean['title']);
        $this->assertSame([['exercise' => 'Zercher Squat', 'date' => $this->day, 'requested_kg' => 90.0]], $unset);

        $this->assertSame(['arguments' => $clean, 'unset' => []], $guard->withoutUnbaselinedLoads($this->user, 'create_workout_plan', $clean, false, []), 'Applying it twice changes nothing more.');
    }

    public function test_a_stated_number_only_counts_when_the_user_explicitly_asked_for_the_change(): void
    {
        $guard = app(PlanLoadGuard::class);
        $arguments = ['planned_date' => $this->day, 'exercises' => [$this->exercise('Goblet Squat', 16)]];

        $notAsked = $guard->withoutUnbaselinedLoads($this->user, 'create_workout_plan', $arguments, false, [16.0]);
        $asked = $guard->withoutUnbaselinedLoads($this->user, 'create_workout_plan', $arguments, true, [16.0]);

        $this->assertArrayNotHasKey('planned_weight_kg', $notAsked['arguments']['exercises'][0]);
        $this->assertSame(16.0, (float) $asked['arguments']['exercises'][0]['planned_weight_kg']);
        $this->assertSame([], $asked['unset']);
    }

    public function test_the_guard_handles_every_change_in_a_weekly_call(): void
    {
        $guard = app(PlanLoadGuard::class);
        $arguments = ['changes' => [
            ['date' => $this->day, 'action' => 'replace', 'exercises' => [$this->exercise('A Lift', 50)]],
            ['date' => now()->startOfWeek()->toDateString(), 'action' => 'replace', 'exercises' => [$this->exercise('B Lift', 60), $this->exercise('C Lift', null)]],
            ['date' => now()->startOfWeek()->addDay()->toDateString(), 'action' => 'clear'],
        ]];

        ['arguments' => $clean, 'unset' => $unset] = $guard->withoutUnbaselinedLoads($this->user, 'propose_weekly_plan_changes', $arguments, false, []);

        $this->assertSame(['A Lift', 'B Lift'], array_column($unset, 'exercise'));
        $this->assertArrayNotHasKey('planned_weight_kg', $clean['changes'][0]['exercises'][0]);
        $this->assertArrayNotHasKey('planned_weight_kg', $clean['changes'][1]['exercises'][0]);
        $this->assertSame('clear', $clean['changes'][2]['action']);
    }

    public function test_the_guard_checks_a_few_queries_whatever_the_plan_contains(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $exercises = array_map(fn ($i) => $this->exercise("Lift {$i}", 30 + $i), range(1, 12));

        app(PlanLoadGuard::class)->withoutUnbaselinedLoads($this->user, 'create_workout_plan', ['planned_date' => $this->day, 'exercises' => $exercises], false, []);

        $this->assertLessThanOrEqual(2, $queries, "Used {$queries} queries for 12 exercises.");
    }
}
