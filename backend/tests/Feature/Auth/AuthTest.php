<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alonso',
            'email' => 'alonso@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'alonso@example.com')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'alonso@example.com']);
    }

    /**
     * Regression: every "today"/"now" computation for a user routes through
     * User::localNow()/localToday(), which reads this column — without it
     * being captured, every such user silently falls back to the server's
     * UTC clock, which is the exact root cause of a reported bug (an
     * activity logged in the evening in America/Bogota, UTC-5, was
     * misdated to the next day).
     */
    public function test_registration_captures_the_submitted_timezone(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alonso',
            'email' => 'alonso@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'America/Bogota',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'alonso@example.com', 'timezone' => 'America/Bogota']);
    }

    public function test_registration_without_a_timezone_leaves_it_null(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alonso',
            'email' => 'alonso@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'alonso@example.com', 'timezone' => null]);
    }

    public function test_registration_rejects_an_invalid_timezone(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alonso',
            'email' => 'alonso@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'Not/A_Real_Zone',
        ])->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    public function test_login_refreshes_the_users_timezone_so_travel_self_corrects(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'timezone' => 'UTC']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'timezone' => 'America/Bogota',
        ])->assertOk();

        $this->assertSame('America/Bogota', $user->fresh()->timezone);
    }

    public function test_login_without_a_timezone_leaves_the_stored_one_unchanged(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'timezone' => 'America/Bogota']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertSame('America/Bogota', $user->fresh()->timezone);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alonso',
            'email' => 'alonso@example.com',
            'password' => 'password123',
            'password_confirmation' => 'nope',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/activity')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_their_profile_via_me(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_repeated_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_repeated_registration_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', [
                'name' => 'Spammer',
                'email' => "spammer{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Spammer',
            'email' => 'spammer-overflow@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }
}
