<?php

namespace Tests\Unit\Memory;

use App\Models\User;
use App\Services\Memory\DatabaseMemoryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseMemoryProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_stores_a_new_memory(): void
    {
        $user = User::factory()->create();

        app(DatabaseMemoryProvider::class)->remember($user, 'Prefers short workouts on football days.');

        $this->assertDatabaseHas('fitness_memories', [
            'user_id' => $user->id,
            'content' => 'Prefers short workouts on football days.',
        ]);
    }

    public function test_remember_skips_a_near_duplicate_of_an_existing_memory(): void
    {
        $user = User::factory()->create();
        $provider = app(DatabaseMemoryProvider::class);

        $provider->remember($user, 'Targets around 6-8k steps on average rather than strict 10k daily.');
        $provider->remember($user, 'Target average steps: 6-8k per day (does not need to hit 10k daily).');
        $provider->remember($user, 'User prefers 6-8k steps on average as a realistic goal.');

        $this->assertSame(1, $user->fitnessMemories()->count());
    }

    public function test_remember_still_stores_a_genuinely_different_preference(): void
    {
        $user = User::factory()->create();
        $provider = app(DatabaseMemoryProvider::class);

        $provider->remember($user, 'Targets around 6-8k steps on average rather than strict 10k daily.');
        $provider->remember($user, 'Occasionally does a Cindy-style workout, but not every session.');

        $this->assertSame(2, $user->fitnessMemories()->count());
    }

    public function test_remember_scopes_duplicate_detection_to_the_same_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $provider = app(DatabaseMemoryProvider::class);

        $provider->remember($userA, 'Targets around 6-8k steps on average rather than strict 10k daily.');
        $provider->remember($userB, 'Targets around 6-8k steps on average rather than strict 10k daily.');

        $this->assertSame(1, $userA->fitnessMemories()->count());
        $this->assertSame(1, $userB->fitnessMemories()->count());
    }
}
