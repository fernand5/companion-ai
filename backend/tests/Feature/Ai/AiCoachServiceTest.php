<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

class AiCoachServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_respond_saves_user_message_and_persists_final_assistant_reply(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'Take it easy today — light cardio is plenty.'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'What should I do today?');

        $this->assertSame(Message::ROLE_ASSISTANT, $reply->role);
        $this->assertSame('Take it easy today — light cardio is plenty.', $reply->content);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'What should I do today?',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_ASSISTANT,
        ]);
    }

    public function test_respond_executes_requested_tool_calls_and_feeds_results_back_before_final_answer(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: ['type' => 'steps', 'metadata' => ['steps' => 6200]]),
            ]),
            new AiResponse(text: 'Logged your 6200 steps. Nice work today.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'I did 6200 steps today.');

        $this->assertSame('Logged your 6200 steps. Nice work today.', $reply->content);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'steps']);

        $this->assertNotNull($reply->meta);
        $this->assertSame('log_activity', $reply->meta['tool_calls'][0]['name']);
        $this->assertCount(2, $fake->calls);
    }

    public function test_respond_can_author_a_workout_plan_via_the_create_workout_plan_tool(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'create_workout_plan', arguments: [
                    'activity_type' => 'strength',
                    'title' => 'Upper Body',
                    'decision_summary' => 'Keeping legs fresh for tomorrow.',
                    'reasoning_factors' => ['Football tomorrow'],
                    'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10]],
                ]),
            ]),
            new AiResponse(text: "Here's your plan for today."),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'What should I do today?');

        $this->assertSame("Here's your plan for today.", $reply->content);
        $this->assertDatabaseHas('workout_plans', [
            'user_id' => $user->id,
            'title' => 'Upper Body',
            'reasoning' => 'Keeping legs fresh for tomorrow.',
        ]);
    }

    public function test_respond_can_chain_create_workout_plan_and_update_exercise_status(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'create_workout_plan', arguments: [
                    'activity_type' => 'strength',
                    'title' => 'Quick Session',
                    'decision_summary' => 'Short session today.',
                    'reasoning_factors' => ['Limited time available'],
                    'exercises' => [['exercise_name' => 'Push-ups']],
                ]),
            ]),
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_2', name: 'update_exercise_status', arguments: [
                    'exercise_name' => 'Push-ups',
                    'status' => 'completed',
                ]),
            ]),
            new AiResponse(text: 'Nice, logged that as done.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'Give me a quick workout and mark push-ups done, I already did them.');

        $this->assertSame('Nice, logged that as done.', $reply->content);
        $this->assertDatabaseHas('workout_plan_exercises', ['exercise_name' => 'Push-ups', 'status' => 'completed']);
        $this->assertSame(3, count($fake->calls));
    }

    public function test_respond_can_persist_profile_and_schedule_from_a_single_onboarding_message(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'update_fitness_profile', arguments: [
                    'height_cm' => 168,
                    'primary_goal' => 'Lose fat while maintaining muscle',
                    'equipment' => ['2x 6kg dumbbells', 'treadmill', 'exercise mat'],
                ]),
                new ToolCallRequest(id: 'call_2', name: 'add_training_schedule_entry', arguments: [
                    'activity_type' => 'Soccer', 'day_of_week' => 'Tuesday',
                ]),
                new ToolCallRequest(id: 'call_3', name: 'add_training_schedule_entry', arguments: [
                    'activity_type' => 'Soccer', 'day_of_week' => 'Thursday',
                ]),
                new ToolCallRequest(id: 'call_4', name: 'remember_preference', arguments: [
                    'content' => 'Occasionally does a Cindy-style workout, but not every session.',
                ]),
            ]),
            new AiResponse(text: "Got it — I've updated your profile and added soccer on Tuesdays and Thursdays."),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond(
            $user,
            $conversation,
            'My goal is to lose fat while maintaining muscle. I play soccer Tue/Thu. I have 2x 6kg dumbbells, a treadmill, and a mat.',
        );

        $this->assertSame("Got it — I've updated your profile and added soccer on Tuesdays and Thursdays.", $reply->content);
        $this->assertDatabaseHas('fitness_profiles', ['user_id' => $user->id, 'height_cm' => 168]);
        $this->assertSame(2, $user->trainingSchedules()->count());
        $this->assertCount(4, $reply->meta['tool_calls']);
    }

    public function test_an_empty_closing_response_after_tool_calls_triggers_a_forced_summary_instead_of_a_blank_reply(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'update_fitness_profile', arguments: ['primary_goal' => 'Fat loss']),
            ]),
            // Model finished tool calls but returned an empty string, not null —
            // this must NOT be accepted as the final answer.
            new AiResponse(text: ''),
            new AiResponse(text: "Done — I've updated your goal to fat loss."),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'My goal is fat loss.');

        $this->assertSame("Done — I've updated your goal to fat loss.", $reply->content);
        $this->assertNotSame('', $reply->content);
    }

    public function test_conversation_history_persists_across_multiple_turns(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'first reply'), new AiResponse(text: 'second reply'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        app(AiCoachService::class)->respond($user, $conversation, 'first message');
        app(AiCoachService::class)->respond($user, $conversation, 'second message');

        $this->assertSame(4, $conversation->messages()->count());

        $secondCallMessages = $fake->calls[1]['messages'];
        $userTurns = collect($secondCallMessages)->where('role', 'user')->pluck('content');
        $this->assertTrue($userTurns->contains('first message'));
        $this->assertTrue($userTurns->contains('second message'));
    }

    /**
     * Root-cause regression. Conversation::messages() carries a baked-in
     * ->orderBy('created_at') ascending (correct for the frontend's own
     * GET .../messages display). Chaining ->orderByDesc('created_at') on top
     * of that in generateReply() does NOT override it — Eloquent appends
     * order clauses instead of replacing them — so with realistically
     * distinct timestamps (never tied, unlike rapid-fire test execution) the
     * query actually returned OLDEST-first, and the .reverse() that followed
     * flipped that into NEWEST-first: the brand-new current message ended up
     * second in the array (right after the system prompt) with older turns
     * placed AFTER it, ending on whichever message happened to be oldest.
     * Confirmed against real production data before the fix. Every message
     * here is pinned to a distinct minute via Carbon::setTestNow() so this
     * reproduces that exact (non-tied) condition — a fast run where every
     * message ties to the same second would mask the bug entirely, which is
     * exactly what let an earlier, insufficient version of this test pass
     * against broken code.
     */
    public function test_the_current_user_message_is_always_the_last_message_sent_to_the_provider(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'first reply'), new AiResponse(text: 'second reply'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));
        app(AiCoachService::class)->respond($user, $conversation, 'first message');

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));
        app(AiCoachService::class)->respond($user, $conversation, 'second message');

        $lastMessageOfSecondCall = collect($fake->calls[1]['messages'])->last();
        $this->assertSame('user', $lastMessageOfSecondCall['role']);
        $this->assertSame('second message', $lastMessageOfSecondCall['content']);
    }

    public function test_history_is_sent_in_strict_chronological_order(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: 'reply one'),
            new AiResponse(text: 'reply two'),
            new AiResponse(text: 'reply three'),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));
        app(AiCoachService::class)->respond($user, $conversation, 'message one');

        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));
        app(AiCoachService::class)->respond($user, $conversation, 'message two');

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00'));
        app(AiCoachService::class)->respond($user, $conversation, 'message three');

        $thirdCallMessages = collect($fake->calls[2]['messages']);
        $conversationTurns = $thirdCallMessages->skip(1)->values(); // drop the leading system message

        $this->assertSame([
            ['role' => 'user', 'content' => 'message one'],
            ['role' => 'assistant', 'content' => 'reply one'],
            ['role' => 'user', 'content' => 'message two'],
            ['role' => 'assistant', 'content' => 'reply two'],
            ['role' => 'user', 'content' => 'message three'],
        ], $conversationTurns->all());
    }

    public function test_two_conversations_for_the_same_user_never_leak_history_into_each_other(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'reply in A'), new AiResponse(text: 'reply in B'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversationA = $user->conversations()->create();
        $conversationB = $user->conversations()->create();

        app(AiCoachService::class)->respond($user, $conversationA, 'message only in A');
        app(AiCoachService::class)->respond($user, $conversationB, 'message only in B');

        $bCallContents = collect($fake->calls[1]['messages'])->pluck('content');
        $this->assertTrue($bCallContents->contains('message only in B'));
        $this->assertFalse($bCallContents->contains('message only in A'));
    }

    /**
     * The exact reported failure mode, reproduced structurally: a prior
     * assistant turn said a match was "tonight"; a WorkoutPlan row for that
     * same past date still carries that wording (title/reasoning are written
     * once and never re-labeled). The system prompt sent to the model for
     * the NEXT turn must mark that entry "past" and warn that its wording may
     * be stale — the model must not be left to infer this from prose alone
     * anchored against its own prior turn.
     */
    public function test_a_past_dated_plan_still_worded_tonight_is_marked_past_in_the_prompt_sent_to_the_model(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();
        $yesterday = Carbon::parse('2026-09-15')->toDateString();

        Carbon::setTestNow(Carbon::parse('2026-09-15 20:39:00'));
        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $yesterday,
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
            'reasoning' => "Focus on tonight's soccer match and keep the rest of the day light.",
        ]);
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => 'hello']);
        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'Hello! Since you have your soccer match scheduled for tonight, I recommend keeping today light.',
        ]);

        // The reported bug happened the NEXT day — this is the case that
        // matters: a genuinely new "now" relative to the stored history.
        Carbon::setTestNow(Carbon::parse('2026-09-16 16:22:00'));

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                'type' => 'sport',
                'logged_date' => $yesterday,
                'metadata' => ['steps' => 9000],
            ]),
        ]), new AiResponse(text: 'Logged your soccer session from yesterday.'));
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond(
            $user,
            $conversation,
            'Yesterday I did the soccer session, walking ~6k steps, so in total yesterday I did 9k steps',
        );

        $firstCallMessages = collect($fake->calls[0]['messages']);
        $systemMessage = $firstCallMessages->firstWhere('role', 'system');

        $this->assertStringContainsString('"relative_to_today":"past"', str_replace(' ', '', $systemMessage['content']));
        $this->assertStringContainsString('historical record only', $systemMessage['content']);

        // The critical assertion: the new message must be LAST overall, not
        // just the last user-role entry — this is exactly what the reversed
        // history bug violated (it landed second, right after the system
        // prompt, with the "hello"/"soccer tonight" turns placed after it).
        $lastMessageOverall = $firstCallMessages->last();
        $this->assertSame(
            'Yesterday I did the soccer session, walking ~6k steps, so in total yesterday I did 9k steps',
            $lastMessageOverall['content'],
        );
    }

    public function test_a_failed_provider_call_does_not_leave_an_orphaned_user_message(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        try {
            app(AiCoachService::class)->respond($user, $conversation, 'What should I do today?');
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException) {
            // expected
        }

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_debug_snapshot_never_contains_the_ai_api_key(): void
    {
        config(['ai.debug' => true, 'ai.api_key' => 'super-secret-key']);

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'ok'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        app(AiCoachService::class)->respond($user, $conversation, 'hello');

        $logPath = storage_path('logs/ai-debug-'.now()->format('Y-m-d').'.log');
        if (is_file($logPath)) {
            $this->assertStringNotContainsString('super-secret-key', file_get_contents($logPath));
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_dashboard_recommendation_is_cached_for_the_rest_of_the_day(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'Take it easy today.'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $service = app(AiCoachService::class);

        $first = $service->dashboardRecommendation($user);
        $second = $service->dashboardRecommendation($user);

        $this->assertSame('Take it easy today.', $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $fake->calls);
    }

    public function test_dashboard_recommendation_is_regenerated_after_the_users_data_changes(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: 'Rest today, soccer is tonight.'),
            new AiResponse(text: 'Great 9k step day — keep it light.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $service = app(AiCoachService::class);

        $before = $service->dashboardRecommendation($user);

        // Logging new activity (e.g. via chat) must not leave the dashboard
        // serving an answer that was written before it existed.
        app(ActivityService::class)->log($user, ['type' => 'steps', 'metadata' => ['steps' => 9000]]);

        $after = $service->dashboardRecommendation($user);

        $this->assertSame('Rest today, soccer is tonight.', $before);
        $this->assertSame('Great 9k step day — keep it light.', $after);
        $this->assertCount(2, $fake->calls);
    }

    public function test_dashboard_recommendation_is_not_shared_across_users(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: 'Recommendation for user one.'),
            new AiResponse(text: 'Recommendation for user two.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        $userOne = User::factory()->create();
        $userTwo = User::factory()->create();
        $service = app(AiCoachService::class);

        $this->assertSame('Recommendation for user one.', $service->dashboardRecommendation($userOne));
        $this->assertSame('Recommendation for user two.', $service->dashboardRecommendation($userTwo));
        $this->assertCount(2, $fake->calls);
    }

    public function test_dashboard_recommendation_tells_the_model_no_tools_are_available_this_call(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'Take it easy today.'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        app(AiCoachService::class)->dashboardRecommendation($user);

        $systemMessage = collect($fake->calls[0]['messages'])->firstWhere('role', 'system');
        $this->assertStringContainsString('no tools are available', $systemMessage['content']);
        $this->assertSame([], $fake->calls[0]['tools']);
    }
}
