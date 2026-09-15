<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;

/**
 * Test double for AiProvider — queue up canned responses so tests never make
 * real network calls to an LLM. Records every call for assertions.
 */
class FakeAiProvider implements AiProvider
{
    /** @var AiResponse[] */
    private array $queue = [];

    /** @var array<int, array{messages: array, tools: array, options: array}> */
    public array $calls = [];

    public function queue(AiResponse ...$responses): static
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function chat(array $messages, array $tools = [], array $options = []): AiResponse
    {
        $this->calls[] = compact('messages', 'tools', 'options');

        return array_shift($this->queue) ?? new AiResponse(text: 'OK, noted.');
    }
}
