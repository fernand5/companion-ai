<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\GeminiImageGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks in the Interactions API contract verified against Google's current
 * docs and a real request: POST /v1beta/interactions, and an image block at
 * steps[].content[] (no `output_image` shortcut in the raw REST body).
 */
class GeminiImageGeneratorTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0jpeg-bytes";

    private function generator(): GeminiImageGenerator
    {
        return new GeminiImageGenerator(apiKey: 'test-key', model: 'gemini-3.1-flash-image');
    }

    /** A response shaped like the real one: a huge "thought" step, then the image step. */
    private function realShapedBody(array $imageBlock = []): array
    {
        return [
            'id' => 'v1_abc',
            'status' => 'completed',
            'model' => 'gemini-3.1-flash-image',
            'steps' => [
                ['type' => 'thought', 'signature' => str_repeat('x', 5000)],
                ['type' => 'model_output', 'content' => [array_merge([
                    'type' => 'image',
                    'data' => base64_encode(self::JPEG),
                    'mime_type' => 'image/jpeg',
                ], $imageBlock)]],
            ],
        ];
    }

    public function test_it_calls_the_interactions_endpoint_with_the_documented_request_shape(): void
    {
        config(['ai.image_aspect_ratio' => '4:3', 'ai.image_size' => '1K']);
        Http::fake(['*' => Http::response($this->realShapedBody(), 200)]);

        $this->generator()->generate('A squat');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request->method() === 'POST'
                && $request->header('x-goog-api-key') === ['test-key']
                && $body['model'] === 'gemini-3.1-flash-image'
                && $body['input'] === [['type' => 'text', 'text' => 'A squat']]
                && $body['response_format'] === ['type' => 'image', 'aspect_ratio' => '4:3', 'image_size' => '1K']
                && ! array_key_exists('generationConfig', $body);
        });
    }

    public function test_response_format_is_omitted_when_no_hints_are_configured(): void
    {
        config(['ai.image_aspect_ratio' => '', 'ai.image_size' => '']);
        Http::fake(['*' => Http::response($this->realShapedBody(), 200)]);

        $this->generator()->generate('A squat');

        Http::assertSent(fn (Request $request) => ! array_key_exists('response_format', $request->data()));
    }

    public function test_it_returns_the_decoded_image_from_the_step_content(): void
    {
        Http::fake(['*' => Http::response($this->realShapedBody(), 200)]);

        $image = $this->generator()->generate('A squat');

        $this->assertSame(self::JPEG, $image->bytes);
        $this->assertSame('image/jpeg', $image->mimeType);
        $this->assertSame('jpg', $image->extension());
        $this->assertSame('gemini-3.1-flash-image', $image->model);
    }

    public function test_it_throws_without_an_api_key_and_sends_nothing(): void
    {
        Http::fake();
        config(['ai.api_key' => null]);

        try {
            (new GeminiImageGenerator(apiKey: null, model: 'm'))->generate('x');
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame('not_configured', $e->userFacingReason());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: array<string, mixed>|string, 1: string}>
     */
    public static function unusableBodies(): array
    {
        $ok = fn (array $imageOverrides) => [
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [array_merge(['type' => 'image', 'data' => base64_encode('bytes'), 'mime_type' => 'image/png'], $imageOverrides)]]],
        ];

        return [
            'not completed' => [['status' => 'failed', 'steps' => []], 'incomplete'],
            'status missing' => [['steps' => []], 'incomplete'],
            'no steps' => [['status' => 'completed'], 'no_image'],
            'text-only answer (e.g. safety block)' => [['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'I cannot']]]]], 'no_image'],
            'steps not a list' => [['status' => 'completed', 'steps' => 'nope'], 'no_image'],
            'unsupported mime' => [$ok(['mime_type' => 'image/gif']), 'invalid_image'],
            'missing mime' => [$ok(['mime_type' => null]), 'invalid_image'],
            'invalid base64' => [$ok(['data' => '***not base64***']), 'invalid_image'],
            'empty image' => [$ok(['data' => '']), 'invalid_image'],
            'oversized image' => [$ok(['data' => base64_encode(str_repeat('a', 5 * 1024 * 1024 + 1))]), 'invalid_image'],
        ];
    }

    #[DataProvider('unusableBodies')]
    public function test_unusable_responses_become_clean_provider_exceptions(array $body, string $reason): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        try {
            $this->generator()->generate('x');
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame($reason, $e->userFacingReason());
        }
    }

    public function test_a_non_json_body_is_a_clean_failure(): void
    {
        Http::fake(['*' => Http::response('"just a string"', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(AiProviderException::class);
        $this->generator()->generate('x');
    }

    public function test_http_errors_carry_the_status_and_never_leak_the_body(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota exceeded for key AIzaSySECRET']], 429)]);

        try {
            $this->generator()->generate('x');
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertTrue($e->isRateLimited());
            $this->assertStringNotContainsString('AIzaSySECRET', $e->getMessage());
        }
    }

    public function test_a_timeout_becomes_an_unavailable_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        try {
            $this->generator()->generate('x');
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->userFacingReason());
        }
    }
}
