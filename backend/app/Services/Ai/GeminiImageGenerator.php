<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\ImageGenerator;
use App\Services\Ai\DTOs\GeneratedImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini image generation through the Interactions API — a different contract
 * from the generateContent endpoint GeminiProvider uses for text. Verified
 * against Google's current documentation and a real request:
 *
 *   POST https://generativelanguage.googleapis.com/v1beta/interactions
 *   {"model": "...", "input": [{"type": "text", "text": "..."}],
 *    "response_format": {"type": "image", "aspect_ratio": "4:3", "image_size": "1K"}}
 *
 * The response's `status` must be "completed", and the image is a content
 * block inside a step: steps[].content[] = {"type":"image","data":<base64>,
 * "mime_type":"image/jpeg"}. (The SDKs' `output_image` shortcut does not exist
 * in the raw REST body.) The body is ~2 MB and includes a very large
 * "thought" signature, so it is never logged.
 */
class GeminiImageGenerator implements ImageGenerator
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?int $timeout = null,
    ) {}

    public function generate(string $prompt): GeneratedImage
    {
        $apiKey = $this->apiKey ?? config('ai.api_key');
        $model = $this->model ?? config('ai.image_model');

        if (! $apiKey) {
            throw new AiProviderException('AI_API_KEY is not configured.', reason: 'not_configured');
        }

        $payload = [
            'model' => $model,
            'input' => [['type' => 'text', 'text' => $prompt]],
        ];

        $responseFormat = array_filter([
            'type' => 'image',
            'aspect_ratio' => config('ai.image_aspect_ratio'),
            'image_size' => config('ai.image_size'),
        ]);

        if (count($responseFormat) > 1) {
            $payload['response_format'] = $responseFormat;
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout($this->timeout ?? (int) config('ai.image_timeout', 60))
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Gemini image request timed out or failed to connect');

            throw new AiProviderException('Gemini image request timed out or failed to connect.', reason: 'unavailable', previous: $e);
        }

        if ($response->failed()) {
            // Status and Google's short error message only — never the full body.
            Log::warning('Gemini image request failed', [
                'status' => $response->status(),
                'error' => mb_substr((string) $response->json('error.message'), 0, 200),
            ]);

            throw new AiProviderException('Gemini image request failed: '.$response->status(), statusCode: $response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'completed') {
            throw new AiProviderException('Gemini image request did not complete.', reason: 'incomplete');
        }

        $block = $this->findImageBlock($body['steps'] ?? []);

        if ($block === null) {
            // Typically a safety block: the model answered without an image.
            throw new AiProviderException('Gemini returned no image.', reason: 'no_image');
        }

        $mime = $block['mime_type'] ?? '';

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new AiProviderException('Gemini returned an unsupported image type.', reason: 'invalid_image');
        }

        $bytes = base64_decode((string) $block['data'], true);

        if ($bytes === false || $bytes === '') {
            throw new AiProviderException('Gemini returned an undecodable image.', reason: 'invalid_image');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new AiProviderException('Gemini returned an oversized image.', reason: 'invalid_image');
        }

        return new GeneratedImage($bytes, $mime, (string) $model);
    }

    /**
     * @return array{data: string, mime_type?: string}|null the last image block, as the docs' convenience property does
     */
    private function findImageBlock(mixed $steps): ?array
    {
        if (! is_array($steps)) {
            return null;
        }

        $found = null;

        foreach ($steps as $step) {
            foreach (is_array($step['content'] ?? null) ? $step['content'] : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'image' && is_string($block['data'] ?? null)) {
                    $found = $block;
                }
            }
        }

        return $found;
    }
}
