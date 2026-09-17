<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

class ConversationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_conversation_lifecycle_over_http(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'Here is your plan for today.'));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        $conversationId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversations', [])
            ->assertCreated()
            ->json('id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'What should I do today?'])
            ->assertCreated()
            ->assertJsonPath('content', 'Here is your plan for today.');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_user_cannot_send_a_message_into_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $conversationId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/conversations', [])
            ->json('id');

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'hi'])
            ->assertForbidden();
    }

    public function test_sending_a_message_without_ai_configured_returns_a_clear_error_not_a_500(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider);

        $user = User::factory()->create();
        $conversationId = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->json('id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'hi'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.');
    }

    public function test_a_rate_limited_provider_returns_a_rate_limit_message_not_a_configuration_message(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider(statusCode: 429));

        $user = User::factory()->create();
        $conversationId = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->json('id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'hi'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Your coach is at its request limit for now — please wait a moment and try again.');
    }

    public function test_repeated_message_sends_are_rate_limited_to_protect_shared_ai_capacity(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(...array_fill(0, 10, new AiResponse(text: 'ok')));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();
        $conversationId = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->json('id');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/conversations/{$conversationId}/messages", ['content' => "message {$i}"])
                ->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversationId}/messages", ['content' => 'one too many'])
            ->assertStatus(429);
    }
}
