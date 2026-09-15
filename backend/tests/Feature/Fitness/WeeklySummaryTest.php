<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\WeeklySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_caches_a_summary_grounded_in_real_stats(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: json_encode([
            'coach_insight' => 'You stayed consistent this week.',
            'next_week_focus' => 'Keep sessions under 45 minutes.',
        ])));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertSame('You stayed consistent this week.', $summary->coach_insight);
        $this->assertArrayHasKey('adherence_pct', $summary->stats);
        $this->assertDatabaseCount('weekly_summaries', 1);
    }

    public function test_a_second_call_in_the_same_week_is_served_from_cache_not_regenerated(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: json_encode(['coach_insight' => 'a', 'next_week_focus' => 'b'])));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $service = app(WeeklySummaryService::class);

        $first = $service->getForCurrentWeek($user);
        $second = $service->getForCurrentWeek($user);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $fake->calls);
        $this->assertSame(1, $user->weeklySummaries()->count());
    }

    public function test_a_non_json_response_falls_back_to_stat_grounded_text_instead_of_failing(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'not valid json'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        $summary = app(WeeklySummaryService::class)->getForCurrentWeek($user);

        $this->assertNotEmpty($summary->coach_insight);
        $this->assertNotEmpty($summary->next_week_focus);
    }

    public function test_the_endpoint_returns_the_ai_providers_mapped_error_message(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider(statusCode: 429));

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/coach/weekly-summary')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Your coach is at its request limit for now — please wait a moment and try again.');
    }

    public function test_the_endpoint_returns_a_full_summary_on_success(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: json_encode(['coach_insight' => 'Nice week.', 'next_week_focus' => 'Keep it up.'])));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/coach/weekly-summary')
            ->assertOk()
            ->assertJsonPath('coach_insight', 'Nice week.')
            ->assertJsonStructure(['week_start', 'stats', 'coach_insight', 'next_week_focus', 'generated_at']);
    }
}
