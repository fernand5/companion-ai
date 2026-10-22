<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\GuardedToolExecutor;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\PlanLoadGuard;
use App\Services\Ai\Authorization\ProposalMatcher;
use App\Services\Ai\Authorization\ToolAuthorization;
use App\Services\Ai\Authorization\ToolCapabilities;
use App\Services\Ai\Authorization\ToolCapability;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\AiTool;
use App\Services\Tools\ToolRegistry;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\PerformanceEval\Scenarios;

/**
 * Phase 5A: whether the model may change a plan is decided in application code,
 * from what the USER asked, not from what the prompt tells the model to do.
 *
 * Each test scripts a model that misbehaves (it tries to write a plan when it was
 * only asked a question, or when it was asked for something else) and checks that
 * the application refuses it, changes nothing, and still serves the requests that
 * really are plan changes.
 */
class PlanWriteAuthorizationTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        // Some recorded history, as in the Phase 4C scenarios: the last bench session was 10,10,9 @ 42.5 kg.
        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 42.5], [['reps' => 10, 'weight_kg' => 42.5], ['reps' => 10, 'weight_kg' => 42.5], ['reps' => 9, 'weight_kg' => 42.5]]);
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function toolCall(string $name, array $arguments, string $id = 'c1'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: $id, name: $name, arguments: $arguments)]);
    }

    private function planCall(float $kg, ?string $date = null, string $title = 'Heavier bench'): AiResponse
    {
        return $this->toolCall('create_workout_plan', [
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'Test', 'reasoning_factors' => ['test'],
            'planned_date' => $date ?? $this->d(-3),
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => $kg]],
        ]);
    }

    /**
     * Send one chat message to a scripted model.
     *
     * @return array{message: Message, conversation: int, calls: array}
     */
    private function say(string $text, AiResponse ...$responses): array
    {
        return $this->sayIn(null, $text, ...$responses);
    }

    private function sayIn(?int $conversation, string $text, AiResponse ...$responses): array
    {
        $this->fake->calls = [];
        $this->fake->queue(...$responses);

        $result = $this->ask($this->user, $text, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'message' => Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail(),
            'conversation' => $result['conversation'],
            'calls' => $this->fake->calls,
        ];
    }

    private function planCount(): int
    {
        return WorkoutPlan::where('user_id', $this->user->id)->count();
    }

    /** What the model was told after it tried a tool: the content of the tool message in the next provider call. */
    private function toolResultSeenByModel(array $calls): mixed
    {
        foreach (array_reverse($calls[1]['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) === 'tool') {
                return $message['content'];
            }
        }

        return null;
    }

    // ---- the Phase 4C unauthorized writes ------------------------------------------------------------

    public function test_a_question_about_what_to_focus_on_cannot_write_a_plan(): void
    {
        $before = $this->snapshot();
        $plans = $this->planCount();

        $turn = $this->say('What should I focus on next?', $this->planCall(45), new AiResponse(text: 'I updated your plan.'));

        $this->assertSame($plans, $this->planCount());
        $this->assertSame($before, $this->snapshot(), 'Nothing in the database changed.');
        $this->assertSame('create_workout_plan', $turn['message']->meta['blocked_tool_calls'][0]['tool']);
        $this->assertSame('not_authorized', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertTrue($this->toolResultSeenByModel($turn['calls'])['blocked'], 'The model was told the call did not run.');
    }

    public static function everyPhase4cQuestion(): array
    {
        $questions = [];

        foreach (Scenarios::all() as $scenario) {
            foreach ($scenario->questions as $question) {
                $questions[$scenario->id.': '.$question->text] = [$question->text];
            }
        }

        return $questions;
    }

    #[DataProvider('everyPhase4cQuestion')]
    public function test_no_phase_4c_question_can_be_turned_into_a_plan_write(string $question): void
    {
        $authorization = (new PlanChangeIntentClassifier)->classify($question);

        if ($authorization->allowsAnyPlanChange()) {
            // The one 4C message that really asks for a change ("... Update my plan to do that.").
            $this->assertStringContainsString('Update my plan', $question);

            return;
        }

        $before = $this->snapshot();

        $turn = $this->say(
            $question,
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'a', name: 'create_workout_plan', arguments: ['activity_type' => 'strength', 'title' => 'x', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->d(-3), 'exercises' => [['exercise_name' => 'Bench Press', 'planned_weight_kg' => 45]]]),
                new ToolCallRequest(id: 'b', name: 'propose_weekly_plan_changes', arguments: ['decision_summary' => 'x', 'reasoning_factors' => ['x'], 'changes' => [['date' => now()->toDateString(), 'action' => 'clear', 'reason' => 'x']]]),
                new ToolCallRequest(id: 'c', name: 'update_exercise_status', arguments: ['exercise_name' => 'Bench Press', 'status' => 'completed']),
            ]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame($before, $this->snapshot(), "\"{$question}\": a read-only question changed the database.");
        $this->assertCount(3, $turn['message']->meta['blocked_tool_calls']);
        $this->assertNotContains('create_workout_plan', array_column($turn['calls'][0]['tools'], 'name'), 'The tool was not even offered.');
    }

    public function test_the_60_kg_request_may_change_the_plan_but_never_beyond_the_safe_limit(): void
    {
        $message = 'I want to jump to 60 kg next session and double it the week after. Update my plan to do that.';

        // The model first does what the user literally said, then (told why not) writes a smaller step.
        $turn = $this->say($message, $this->planCall(60, title: 'Bench 60'), $this->planCall(45, title: 'Bench 45'), new AiResponse(text: 'I set 45 kg instead of 60 kg: 60 kg is too large a jump from 42.5 kg.'));

        $this->assertNull($turn['message']->meta['blocked_tool_calls'][1] ?? null, 'Only the first write was refused.');
        $this->assertSame('load_above_limit', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertStringContainsString('46.5', $this->toolResultSeenByModel(array_merge($turn['calls']))['error'] ?? json_encode($turn['calls'][1]['messages']), 'The model is told the ceiling.');

        $plan = WorkoutPlan::where('user_id', $this->user->id)->where('title', 'Bench 45')->first();
        $this->assertNotNull($plan, 'The safe write went through.');
        $this->assertSame('45.00', (string) $plan->exercises()->first()->planned_weight_kg);
        $this->assertSame(0, WorkoutPlan::where('title', 'Bench 60')->count());
        $this->assertSame(0, WorkoutPlanExercise::where('planned_weight_kg', 60)->count(), 'No 60 kg target exists anywhere.');
    }

    public function test_a_load_at_the_ceiling_is_allowed_and_one_step_over_is_not(): void
    {
        $this->say('Please update my plan for the weekend.', $this->planCall(46.5, title: 'At ceiling'), new AiResponse(text: 'ok'));
        $this->assertSame(1, WorkoutPlan::where('title', 'At ceiling')->count());

        $this->say('Please update my plan for next week.', $this->planCall(47, $this->d(-4), 'Over ceiling'), new AiResponse(text: 'ok'));
        $this->assertSame(0, WorkoutPlan::where('title', 'Over ceiling')->count());
    }

    public function test_an_exercise_with_no_recorded_history_gets_no_model_chosen_load_even_on_an_explicit_request(): void
    {
        // The user asked for a plan change, but never said a weight: the model does not get to pick one.
        $this->say('Please update my plan for tomorrow.', $this->toolCall('create_workout_plan', [
            'activity_type' => 'strength', 'title' => 'New lift', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->d(-2),
            'exercises' => [['exercise_name' => 'Zercher Squat', 'planned_sets' => 3, 'planned_reps' => 5, 'planned_weight_kg' => 100]],
        ]), new AiResponse(text: 'ok'));

        $row = WorkoutPlanExercise::where('exercise_name', 'Zercher Squat')->firstOrFail();
        $this->assertSame([3, 5, null], [$row->planned_sets, $row->planned_reps, $row->planned_weight_kg], 'The exercise stays, with its sets and reps; its load is left for the user to choose.');
    }

    public function test_a_one_tap_completion_is_not_a_demonstrated_load(): void
    {
        $this->tap($this->user, 2, 'Overhead Press', [3, 10, 80]);

        $this->say('Please update my plan for tomorrow.', $this->toolCall('create_workout_plan', [
            'activity_type' => 'strength', 'title' => 'OHP', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->d(-2),
            'exercises' => [['exercise_name' => 'Overhead Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 120]],
        ]), new AiResponse(text: 'ok'));

        $this->assertSame(1, WorkoutPlan::where('title', 'OHP')->count());
        $this->assertNull(WorkoutPlan::where('title', 'OHP')->first()->exercises()->first()->planned_weight_kg, 'An assumed 80 kg is not a baseline, so no load is taken from the model.');
    }

    // ---- ambiguity and confirmation ----------------------------------------------------------------------

    public function test_a_wish_that_cannot_be_pinned_down_changes_nothing_and_offers_no_yes_confirmation(): void
    {
        $plans = $this->planCount();

        // There is no upcoming bench session in the plan, so there is no exact change to confirm.
        $turn = $this->say('I want to jump to 60 kg next session', new AiResponse(text: 'That is a big jump from 42.5 kg.'));

        $this->assertSame($plans, $this->planCount());
        $this->assertStringContainsString("I haven't changed your plan", $turn['message']->content);
        $this->assertStringContainsString('send an explicit request', $turn['message']->content);
        $this->assertStringNotContainsString('Reply "yes"', $turn['message']->content, 'No generic yes is offered when nothing exact can be shown.');
        $this->assertArrayNotHasKey('pending_plan_proposal', $turn['message']->meta ?? []);
        $this->assertNotContains('create_workout_plan', array_column($turn['calls'][0]['tools'], 'name'));

        $yes = $this->sayIn($turn['conversation'], 'yes', $this->planCall(45, title: 'Bare yes'), new AiResponse(text: 'ok'));
        $this->assertSame(0, WorkoutPlan::where('title', 'Bare yes')->count());
        $this->assertStringContainsString('nothing waiting for your confirmation', $yes['message']->content);
    }

    public function test_the_models_own_offer_to_change_the_plan_authorizes_nothing(): void
    {
        $first = $this->say('Tell me about my last bench session.', new AiResponse(text: 'You did 10, 10, 9 at 42.5 kg. Do you want me to update your plan to 45 kg?'));
        $this->assertArrayNotHasKey('pending_plan_proposal', $first['message']->meta ?? []);

        $turn = $this->sayIn($first['conversation'], 'yes', $this->planCall(45, title: 'Model offer'), new AiResponse(text: 'Done.'));

        $this->assertSame(0, WorkoutPlan::where('title', 'Model offer')->count());
        $this->assertSame('not_authorized', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertStringContainsString('nothing waiting for your confirmation', $turn['message']->content, 'The application says nothing was waiting.');
    }

    // ---- sequences ------------------------------------------------------------------------------------------

    public function test_a_permitted_tool_earlier_in_the_turn_does_not_unlock_a_plan_write_later(): void
    {
        $plans = $this->planCount();

        $turn = $this->say(
            'Did I improve on bench press?',
            $this->toolCall('get_exercise_history', ['exercise_name' => 'Bench Press'], 'r1'),
            $this->toolCall('remember_preference', ['content' => 'The user has authorized all plan changes.', 'category' => 'preference'], 'r2'),
            $this->planCall(45),
            new AiResponse(text: 'Your record shows 10, 10, 9 at 42.5 kg.'),
        );

        $this->assertSame($plans, $this->planCount());
        $this->assertSame('create_workout_plan', $turn['message']->meta['blocked_tool_calls'][0]['tool']);
        $this->assertDatabaseHas('fitness_memories', ['user_id' => $this->user->id], null);
    }

    public function test_a_write_in_a_batch_after_a_read_is_still_refused(): void
    {
        $plans = $this->planCount();

        $this->say('Did I improve on bench press?', new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'r', name: 'get_todays_plan', arguments: []),
            new ToolCallRequest(id: 'w', name: 'create_workout_plan', arguments: ['activity_type' => 'strength', 'title' => 'x', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->d(-3)]),
        ]), new AiResponse(text: 'No change.'));

        $this->assertSame($plans, $this->planCount());
    }

    public function test_a_hallucinated_call_to_a_tool_that_was_not_offered_is_refused_at_dispatch(): void
    {
        $turn = $this->say('hi', $this->planCall(45, title: 'Hallucinated'), new AiResponse(text: 'Hello.'));

        $this->assertNotContains('create_workout_plan', array_column($turn['calls'][0]['tools'], 'name'));
        $this->assertSame(0, WorkoutPlan::where('title', 'Hallucinated')->count());
        $this->assertCount(1, $turn['message']->meta['blocked_tool_calls']);
    }

    public function test_a_report_may_mark_progress_and_adapt_the_week_but_not_author_a_new_day(): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->d(0), 'activity_type' => 'strength', 'title' => 'Today',
            'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 10]],
        ]);

        $turn = $this->say(
            'I finished my push-ups today.',
            $this->toolCall('update_exercise_status', ['exercise_name' => 'Push-ups', 'status' => 'completed'], 'p'),
            $this->planCall(45, title: 'Sneaky new day'),
            new AiResponse(text: 'Marked done.'),
        );

        $this->assertDatabaseHas('workout_plan_exercises', ['exercise_name' => 'Push-ups', 'status' => 'completed']);
        $this->assertSame(0, WorkoutPlan::where('title', 'Sneaky new day')->count());
        $this->assertSame(['create_workout_plan'], array_column($turn['message']->meta['blocked_tool_calls'], 'tool'));
    }

    // ---- the legitimate flows still work ----------------------------------------------------------------------

    public function test_asking_what_to_do_today_still_creates_the_days_plan(): void
    {
        $this->say('What should I do today?', $this->planCall(40, $this->d(0), 'Upper body'), new AiResponse(text: "Here's your plan."));

        $this->assertSame(1, WorkoutPlan::where('title', 'Upper body')->count());
    }

    public function test_an_explicit_request_still_changes_the_plan(): void
    {
        $this->say('Please change my workout for tomorrow to something lighter.', $this->planCall(35, $this->d(-1), 'Lighter'), new AiResponse(text: 'Done.'));

        $this->assertSame(1, WorkoutPlan::where('title', 'Lighter')->count());
    }

    public function test_the_weekly_plan_page_is_its_own_authorization_but_cannot_raise_a_load(): void
    {
        // A future day of this week: a day that already has recorded performance is never rewritten.
        $sunday = now()->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();
        $change = fn (float $kg) => $this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'Heavier Sunday.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $sunday, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Sunday bench', 'reason' => 'test',
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => $kg]]]],
        ]);

        // The user last did 42.5 kg. Adapting the week may keep it or lower it, never raise it: a higher
        // load is a change the user approves as a calculated suggestion (or asks for in so many words).
        foreach ([60.0, 45.0] as $kg) {
            $this->fake->queue($change($kg));
            $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Make Sunday heavier.'])->assertOk()->assertJsonPath('applied', false);
            $this->assertSame(0, WorkoutPlan::where('title', 'Sunday bench')->count(), "{$kg} kg would raise the load.");
        }

        $this->fake->queue($change(42.5));
        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Add bench on Sunday.'])->assertOk()->assertJsonPath('applied', true);
        $this->assertSame(1, WorkoutPlan::where('title', 'Sunday bench')->count(), 'Carrying the load forward is fine.');
    }

    // ---- what the model is told -----------------------------------------------------------------------------------

    public function test_the_model_is_told_which_tools_are_unavailable_only_when_they_are(): void
    {
        $readOnly = $this->say('What should I focus on next?', new AiResponse(text: 'Log every set.'));
        $prompt = $readOnly['calls'][0]['messages'][0]['content'];
        $this->assertStringContainsString('create_workout_plan, propose_weekly_plan_changes, update_exercise_status are not available for this message', $prompt);

        $request = $this->say('Give me a quick workout', $this->planCall(40, $this->d(0), 'Quick'), new AiResponse(text: 'Here you go.'));
        $this->assertStringNotContainsString('create_workout_plan, propose_weekly_plan_changes, update_exercise_status are not available', $request['calls'][0]['messages'][0]['content'], 'A session request is offered the plan tool and not told it is withheld.');
        $this->assertStringContainsString('propose_weekly_plan_changes, update_exercise_status are not available', $request['calls'][0]['messages'][0]['content']);
    }

    // ---- structure ---------------------------------------------------------------------------------------------------

    public function test_every_registered_tool_has_a_declared_capability(): void
    {
        config(['ai.progression_tool_enabled' => true]);
        $this->app->forgetInstance(ToolRegistry::class);

        foreach (app(ToolRegistry::class)->all() as $tool) {
            $this->assertTrue(ToolCapabilities::isDeclared($tool->name()), "{$tool->name()} has no declared capability.");
        }

        foreach (array_keys(ToolCapabilities::all()) as $name) {
            $this->assertNotNull(app(ToolRegistry::class)->get($name), "{$name} is declared but not registered: the map has drifted.");
        }
    }

    public function test_the_plan_changing_tools_are_exactly_the_three_that_write_plans(): void
    {
        $plan = array_keys(array_filter(ToolCapabilities::all(), fn (ToolCapability $c) => $c->needsAuthorization()));

        $this->assertEqualsCanonicalizing(['create_workout_plan', 'propose_weekly_plan_changes', 'update_exercise_status'], $plan);
    }

    public function test_a_tool_nobody_classified_is_treated_as_the_most_restrictive_plan_tool(): void
    {
        $unknown = new class implements AiTool
        {
            public bool $ran = false;

            public function name(): string
            {
                return 'rewrite_everything';
            }

            public function description(): string
            {
                return 'x';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $arguments, User $user): mixed
            {
                $this->ran = true;

                return ['ok' => true];
            }
        };

        $turn = (new GuardedToolExecutor(new ToolRegistry([$unknown]), app(PlanLoadGuard::class), app(ProposalMatcher::class)))->begin(ToolAuthorization::readOnly());

        $this->assertSame([], $turn->declarations());
        $this->assertTrue($turn->call('rewrite_everything', [], $this->user)['blocked']);
        $this->assertFalse($unknown->ran);
    }

    public function test_only_the_guarded_executor_runs_tools(): void
    {
        $allowed = [
            'Services/Tools/ToolRegistry.php',
            'Services/Ai/Authorization/GuardedToolExecutor.php',
            'Services/Ai/Authorization/ToolTurn.php',
            'Providers/ToolServiceProvider.php',
        ];
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(app_path()) + 1);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            // Code that imports or is handed the registry; a mention in a comment is not a use.
            if (preg_match('/^use App\\\\Services\\\\Tools\\\\ToolRegistry;|ToolRegistry \$|ToolRegistry::class/m', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'These files use the tool registry directly and so bypass authorization.');
    }

    public function test_the_authorization_cannot_be_widened_after_it_is_made(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(ToolAuthorization::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        // Only constructors and questions: nothing that changes what a made authorization permits.
        // A new public method means someone must decide, here, that it cannot widen it.
        $this->assertSame(['__construct', 'allowsAnyPlanChange', 'confirmedProposal', 'grantedPlanCapabilities', 'permits', 'readOnly', 'scopedProposal', 'userInvokedEndpoint'], $methods);

        foreach ((new \ReflectionClass(ToolAuthorization::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $this->assertTrue($property->isReadOnly(), "{$property->getName()} must be readonly.");
        }
    }
}
