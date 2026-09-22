<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\GeminiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    public function test_it_throws_when_no_api_key_is_configured(): void
    {
        // Belt-and-suspenders: even if a real key ever leaked into config,
        // this must not be able to reach the network.
        Http::fake();

        config(['ai.api_key' => null]);

        $this->expectException(AiProviderException::class);

        (new GeminiProvider(apiKey: null, model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        Http::assertNothingSent();
    }

    public function test_it_parses_text_and_function_calls_from_the_response(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['functionCall' => ['name' => 'get_today_activity', 'args' => []]],
                            ['thoughtSignature' => 'sig-123'],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $response = (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'What did I do today?'],
        ], [
            ['name' => 'get_today_activity', 'description' => '...', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]],
        ]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('get_today_activity', $response->toolCalls[0]->name);
    }

    public function test_empty_tool_call_arguments_are_sent_as_a_json_object_not_an_array(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]]]],
            ], 200),
        ]);

        (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'What did I do today?'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [new ToolCallRequest(id: 'x1', name: 'get_today_activity', arguments: [])],
            ],
            ['role' => 'tool', 'tool_call_id' => 'x1', 'name' => 'get_today_activity', 'content' => []],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $functionCallPart = $body['contents'][1]['parts'][0]['functionCall'];
            $functionResponsePart = $body['contents'][2]['parts'][0]['functionResponse'];

            // json_decode(..., true) on the raw JSON tells us whether args/response
            // were encoded as a JSON object ({}) or array ([]) on the wire.
            $raw = json_decode($request->body(), true);

            return $raw['contents'][1]['parts'][0]['functionCall']['args'] === []
                && $functionCallPart['name'] === 'get_today_activity'
                && $functionResponsePart['name'] === 'get_today_activity'
                && str_contains($request->body(), '"args":{}')
                && str_contains($request->body(), '"response":{}');
        });
    }

    public function test_thought_signature_is_echoed_back_on_the_next_turn(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]]]],
            ], 200),
        ]);

        (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'hi'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    new ToolCallRequest(id: 'x1', name: 'get_today_activity', arguments: [], meta: ['thoughtSignature' => 'sig-abc']),
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'x1', 'name' => 'get_today_activity', 'content' => []],
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'sig-abc');
        });
    }

    public function test_a_connection_timeout_is_converted_to_an_ai_provider_exception(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        try {
            (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->userFacingReason());
        }
    }

    public function test_a_malformed_non_json_200_response_is_converted_to_an_ai_provider_exception(): void
    {
        // A 200 status with a body that isn't valid JSON (e.g. a truncated
        // stream, an HTML error page from an intermediary proxy) must not
        // crash with an uncaught TypeError — it should degrade the same way
        // as any other provider failure.
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response('not json at all <html>', 200),
        ]);

        try {
            (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->userFacingReason());
        }
    }

    public function test_a_429_response_is_reported_as_rate_limited(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        try {
            (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertSame('rate_limited', $e->userFacingReason());
        }
    }

    /**
     * Valid JSON that isn't the shape we expect must degrade into a clean
     * AiProviderException, never a TypeError/500 out of the adapter.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedBodies(): array
    {
        return [
            'json string, not an object' => ['oops'],
            'json number' => [123],
            'no candidates (e.g. prompt blocked)' => [['promptFeedback' => ['blockReason' => 'SAFETY']]],
            'empty candidates' => [['candidates' => []]],
            'candidates not a list' => [['candidates' => 'nope']],
            'function call without a name' => [['candidates' => [['content' => ['parts' => [['functionCall' => ['args' => []]]]]]]]],
            'function call with non-string name' => [['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => ['x']]]]]]]]],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function test_a_well_formed_json_but_malformed_response_becomes_an_ai_provider_exception(mixed $body): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(json_encode($body), 200, ['Content-Type' => 'application/json']),
        ]);

        try {
            (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->userFacingReason());
        }
    }

    public function test_a_candidate_with_no_parts_is_still_a_valid_empty_response(): void
    {
        // A model that just finished its tool calls can legitimately return no
        // closing text; the coach service handles that by asking again. It
        // must not be confused with a malformed response.
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']],
            ], 200),
        ]);

        $response = (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertNull($response->text);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test_non_object_function_call_args_are_treated_as_no_arguments(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'get_todays_plan', 'args' => 'garbage']]]]]],
            ], 200),
        ]);

        $response = (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame([], $response->toolCalls[0]->arguments);
    }

    public function test_timeout_seconds_option_sets_the_http_timeout_and_is_not_sent_to_the_model(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]], 200),
        ]);

        (new GeminiProvider(apiKey: 'test-key', model: 'gemini-3.6-flash'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [],
            ['timeout_seconds' => 90],
        );

        Http::assertSent(fn ($request) => ! array_key_exists('generationConfig', $request->data()));
    }
}
