<?php

namespace Tests\Feature\Fitness;

use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * The progression endpoints have their own named rate limits. Laravel's plain `throttle:N,M` shares
 * one counter per user across every route that uses it, so reviewing suggestions used to eat into the
 * chat budget; a named limiter's counter is its own. These tests pin both halves: each limit bites at
 * its number, and none of them can be spent by (or spend) another's.
 */
class ProgressionRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = User::factory()->create(['timezone' => 'America/Bogota']);
        $this->app->instance(AiProvider::class, new FakeAiProvider);
    }

    private function read(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->getJson('/api/progression/recommendations');
    }

    /** A decision request the server refuses on its merits (422): it still counts against the limit. */
    private function decide(string $kind = 'approvals', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson("/api/progression/{$kind}", ['proposal' => 'not-a-real-proposal']);
    }

    private function chat(int $conversation, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson("/api/conversations/{$conversation}/messages", ['content' => 'hi']);
    }

    private function conversation(?User $as = null): int
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
    }

    // ---- each limit bites at its number ---------------------------------------------------------------

    public function test_reading_is_limited_to_30_a_minute_per_user(): void
    {
        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }

        $this->read()->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_accepting_and_keeping_share_a_limit_of_20_a_minute(): void
    {
        foreach (range(1, 10) as $i) {
            $this->decide('approvals')->assertStatus(422);
            $this->decide('rejections')->assertStatus(422);
        }

        $this->decide('approvals')->assertStatus(429);
        $this->decide('rejections')->assertStatus(429);
    }

    public function test_the_limits_reset_after_a_minute(): void
    {
        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }
        $this->read()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->read()->assertOk();
    }

    // ---- isolation -------------------------------------------------------------------------------------------

    public function test_reviewing_suggestions_cannot_use_up_the_chat_budget(): void
    {
        $conversation = $this->conversation();

        // A heavy review: every read the limit allows, and every decision it allows.
        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }
        foreach (range(1, 20) as $i) {
            $this->decide()->assertStatus(422);
        }

        // Chat still has its whole budget of 10...
        foreach (range(1, 10) as $i) {
            $this->chat($conversation)->assertCreated();
        }

        // ...and its own protection is unchanged: the 11th is refused.
        $this->chat($conversation)->assertStatus(429);
    }

    public function test_using_up_the_chat_budget_does_not_block_reviewing_suggestions(): void
    {
        $conversation = $this->conversation();

        foreach (range(1, 10) as $i) {
            $this->chat($conversation)->assertCreated();
        }
        $this->chat($conversation)->assertStatus(429);

        $this->read()->assertOk();
        $this->decide()->assertStatus(422);
    }

    public function test_weekly_adaptation_budget_is_not_touched_either(): void
    {
        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }

        // The weekly page's own limiter (10 a minute, shared with chat as before) is still fully available.
        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer on Friday.'])->assertStatus(200);
    }

    public function test_reading_and_deciding_have_separate_budgets(): void
    {
        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }
        $this->read()->assertStatus(429);
        $this->decide()->assertStatus(422);

        $this->travel(61)->seconds();
        foreach (range(1, 20) as $i) {
            $this->decide()->assertStatus(422);
        }
        $this->decide()->assertStatus(429);
        $this->read()->assertOk();
    }

    public function test_one_user_cannot_use_up_another_users_budget(): void
    {
        $other = User::factory()->create();

        foreach (range(1, 30) as $i) {
            $this->read()->assertOk();
        }
        $this->read()->assertStatus(429);

        $this->read($other)->assertOk();
        $this->decide('approvals', $other)->assertStatus(422);
    }

    // ---- wiring ----------------------------------------------------------------------------------------------------

    public function test_the_routes_use_the_named_limiters_and_chat_keeps_its_own(): void
    {
        $limits = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'progression') || str_contains($r->uri(), 'conversations/{conversation}/messages'))
            ->mapWithKeys(fn ($r) => [$r->methods()[0].' '.$r->uri() => collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle'))->values()->all()])
            ->all();

        $this->assertSame(['throttle:progression-read'], $limits['GET api/progression/recommendations']);
        $this->assertSame(['throttle:progression-decision'], $limits['POST api/progression/approvals']);
        $this->assertSame(['throttle:progression-decision'], $limits['POST api/progression/rejections']);
        $this->assertSame(['throttle:10,1'], $limits['POST api/conversations/{conversation}/messages'], 'Chat protection is exactly as it was.');
    }
}
