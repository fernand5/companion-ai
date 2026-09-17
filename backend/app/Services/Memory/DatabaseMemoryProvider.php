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

    /**
     * A message re-stated across several similarly-worded turns (e.g. the same
     * step-count goal saved on 3 separate onboarding attempts) must not pile
     * up as near-duplicate rows — each one counts toward this user's `recall()`
     * limit and crowds out genuinely distinct preferences from the AI's
     * context. Real paraphrases of the same fact ("Targets around 6-8k steps
     * on average..." vs "User prefers 6-8k steps on average...") share almost
     * no character sequences in common (similar_text() scores them 27-60%),
     * so duplicate detection reuses the same keyword tokenizer as recall()
     * and compares keyword-SET overlap instead of raw string similarity.
     * Calibrated against real captured duplicates (0.33-0.44 overlap) vs a
     * genuinely distinct preference (0 overlap) — 0.3 cleanly separates them.
     */
    private const DUPLICATE_KEYWORD_OVERLAP_THRESHOLD = 0.3;

    public function remember(User $user, string $content, array $metadata = []): void
    {
        $content = trim($content);

        if ($content === '') {
            return;
        }

        if ($this->hasSimilarMemory($user, $content)) {
            return;
        }

        FitnessMemory::create([
            'user_id' => $user->id,
            'content' => $content,
            'category' => $metadata['category'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    private function hasSimilarMemory(User $user, string $content): bool
    {
        $newKeywords = $this->keywords($content);

        if ($newKeywords === []) {
            return false;
        }

        $existing = FitnessMemory::query()
            ->where('user_id', $user->id)
            ->pluck('content');

        foreach ($existing as $other) {
            $otherKeywords = $this->keywords($other);

            if ($otherKeywords === []) {
                continue;
            }

            $overlap = count(array_intersect($newKeywords, $otherKeywords));
            $smallerSetSize = min(count($newKeywords), count($otherKeywords));

            if (($overlap / $smallerSetSize) >= self::DUPLICATE_KEYWORD_OVERLAP_THRESHOLD) {
                return true;
            }
        }

        return false;
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
