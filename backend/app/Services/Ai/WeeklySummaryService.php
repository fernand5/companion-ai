<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Models\WeeklySummary;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\AdherenceService;
use App\Support\AppLocale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Generates the weekly AI recap, cached per (user, week_start) so it's never
 * regenerated on every page view — the AI is only called once per week per
 * user, then the whole row (stats + text) is served from the cache. This
 * keeps the AI-written text always consistent with the numbers next to it,
 * and matters given the Gemini free-tier quota this app runs against.
 *
 * The row also records, inside its existing stats JSON, the language the text was written in. A recap
 * asked for in a different language is regenerated once (never merely because the user changed their
 * preference), and if that fails the recap already stored is kept and served.
 */
class WeeklySummaryService
{
    public function __construct(
        private readonly AdherenceService $adherenceService,
        private readonly AiProvider $aiProvider,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {}

    /** Key inside the stats JSON that records the language the recap text was written in. Never sent to the client. */
    public const LANGUAGE_KEY = 'language';

    public function getForCurrentWeek(User $user): WeeklySummary
    {
        $weekStart = $user->localNow()->startOfWeek()->toDateString();
        $locale = $user->appLocale();

        $existing = $user->weeklySummaries()->whereDate('week_start', $weekStart)->first();

        if ($existing === null) {
            return $this->generate($user, $weekStart, $locale);
        }

        return self::languageOf($existing) === $locale ? $existing : $this->regenerate($user, $weekStart, $locale, $existing);
    }

    /** The language a stored recap was written in. A recap from before this marker existed was written in English. */
    public static function languageOf(WeeklySummary $summary): AppLocale
    {
        return AppLocale::fromStored($summary->stats[self::LANGUAGE_KEY] ?? null);
    }

    /**
     * Writes the recap again in another language. One request at a time per user, week and language: a
     * request that finds another one already at it serves what is stored instead of calling the model
     * twice. Any failure leaves the stored recap exactly as it was.
     */
    private function regenerate(User $user, string $weekStart, AppLocale $locale, WeeklySummary $existing): WeeklySummary
    {
        $lock = Cache::lock("weekly_summary_language:{$user->id}:{$weekStart}:{$locale->value}", 90);

        if (! $lock->get()) {
            return $existing;
        }

        try {
            // Another request may have finished while this one waited for the lock.
            $current = $user->weeklySummaries()->whereDate('week_start', $weekStart)->first() ?? $existing;

            if (self::languageOf($current) === $locale) {
                return $current;
            }

            return $this->generate($user, $weekStart, $locale, $current);
        } catch (\Throwable $e) {
            report($e);

            return $existing;
        } finally {
            $lock->release();
        }
    }

    private function generate(User $user, string $weekStart, AppLocale $locale, ?WeeklySummary $replacing = null): WeeklySummary
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
                    .'concrete, actionable guidance for next week). '
                    // Voice only; the JSON shape and the "only what the numbers support" rule above still apply.
                    .$this->promptBuilder->voiceSection(CoachingPreferences::forUser($user))
                    // Which language the values are written in; the JSON keys above stay exactly as named.
                    ."\n\n".$this->promptBuilder->languageSection($locale),
            ],
            ['role' => 'user', 'content' => 'This week\'s stats: '.json_encode($stats)],
        ], [], ['responseMimeType' => 'application/json']);

        $parsed = $this->parseResponse($response->text);

        if ($parsed === null) {
            // Never replace a recap that exists with the stats-only fallback.
            if ($replacing !== null) {
                return $replacing;
            }

            $parsed = $this->fallback($stats, $locale);
        }

        [$coachInsight, $nextWeekFocus] = $parsed;

        $attributes = [
            'stats' => $stats + [self::LANGUAGE_KEY => $locale->value],
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
     * @return array{0: string, 1: string}|null
     */
    private function parseResponse(?string $text): ?array
    {
        $decoded = $text ? json_decode($text, true) : null;

        if (is_array($decoded) && ! empty($decoded['coach_insight']) && ! empty($decoded['next_week_focus'])) {
            return [$decoded['coach_insight'], $decoded['next_week_focus']];
        }

        return null;
    }

    /**
     * Defensive fallback if the model didn't return valid JSON — still grounded in the real numbers,
     * never fabricated, and in the language the recap was asked for.
     *
     * @return array{0: string, 1: string}
     */
    private function fallback(array $stats, AppLocale $locale): array
    {
        if ($locale === AppLocale::Spanish) {
            $adherence = $stats['adherence_pct'] !== null ? "{$stats['adherence_pct']}% de cumplimiento" : 'aún no hay entrenamientos planificados';

            return [
                "Esta semana: {$adherence}, {$stats['active_days']} día(s) activo(s).",
                'Sigue registrando tus entrenamientos y chequeos para que el resumen de la próxima semana sea más específico.',
            ];
        }

        $adherence = $stats['adherence_pct'] !== null ? "{$stats['adherence_pct']}% adherence" : 'no planned workouts yet';

        return [
            "This week: {$adherence}, {$stats['active_days']} active day(s).",
            'Keep logging your workouts and check-ins so next week\'s recap can be more specific.',
        ];
    }
}
