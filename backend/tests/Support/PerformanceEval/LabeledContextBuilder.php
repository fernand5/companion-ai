<?php

namespace Tests\Support\PerformanceEval;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\FitnessContextBuilder;

/**
 * EXPERIMENT (evaluation only, not used by the application): the same facts, with each
 * session explicitly labelled "latest" / "previous" / "older" and the comparison line
 * labelled "latest vs previous". Tests whether naming which session is which reduces the
 * real model's references to the wrong session, without changing a single number.
 */
final class LabeledContextBuilder extends FitnessContextBuilder
{
    public function build(User $user, string $userMessage, ?Conversation $conversation = null, bool $includeWeekPlan = false, ?array $weekRange = null, bool $includePerformance = false): array
    {
        $context = parent::build($user, $userMessage, $conversation, $includeWeekPlan, $weekRange, $includePerformance);

        if (is_string($context['performance'] ?? null) && $context['performance'] !== '') {
            $context['performance'] = self::label($context['performance']);
        }

        return $context;
    }

    public static function label(string $performance): string
    {
        $blocks = [];

        foreach (explode("\n\n", $performance) as $block) {
            $lines = explode("\n", $block);
            $session = array_keys(array_filter($lines, fn ($l) => preg_match('/^- \d{4}-\d{2}-\d{2}:/', $l)));

            foreach ($session as $position => $index) {
                $fromEnd = count($session) - 1 - $position;
                $label = $fromEnd === 0 ? 'latest session' : ($fromEnd === 1 ? 'previous session' : 'older session');
                $lines[$index] = preg_replace('/^- (\d{4}-\d{2}-\d{2}): /', "- {$label} ($1): ", $lines[$index]);
            }

            $lines = array_map(fn ($l) => preg_replace('/^Change (\d{4}-\d{2}-\d{2}) → (\d{4}-\d{2}-\d{2}):/', 'Latest vs previous measured session ($1 → $2):', $l), $lines);
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }
}
