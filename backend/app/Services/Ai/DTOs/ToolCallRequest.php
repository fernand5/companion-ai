<?php

namespace App\Services\Ai\DTOs;

final class ToolCallRequest
{
    /**
     * @param  array<string, mixed>  $meta  Opaque provider-specific data that must be
     *                                      echoed back verbatim on the next turn (e.g. Gemini's thoughtSignature).
     *                                      Providers that don't need this ignore it; AiCoachService never reads it.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
        public readonly array $meta = [],
    ) {}
}
