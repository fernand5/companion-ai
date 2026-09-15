<?php

namespace App\Services\Memory;

use App\Models\User;

/**
 * Placeholder for a future Hermes Agent (Nous Research) integration.
 *
 * Evaluation conclusion (see README "Hermes evaluation"): Hermes is architected
 * as a self-contained interactive agent (CLI + chat-platform integrations) with
 * memory kept in flat files and an OpenAI-compatible chat-completions proxy. It
 * does not currently expose a documented REST API for an external backend to
 * call remember()/recall() against, so it cannot be embedded as a MemoryProvider
 * today. This class exists so that if Hermes ships such an API, only this class
 * needs to change — set MEMORY_PROVIDER=hermes to select it.
 */
class HermesMemoryProvider implements MemoryProvider
{
    public function remember(User $user, string $content, array $metadata = []): void
    {
        throw new MemoryProviderUnavailableException(
            'HermesMemoryProvider is a placeholder: Hermes Agent does not currently expose an '
            .'embeddable memory API. See README "Hermes evaluation". Use MEMORY_PROVIDER=database instead.'
        );
    }

    public function recall(User $user, string $query, int $limit = 10): array
    {
        throw new MemoryProviderUnavailableException(
            'HermesMemoryProvider is a placeholder: Hermes Agent does not currently expose an '
            .'embeddable memory API. See README "Hermes evaluation". Use MEMORY_PROVIDER=database instead.'
        );
    }
}
