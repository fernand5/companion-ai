<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTOs\AiResponse;

interface AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages  Normalized message list. Each item:
     *                                                      ['role' => 'system'|'user'|'assistant'|'tool', 'content' => ?string,
     *                                                      'tool_calls' => ToolCallRequest[] (assistant only),
     *                                                      'tool_call_id' => string, 'name' => string (tool only)]
     * @param  array<int, array{name: string, description: string, parameters: array}>  $tools
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $tools = [], array $options = []): AiResponse;
}
