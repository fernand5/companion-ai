<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google Gemini implementation of AiProvider, using the v1beta generateContent
 * REST endpoint. See https://ai.google.dev/api/generate-content for the request
 * and response shapes this class maps to/from.
 */
class GeminiProvider implements AiProvider
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): AiResponse
    {
        $apiKey = $this->apiKey ?? config('ai.api_key');
        $model = $this->model ?? config('ai.model');

        if (! $apiKey) {
            throw new AiProviderException('AI_API_KEY is not configured.', reason: 'not_configured');
        }

        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemInstruction = [
                    'parts' => [['text' => $message['content']]],
                ];

                continue;
            }

            $contents[] = $this->mapMessage($message);
        }

        $payload = ['contents' => $contents];

        if ($systemInstruction) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ], $tools),
            ]];
        }

        $timeout = (int) ($options['timeout_seconds'] ?? config('ai.timeout', 20));
        unset($options['timeout_seconds']);

        if ($options !== []) {
            $payload['generationConfig'] = $options;
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );

        try {
            // Kept comfortably below PHP's own request execution time limit so a
            // slow/unreachable Gemini call surfaces as a clean AiProviderException
            // (caught below) instead of an uncaught fatal "max execution time" error.
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout($timeout)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Gemini API request timed out or failed to connect', [
                'error' => $e->getMessage(),
            ]);

            throw new AiProviderException(
                'Gemini API request timed out or failed to connect.',
                reason: 'unavailable',
                previous: $e,
            );
        }

        if ($response->failed()) {
            Log::warning('Gemini API request failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new AiProviderException(
                'Gemini API request failed: '.$response->status().' '.$response->body(),
                statusCode: $response->status(),
            );
        }

        $body = $response->json();

        // Valid JSON that isn't an object (a bare string/number) is as
        // unusable as no JSON at all.
        if (! is_array($body)) {
            Log::warning('Gemini API returned a non-JSON or empty body on a successful response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AiProviderException(
                'Gemini API returned a malformed (non-JSON) response body.',
                reason: 'unavailable',
            );
        }

        return $this->toAiResponse($body);
    }

    private function mapMessage(array $message): array
    {
        return match ($message['role']) {
            'user' => [
                'role' => 'user',
                'parts' => [['text' => $message['content'] ?? '']],
            ],
            'assistant' => [
                'role' => 'model',
                'parts' => $this->assistantParts($message),
            ],
            'tool' => [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $message['name'],
                        'response' => $this->asJsonObject($message['content']),
                    ],
                ]],
            ],
            default => [
                'role' => 'user',
                'parts' => [['text' => $message['content'] ?? '']],
            ],
        };
    }

    private function assistantParts(array $message): array
    {
        $parts = [];

        if (! empty($message['content'])) {
            $parts[] = ['text' => $message['content']];
        }

        /** @var ToolCallRequest[] $toolCalls */
        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $part = [
                'functionCall' => [
                    'name' => $toolCall->name,
                    // Gemini expects an object even for zero arguments; a bare
                    // PHP [] json-encodes as `[]`, which the API rejects.
                    'args' => $toolCall->arguments ?: (object) [],
                ],
            ];

            // Gemini 3's "thinking" models require the thoughtSignature from the
            // functionCall part to be echoed back verbatim on the next turn, or
            // the API rejects the request. See docs: gemini-api/docs/thinking#signatures
            if (isset($toolCall->meta['thoughtSignature'])) {
                $part['thoughtSignature'] = $toolCall->meta['thoughtSignature'];
            }

            $parts[] = $part;
        }

        return $parts ?: [['text' => '']];
    }

    /**
     * Gemini's functionResponse.response (and functionCall.args) must be a
     * JSON object. A bare PHP list or empty array json-encodes as `[]`, which
     * the API rejects, so wrap anything that isn't already an associative array.
     */
    private function asJsonObject(mixed $value): array|object
    {
        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            return $value;
        }

        if (is_array($value) && $value === []) {
            return (object) [];
        }

        return ['result' => $value];
    }

    private function toAiResponse(array $body): AiResponse
    {
        // No candidate at all (e.g. the prompt was blocked) means the model
        // produced nothing to act on — distinct from a candidate that simply
        // has no parts, which is a valid empty turn the caller handles.
        $candidate = $body['candidates'][0] ?? null;

        if (! is_array($candidate)) {
            Log::warning('Gemini API returned no candidates', [
                'block_reason' => $body['promptFeedback']['blockReason'] ?? null,
            ]);

            throw new AiProviderException('Gemini API returned no usable candidates.', reason: 'unavailable');
        }

        $parts = $candidate['content']['parts'] ?? [];

        $text = null;
        $toolCalls = [];

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $text = ($text ?? '').$part['text'];
            }

            if (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    throw new AiProviderException('Gemini API returned a function call without a name.', reason: 'unavailable');
                }

                $args = $part['functionCall']['args'] ?? [];

                $toolCalls[] = new ToolCallRequest(
                    id: (string) Str::uuid(),
                    name: $name,
                    // Non-object args are handed to the tool as "no
                    // arguments"; the tool's own validation then reports
                    // what's missing back to the model.
                    arguments: is_array($args) ? $args : [],
                    meta: isset($part['thoughtSignature']) ? ['thoughtSignature' => $part['thoughtSignature']] : [],
                );
            }
        }

        return new AiResponse(text: $text, toolCalls: $toolCalls, raw: $body);
    }
}
