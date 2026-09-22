<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\GeminiProvider;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * End-to-end (HTTP -> controller -> persistence -> history -> context ->
 * prompt -> provider) guards for the "assistant answers the previous message
 * instead of the current one" failure and the date handling around it.
 *
 * Clock: Wednesday 2026-09-16, 19:30 in America/Bogota (Thursday 00:30 UTC).
 */
class StaleResponseRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', 'UTC'));
        $this->user = User::factory()->create(['timezone' => 'America/Bogota']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(AiResponse ...$responses): FakeAiProvider
    {
        $fake = (new FakeAiProvider)->queue(...$responses);
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    private function conversationId(): int
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->json('id');
    }

    private function send(int $conversationId, string $content)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => $content]);
    }

    private function systemPromptOf(array $call): string
    {
        return $call['messages'][0]['content'];
    }

    public function test_new_user_input_that_contradicts_a_previous_assistant_assumption_reaches_the_model_as_the_latest_message(): void
    {
        $fake = $this->fake(new AiResponse(text: 'You have soccer tonight, so keep it light.'), new AiResponse(text: 'Got it — soccer was yesterday.'));
        $id = $this->conversationId();

        $this->send($id, 'What should I do today?')->assertCreated();
        $this->send($id, 'I already played soccer yesterday.')->assertCreated()
            ->assertJsonPath('content', 'Got it — soccer was yesterday.');

        $secondCall = $fake->calls[1]['messages'];
        $conversationTurns = array_values(array_filter($secondCall, fn ($m) => $m['role'] !== 'system'));

        $this->assertSame(
            [['user', 'What should I do today?'], ['assistant', 'You have soccer tonight, so keep it light.'], ['user', 'I already played soccer yesterday.']],
            array_map(fn ($m) => [$m['role'], $m['content']], $conversationTurns),
            'History must be chronological and end on the CURRENT user message.',
        );

        // The model is handed today's real local date, the weekday, and a
        // resolved yesterday, so "yesterday" can't be misread as "tonight".
        $prompt = $this->systemPromptOf($fake->calls[1]);
        $this->assertStringContainsString("Today's date: 2026-09-16 (Wednesday), user's timezone: America/Bogota", $prompt);
        $this->assertStringContainsString('Tuesday 2026-09-15 (yesterday)', $prompt);
        $this->assertStringContainsString('Wednesday 2026-09-16 (today)', $prompt);
        $this->assertStringContainsString('Thursday 2026-09-17 (tomorrow)', $prompt);
    }

    public function test_two_consecutive_messages_each_get_their_own_reply_in_order(): void
    {
        $this->fake(new AiResponse(text: 'Reply A'), new AiResponse(text: 'Reply B'));
        $id = $this->conversationId();

        $a = $this->send($id, 'Message A')->assertCreated()->json();
        $b = $this->send($id, 'Message B')->assertCreated()->json();

        $this->assertSame('Reply A', $a['content']);
        $this->assertSame('Reply B', $b['content']);
        $this->assertGreaterThan($a['id'], $b['id']);

        $stored = Message::where('conversation_id', $id)->orderBy('id')->get()->map(fn ($m) => [$m->role, $m->content])->all();
        $this->assertSame([['user', 'Message A'], ['assistant', 'Reply A'], ['user', 'Message B'], ['assistant', 'Reply B']], $stored);
    }

    public function test_a_second_request_while_a_turn_is_in_flight_is_rejected_without_touching_history_or_the_model(): void
    {
        $fake = $this->fake(new AiResponse(text: 'Should never be used.'));
        $id = $this->conversationId();

        // Simulates the first turn still being processed.
        $lock = Cache::lock("conversation-turn:{$id}", 210);
        $this->assertTrue($lock->get());

        $this->send($id, 'Rapid second message')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Your coach is still working on your previous message — please wait for the reply first.');

        $this->assertCount(0, $fake->calls);
        $this->assertSame(0, Message::where('conversation_id', $id)->count());

        $lock->release();

        $this->send($id, 'Now it can go through')->assertCreated();
        $this->assertCount(2, Message::where('conversation_id', $id)->get());
    }

    public function test_a_different_conversation_is_not_blocked_by_an_in_flight_turn(): void
    {
        $this->fake(new AiResponse(text: 'Fine.'));
        $busy = $this->conversationId();
        $other = $this->conversationId();

        Cache::lock("conversation-turn:{$busy}", 210)->get();

        $this->send($other, 'Hello')->assertCreated();
    }

    public function test_provider_failure_returns_503_removes_the_orphaned_message_and_releases_the_lock(): void
    {
        // Fails on the first call, works afterwards — one instance, because
        // the router caches the resolved controller (and so its provider)
        // across requests within a single test.
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            private int $calls = 0;

            public function chat(array $messages, array $tools = [], array $options = []): AiResponse
            {
                if (++$this->calls === 1) {
                    throw new AiProviderException('boom', statusCode: 500);
                }

                return new AiResponse(text: 'Recovered.');
            }
        });
        $id = $this->conversationId();

        $this->send($id, 'Will fail')->assertStatus(503);
        $this->assertSame(0, Message::where('conversation_id', $id)->count(), 'No orphaned question left behind.');

        $this->send($id, 'Retry')->assertCreated()->assertJsonPath('content', 'Recovered.');
    }

    public function test_a_malformed_gemini_body_through_the_real_adapter_is_a_clean_503_not_a_500(): void
    {
        config(['ai.api_key' => 'test-key']);
        $this->app->instance(AiProvider::class, new GeminiProvider('test-key', 'test-model'));
        Http::fake(['*generativelanguage.googleapis.com*' => Http::response('"unexpected string body"', 200, ['Content-Type' => 'application/json'])]);
        $id = $this->conversationId();

        $this->send($id, 'Hello')->assertStatus(503);
        $this->assertSame(0, Message::where('conversation_id', $id)->count());
    }

    public function test_every_api_response_carries_a_request_id_and_honours_a_valid_incoming_one(): void
    {
        $generated = $this->actingAs($this->user, 'sanctum')->getJson('/api/me');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated->headers->get('X-Request-Id'));

        $echoed = $this->actingAs($this->user, 'sanctum')->withHeader('X-Request-Id', 'trace-abc-12345')->getJson('/api/me');
        $this->assertSame('trace-abc-12345', $echoed->headers->get('X-Request-Id'));

        $rejected = $this->actingAs($this->user, 'sanctum')->withHeader('X-Request-Id', "bad id\nwith newline")->getJson('/api/me');
        $this->assertNotSame("bad id\nwith newline", $rejected->headers->get('X-Request-Id'));
    }

    public function test_tool_chain_logs_the_activity_on_its_own_day_then_adapts_only_the_future_plan(): void
    {
        $plans = app(WorkoutPlanService::class);
        $plans->createOrReplace($this->user, ['planned_date' => '2026-09-17', 'activity_type' => 'sport', 'title' => 'Soccer']);
        $plans->createOrReplace($this->user, ['planned_date' => '2026-09-18', 'activity_type' => 'strength', 'title' => 'Legs']);

        $this->fake(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest('c1', 'log_activity', [
                'type' => 'sport', 'logged_date' => '2026-09-16', 'intensity' => 'high', 'metadata' => ['sport' => 'Soccer'],
            ])]),
            new AiResponse(text: null, toolCalls: [new ToolCallRequest('c2', 'propose_weekly_plan_changes', [
                'decision_summary' => 'Lighter Friday after a hard soccer session today.',
                'reasoning_factors' => ['High-intensity soccer today.'],
                'changes' => [['date' => '2026-09-18', 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Mobility', 'reason' => 'Legs need recovery after soccer.']],
            ])]),
            new AiResponse(text: 'Logged today\'s soccer and eased Friday.'),
        );
        $id = $this->conversationId();

        $reply = $this->send($id, 'I played soccer today.')->assertCreated()->json();

        $this->assertSame(['log_activity', 'propose_weekly_plan_changes'], array_column($reply['meta']['tool_calls'], 'name'));

        $activity = ActivityLog::where('user_id', $this->user->id)->sole();
        $this->assertSame('2026-09-16', $activity->logged_date->toDateString());

        $this->assertSame('Soccer', $plans->forDate($this->user, '2026-09-17')->title, 'Thursday soccer is untouched.');
        $this->assertSame('Mobility', $plans->forDate($this->user, '2026-09-18')->title, 'Only the affected future day adapted.');
        $this->assertSame(0, ActivityLog::where('user_id', $this->user->id)->whereDate('logged_date', '2026-09-17')->count());
    }

    public function test_historical_activity_that_does_not_warrant_a_change_leaves_every_plan_untouched(): void
    {
        $plans = app(WorkoutPlanService::class);
        $plans->createOrReplace($this->user, ['planned_date' => '2026-09-17', 'activity_type' => 'sport', 'title' => 'Soccer']);

        $this->fake(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest('c1', 'log_activity', [
                'type' => 'steps', 'logged_date' => '2026-09-15', 'metadata' => ['steps' => 9000],
            ])]),
            new AiResponse(text: 'Logged 9,000 steps for yesterday — no change to your plan.'),
        );
        $id = $this->conversationId();

        $this->send($id, 'Yesterday I walked 9k steps.')->assertCreated();

        $this->assertSame('2026-09-15', ActivityLog::where('user_id', $this->user->id)->sole()->logged_date->toDateString());
        $this->assertSame('Soccer', $plans->forDate($this->user, '2026-09-17')->title);
    }

    public function test_if_the_model_wrongly_logs_a_future_event_as_activity_the_tool_refuses_and_nothing_is_saved(): void
    {
        $this->fake(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest('c1', 'log_activity', [
                'type' => 'sport', 'logged_date' => '2026-09-17', 'metadata' => ['sport' => 'Soccer'],
            ])]),
            new AiResponse(text: 'Noted that soccer is tomorrow — I haven\'t logged it as done.'),
        );
        $id = $this->conversationId();

        $reply = $this->send($id, 'I have soccer tomorrow.')->assertCreated()->json();

        $this->assertArrayHasKey('error', $reply['meta']['tool_calls'][0]['result']);
        $this->assertSame(0, ActivityLog::where('user_id', $this->user->id)->count());
    }
}
