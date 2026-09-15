<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Memory\MemoryProvider;

class RememberPreferenceTool implements AiTool
{
    public function __construct(private readonly MemoryProvider $memoryProvider) {}

    public function name(): string
    {
        return 'remember_preference';
    }

    public function description(): string
    {
        return 'Save a durable preference or behavior pattern you noticed about the user, so future conversations can '
            .'take it into account (e.g. "prefers short workouts on football days"). Do NOT use this for one-off facts '
            .'like today\'s steps or a single workout — those are logged with log_activity/log_workout instead.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string', 'description' => 'The preference/pattern to remember, as a short standalone sentence.'],
                'category' => ['type' => 'string', 'description' => 'Optional short label, e.g. "preference", "constraint".'],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $content = trim((string) ($arguments['content'] ?? ''));

        if ($content === '') {
            return ['error' => 'content is required.'];
        }

        $this->memoryProvider->remember($user, $content, [
            'category' => $arguments['category'] ?? 'preference',
        ]);

        return ['status' => 'remembered'];
    }
}
