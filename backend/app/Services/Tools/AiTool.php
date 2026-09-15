<?php

namespace App\Services\Tools;

use App\Models\User;

interface AiTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON-schema-shaped parameter definition, e.g.
     * ['type' => 'object', 'properties' => [...], 'required' => [...]]
     */
    public function schema(): array;

    /**
     * Execute the tool for the given authenticated user. Implementations must
     * scope every query/write to $user — arguments are untrusted AI output and
     * must never be used to identify *which* user's data to touch.
     */
    public function execute(array $arguments, User $user): mixed;
}
