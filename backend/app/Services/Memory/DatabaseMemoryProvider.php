<?php

namespace App\Services\Memory;

use App\Models\FitnessMemory;
use App\Models\User;

/**
 * MySQL-backed MemoryProvider. Recall uses a lightweight keyword-overlap score
 * (no vector database — per-user memory volume is small for an MVP) with a
 * recency backfill so the coach still sees the user's active preferences even
 * when the current message shares no literal words with a stored memory.
 */
class DatabaseMemoryProvider implements MemoryProvider
{
    /** @var string[] */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been',
        'i', 'me', 'my', 'you', 'your', 'do', 'did', 'does', 'what', 'should', 'today',
        'to', 'of', 'in', 'on', 'for', 'with', 'that', 'this', 'it', 'have', 'has',
    ];

    public function remember(User $user, string $content, array $metadata = []): void
    {
        $content = trim($content);

        if ($content === '') {
            return;
        }

        FitnessMemory::create([
            'user_id' => $user->id,
            'content' => $content,
            'category' => $metadata['category'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    public function recall(User $user, string $query, int $limit = 10): array
    {
        $memories = FitnessMemory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        if ($memories->isEmpty()) {
            return [];
        }

        $keywords = $this->keywords($query);

        $scored = $memories
            ->map(function (FitnessMemory $memory) use ($keywords) {
                $haystack = mb_strtolower($memory->content);
                $score = 0;

                foreach ($keywords as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        $score++;
                    }
                }

                return ['memory' => $memory, 'score' => $score];
            })
            ->sortByDesc('score')
            ->values();

        $matched = $scored->filter(fn (array $row) => $row['score'] > 0)->pluck('memory');
        $backfill = $scored->filter(fn (array $row) => $row['score'] === 0)->pluck('memory');

        $selected = $matched->concat($backfill)->take($limit);

        return $selected
            ->map(fn (FitnessMemory $memory) => [
                'content' => $memory->content,
                'category' => $memory->category,
                'created_at' => $memory->created_at->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return string[]
     */
    private function keywords(string $query): array
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [];

        return collect($words)
            ->filter(fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }
}
