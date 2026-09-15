<?php

namespace App\Services\Memory;

use App\Models\User;

interface MemoryProvider
{
    /**
     * Persist a durable, user-specific memory (a preference or learned pattern).
     * Not for one-off structured facts — those belong in the fitness data tables.
     */
    public function remember(User $user, string $content, array $metadata = []): void;

    /**
     * Retrieve the memories most relevant to the given query, most relevant first.
     *
     * @return array<int, array{content: string, category: ?string, created_at: string}>
     */
    public function recall(User $user, string $query, int $limit = 10): array;
}
