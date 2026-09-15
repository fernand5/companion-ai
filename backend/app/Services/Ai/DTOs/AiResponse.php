<?php

namespace App\Services\Ai\DTOs;

final class AiResponse
{
    /**
     * @param  ToolCallRequest[]  $toolCalls
     * @param  array<string, mixed>  $raw  Full provider response, for debug logging only.
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
        public readonly array $raw = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
