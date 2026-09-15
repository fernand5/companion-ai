<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Memory\MemoryProvider;

class GetUserPreferencesTool implements AiTool
{
    public function __construct(private readonly MemoryProvider $memoryProvider) {}

    public function name(): string
    {
        return 'get_user_preferences';
    }

    public function description(): string
    {
        return 'Get the user\'s known long-term preferences and behavior patterns previously remembered by the coach.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max preferences to return, default 20.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        return $this->memoryProvider->recall($user, '', $limit);
    }
}
