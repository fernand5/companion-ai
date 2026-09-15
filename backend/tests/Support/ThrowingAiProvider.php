<?php

namespace Tests\Support;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;

class ThrowingAiProvider implements AiProvider
{
    public function __construct(private readonly ?int $statusCode = null) {}

    public function chat(array $messages, array $tools = [], array $options = []): AiResponse
    {
        throw new AiProviderException(
            $this->statusCode === null ? 'AI_API_KEY is not configured.' : "Gemini API request failed: {$this->statusCode}",
            statusCode: $this->statusCode,
            reason: $this->statusCode === null ? 'not_configured' : null,
        );
    }
}
