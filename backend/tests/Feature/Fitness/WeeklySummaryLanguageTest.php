<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Models\WeeklySummary;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\WeeklySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

/**
 * Phase 7 / M2: the weekly recap is written in the user's language, records that language inside its
 * existing stats JSON, and is rewritten once when it is asked for in another language. It is never
 * rewritten merely because the preference changed, and a failed rewrite leaves the recap that exists.
 */
class WeeklySummaryLanguageTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
    }

    private function recap(string $insight, string $focus = 'Focus.'): AiResponse
    {
        return new AiResponse(text: json_encode(['coach_insight' => $insight, 'next_week_focus' => $focus]));
    }

    private function user(string $locale = 'en'): User
    {
        return User::factory()->create(['locale' => $locale]);
    }

    private function setLanguage(User $user, string $locale): User
    {
        User::whereKey($user->id)->update(['locale' => $locale]);

        return User::findOrFail($user->id);
    }

    /** A recap stored the way it was before the language marker existed. */
    private function legacyRecap(User $user, string $insight = 'Old insight.'): WeeklySummary
    {
        $stats = ['adherence_pct' => 50, 'active_days' => 2];

        return $user->weeklySummaries()->create([
            'week_start' => $user->localNow()->startOfWeek()->toDateString(),
            'stats' => $stats,
            'coach_insight' => $insight,
            'next_week_focus' => 'Old focus.',
            'generated_at' => now(),
        ]);
    }

    public function test_a_new_recap_is_written_in_the_users_language_and_records_it(): void
    {
        $user = $this->user('es');
        $this->fake->queue($this->recap('Buena semana.', 'Repite la rutina.'));

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertSame('Buena semana.', $summary->coach_insight);
        $this->assertSame('es', $summary->stats[WeeklySummaryService::LANGUAGE_KEY]);
        $this->assertSame('es', WeeklySummaryService::languageOf($summary)->value);
        $this->assertCount(1, $this->fake->calls);

        $prompt = $this->fake->calls[0]['messages'][0]['content'];
        $this->assertStringContainsString('natural, neutral Latin American Spanish', $prompt);
        $this->assertStringContainsString('## Coaching voice', $prompt);
        $this->assertStringContainsString('Respond with a JSON object with exactly two string fields: "coach_insight"', $prompt, 'The JSON contract names are unchanged.');
        $this->assertStringContainsString('JSON keys', $prompt, 'and the language section says to keep them.');
        $this->assertSame(['responseMimeType' => 'application/json'], $this->fake->calls[0]['options']);
        $this->assertSame([], $this->fake->calls[0]['tools']);
    }

    public function test_the_response_contract_is_unchanged_and_never_shows_the_language_marker(): void
    {
        $user = $this->user('es');
        $this->fake->queue($this->recap('Buena semana.'));

        $json = $this->actingAs($user, 'sanctum')->getJson('/api/coach/weekly-summary')
            ->assertOk()
            ->assertJsonStructure(['week_start', 'stats', 'coach_insight', 'next_week_focus', 'generated_at'])
            ->json();

        $this->assertSame(['week_start', 'stats', 'coach_insight', 'next_week_focus', 'generated_at'], array_keys($json));
        $this->assertArrayNotHasKey('language', $json['stats']);
        $this->assertArrayHasKey('adherence_pct', $json['stats']);
        $this->assertSame('es', WeeklySummary::first()->stats['language'], 'It is stored, only not sent.');
    }

    public function test_english_users_are_unchanged_and_a_second_request_makes_no_call(): void
    {
        $user = $this->user('en');
        $this->fake->queue($this->recap('Good week.'));
        $service = app(WeeklySummaryService::class);

        $first = $service->getForCurrentWeek($user);
        $second = $service->getForCurrentWeek(User::findOrFail($user->id));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('en', $first->stats['language']);
        $this->assertCount(1, $this->fake->calls);
    }

    public function test_a_recap_stored_before_the_marker_is_english_and_is_served_to_english_without_a_call(): void
    {
        $user = $this->user('en');
        $this->legacyRecap($user);

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertSame('Old insight.', $summary->coach_insight);
        $this->assertSame('en', WeeklySummaryService::languageOf($summary)->value);
        $this->assertCount(0, $this->fake->calls);
    }

    public function test_a_recap_asked_for_in_another_language_is_rewritten_once(): void
    {
        $user = $this->user('es');
        $legacy = $this->legacyRecap($user);
        $this->fake->queue($this->recap('Semana sólida.', 'Sigue así.'));
        $service = app(WeeklySummaryService::class);

        $summary = $service->getForCurrentWeek($user);

        $this->assertSame($legacy->id, $summary->id, 'The same row is rewritten, not a second one.');
        $this->assertSame('Semana sólida.', $summary->coach_insight);
        $this->assertSame('es', $summary->stats['language']);
        $this->assertArrayHasKey('adherence_pct', $summary->stats, 'The stats are recomputed from the real numbers and kept beside the marker.');
        $this->assertCount(1, $this->fake->calls);
        $this->assertDatabaseCount('weekly_summaries', 1);

        $again = $service->getForCurrentWeek(User::findOrFail($user->id));
        $this->assertSame('Semana sólida.', $again->coach_insight);
        $this->assertCount(1, $this->fake->calls, 'Asked again in the same language: served as stored.');
    }

    public function test_changing_the_preference_alone_never_regenerates_the_recap(): void
    {
        $user = $this->user('en');
        $this->fake->queue($this->recap('Good week.'));
        app(WeeklySummaryService::class)->getForCurrentWeek($user);
        $this->assertCount(1, $this->fake->calls);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => 'es'])->assertOk();

        $this->assertCount(1, $this->fake->calls, 'Saving the language is not a request for a recap.');
        $this->assertSame('Good week.', WeeklySummary::first()->coach_insight);
        $this->assertSame('en', WeeklySummary::first()->stats['language']);
    }

    public function test_switching_back_after_a_rewrite_rewrites_again_only_when_a_recap_is_requested(): void
    {
        $user = $this->user('en');
        $this->fake->queue($this->recap('Good week.'), $this->recap('Buena semana.'), $this->recap('Good week again.'));
        $service = app(WeeklySummaryService::class);

        $service->getForCurrentWeek($user);
        $service->getForCurrentWeek($this->setLanguage($user, 'es'));
        $this->assertCount(2, $this->fake->calls);

        $this->assertSame('Good week again.', $service->getForCurrentWeek($this->setLanguage($user, 'en'))->coach_insight);
        $this->assertCount(3, $this->fake->calls);
        $this->assertDatabaseCount('weekly_summaries', 1);
    }

    public function test_a_failed_rewrite_keeps_serving_the_existing_recap_unchanged(): void
    {
        $user = $this->user('es');
        $legacy = $this->legacyRecap($user);
        $this->app->instance(AiProvider::class, new ThrowingAiProvider(statusCode: 429));

        $this->actingAs($user, 'sanctum')->getJson('/api/coach/weekly-summary')
            ->assertOk()
            ->assertJsonPath('coach_insight', 'Old insight.')
            ->assertJsonPath('next_week_focus', 'Old focus.');

        $row = $legacy->fresh();
        $this->assertSame('Old insight.', $row->coach_insight);
        $this->assertArrayNotHasKey('language', $row->stats, 'Still unmarked, so the next request tries again.');
    }

    public function test_an_unusable_rewrite_never_replaces_a_recap_with_the_stats_only_fallback(): void
    {
        $user = $this->user('es');
        $legacy = $this->legacyRecap($user);
        $this->fake->queue(new AiResponse(text: 'not valid json'));

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertSame('Old insight.', $summary->coach_insight);
        $this->assertSame('Old insight.', $legacy->fresh()->coach_insight);
        $this->assertArrayNotHasKey('language', $legacy->fresh()->stats);
        $this->assertCount(1, $this->fake->calls);
    }

    public function test_a_second_request_while_one_is_rewriting_serves_the_stored_recap_and_calls_nothing(): void
    {
        $user = $this->user('es');
        $this->legacyRecap($user);
        $weekStart = $user->localNow()->startOfWeek()->toDateString();
        $lock = Cache::lock("weekly_summary_language:{$user->id}:{$weekStart}:es", 90);
        $this->assertTrue($lock->get(), 'Another request is rewriting this recap.');

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertSame('Old insight.', $summary->coach_insight);
        $this->assertCount(0, $this->fake->calls, 'No duplicate provider call.');

        $lock->release();
        $this->fake->queue($this->recap('Semana sólida.'));
        $this->assertSame('Semana sólida.', app(WeeklySummaryService::class)->getForCurrentWeek($user)->coach_insight);
        $this->assertCount(1, $this->fake->calls);
    }

    public function test_the_lock_is_released_after_a_failed_rewrite(): void
    {
        $user = $this->user('es');
        $this->legacyRecap($user);
        $this->app->instance(AiProvider::class, new ThrowingAiProvider(statusCode: 500));

        app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->app->instance(AiProvider::class, $this->fake);
        $this->fake->queue($this->recap('Semana sólida.'));
        $this->assertSame('Semana sólida.', app(WeeklySummaryService::class)->getForCurrentWeek($user)->coach_insight, 'A failure does not leave the rewrite blocked.');
    }

    public function test_a_marker_this_code_does_not_know_is_treated_as_english(): void
    {
        $user = $this->user('en');
        $row = $this->legacyRecap($user);
        $row->update(['stats' => $row->stats + ['language' => 'fr']]);

        $this->assertCount(0, $this->fake->calls);
        $this->assertSame('Old insight.', app(WeeklySummaryService::class)->getForCurrentWeek($user)->coach_insight);
        $this->assertCount(0, $this->fake->calls);
    }

    public function test_the_first_recap_still_falls_back_to_stat_grounded_text_in_the_users_language(): void
    {
        $this->fake->queue(new AiResponse(text: 'not valid json'), new AiResponse(text: 'not valid json'));

        $spanish = app(WeeklySummaryService::class)->getForCurrentWeek($this->user('es'));
        $english = app(WeeklySummaryService::class)->getForCurrentWeek($this->user('en'));

        $this->assertStringStartsWith('Esta semana:', $spanish->coach_insight);
        $this->assertSame('es', $spanish->stats['language']);
        $this->assertStringStartsWith('This week:', $english->coach_insight);
        $this->assertSame('en', $english->stats['language']);
        $this->assertNotEmpty($spanish->next_week_focus);
    }

    public function test_the_first_recap_still_fails_with_the_provider_error_when_there_is_nothing_to_keep(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider(statusCode: 429));

        $this->actingAs($this->user('es'), 'sanctum')->getJson('/api/coach/weekly-summary')
            ->assertStatus(503);

        $this->assertDatabaseCount('weekly_summaries', 0);
    }
}
