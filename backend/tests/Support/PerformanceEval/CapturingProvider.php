<?php

namespace Tests\Support\PerformanceEval;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;

/** Wraps any provider and records exactly what the model was sent and how long it took. */
final class CapturingProvider implements AiProvider
{
    /** @var array<int, array{messages: array, tools: array, latency_ms: int}> */
    public array $calls = [];

    public function __construct(private readonly AiProvider $inner) {}

    public function chat(array $messages, array $tools = [], array $options = []): AiResponse
    {
        $started = microtime(true);
        $index = count($this->calls);
        $this->calls[$index] = ['messages' => $messages, 'tools' => $tools, 'latency_ms' => 0];

        try {
            return $this->inner->chat($messages, $tools, $options);
        } finally {
            $this->calls[$index]['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        }
    }
}
