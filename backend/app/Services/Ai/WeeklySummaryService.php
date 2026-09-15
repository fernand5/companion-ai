<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Models\WeeklySummary;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\AdherenceService;
use Carbon\Carbon;

/**
 * Generates the weekly AI recap, cached per (user, week_start) so it's never
 * regenerated on every page view — the AI is only called once per week per
 * user, then the whole row (stats + text) is served from the cache. This
 * keeps the AI-written text always consistent with the numbers next to it,
 * and matters given the Gemini free-tier quota this app runs against.
 */
class WeeklySummaryService
{
    public function __construct(
        private readonly AdherenceService $adherenceService,
        private readonly AiProvider $aiProvider,
    ) {}

    public function getForCurrentWeek(User $user): WeeklySummary
    {
        $weekStart = Carbon::today()->startOfWeek()->toDateString();

        $existing = $user->weeklySummaries()->whereDate('week_start', $weekStart)->first();

        return $existing ?? $this->generate($user, $weekStart);
    }

    private function generate(User $user, string $weekStart): WeeklySummary
    {
        set_time_limit(60);

        $weekEnd = Carbon::parse($weekStart)->endOfWeek()->toDateString();
        $stats = $this->adherenceService->summary($user, $weekStart, $weekEnd);

        $response = $this->aiProvider->chat([
            [
                'role' => 'system',
                'content' => 'You are an adaptive fitness coach writing a short weekly recap for the user. '
                    .'You will be given real, computed stats for their week — base every claim strictly on '
                    .'those numbers. Never invent a behavioral pattern the numbers do not support. Respond '
                    .'with a JSON object with exactly two string fields: "coach_insight" (1-2 encouraging, '
                    .'specific sentences about their week — only note a pattern if the stats clearly support '
                    .'it, otherwise just acknowledge the numbers) and "next_week_focus" (1-2 sentences of '
                    .'concrete, actionable guidance for next week).',
            ],
            ['role' => 'user', 'content' => 'This week\'s stats: '.json_encode($stats)],
        ], [], ['responseMimeType' => 'application/json']);

        [$coachInsight, $nextWeekFocus] = $this->parseResponse($response->text, $stats);

        $attributes = [
            'stats' => $stats,
            'coach_insight' => $coachInsight,
            'next_week_focus' => $nextWeekFocus,
            'generated_at' => now(),
        ];

        $summary = $user->weeklySummaries()->whereDate('week_start', $weekStart)->first();

        if ($summary) {
            $summary->update($attributes);
        } else {
            $summary = $user->weeklySummaries()->create($attributes + ['week_start' => $weekStart]);
        }

        return $summary;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseResponse(?string $text, array $stats): array
    {
        $decoded = $text ? json_decode($text, true) : null;

        if (is_array($decoded) && ! empty($decoded['coach_insight']) && ! empty($decoded['next_week_focus'])) {
            return [$decoded['coach_insight'], $decoded['next_week_focus']];
        }

        // Defensive fallback if the model didn't return valid JSON — still
        // grounded in the real numbers, never fabricated.
        $adherence = $stats['adherence_pct'] !== null ? "{$stats['adherence_pct']}% adherence" : 'no planned workouts yet';

        return [
            "This week: {$adherence}, {$stats['active_days']} active day(s).",
            'Keep logging your workouts and check-ins so next week\'s recap can be more specific.',
        ];
    }
}
