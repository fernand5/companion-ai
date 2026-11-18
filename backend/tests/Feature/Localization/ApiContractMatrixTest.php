<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Support\ApplicationKey;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

/**
 * Phase 7 / M3d: the API's error contract, frozen, in both languages and for both guests and signed-in users.
 *
 * Every scenario below is run as an English and as a Spanish request. The status, the JSON keys, the field keys of an
 * `errors` array, the machine-readable `code` and the relevant headers are asserted against the table written here
 * (what the API answered before localization), and the English wording is asserted word for word. The Spanish
 * response must then differ from the English one in `message`/error text and in nothing else.
 *
 * Synthetic users, fake providers, no network.
 */
class ApiContractMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]); // as in production: no debug payload
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function user(string $locale): User
    {
        $this->app['auth']->forgetGuards();

        return User::factory()->create(['locale' => $locale, 'timezone' => 'America/Bogota']);
    }

    private function asUser(string $lang): static
    {
        return $this->actingAs($this->user($lang), 'sanctum');
    }

    /**
     * Each scenario: how to make the request in a language, then the frozen contract.
     * Contract: status, JSON keys (in order), error-array keys (or null), machine code (or null), English message
     * (exact string, or a prefix when it ends with "…"), headers that must be present.
     *
     * @return array<string, array{0: string, 1: int, 2: array<int, string>, 3: ?array<int, string>, 4: ?string, 5: string, 6: array<int, string>}>
     */
    public static function scenarios(): array
    {
        return [
            // name, method, status, keys, error keys, code, english message, headers
            'guest 401' => ['unauthenticated', 401, ['message'], null, null, 'Unauthenticated.', []],
            'auth 403' => ['forbidden', 403, ['message'], null, null, '', []],
            'auth 404 (model binding)' => ['not_found', 404, ['message'], null, null, 'No query results for model…', []],
            'auth 409 busy' => ['busy', 409, ['message'], null, null, 'Your coach is still working on your previous message — please wait for the reply first.', []],
            'guest 422 login' => ['login_failed', 422, ['message', 'errors'], ['email'], null, 'These credentials do not match our records.', []],
            'auth 422 profile validation' => ['profile_invalid', 422, ['message', 'errors'], ['weight_kg', 'age'], null, 'The weight kg field must be a number.…', []],
            'auth 422 custom request message' => ['only_proposal', 422, ['message', 'errors'], ['proposal'], null, 'Only the proposal can be submitted.', []],
            'auth 422 custom performance message' => ['set_needs_value', 422, ['message', 'errors'], ['sets.0'], null, 'Each set needs reps, weight or duration.', []],
            'auth 422 exercise demonstration' => ['demo_unavailable', 422, ['message', 'image_url', 'status'], null, null, 'No demonstration is available for this exercise.', []],
            'guest 429 throttle' => ['throttled', 429, ['message'], null, null, 'Too Many Attempts.', ['Retry-After', 'X-RateLimit-Limit']],
            'guest 500' => ['boom', 500, ['message'], null, null, 'Server Error', []],
            'auth 503 provider not configured' => ['provider_not_configured', 503, ['message', 'code'], null, 'ai_not_configured', 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.', []],
            'auth 503 provider rate limited' => ['provider_rate_limited', 503, ['message', 'code'], null, 'ai_rate_limited', 'Your coach is at its request limit for now — please wait a moment and try again.', []],
            'auth 503 provider unavailable' => ['provider_unavailable', 503, ['message', 'code'], null, 'ai_unavailable', 'The AI coach is temporarily unavailable — please try again in a moment.', []],
            'auth 503 weekly plan provider' => ['weekly_provider_unavailable', 503, ['message', 'code'], null, 'ai_unavailable', 'The AI coach is temporarily unavailable — please try again in a moment.', []],
            'auth 503 application key' => ['key_unusable', 503, ['code', 'message'], null, 'progression_unavailable', 'Progression suggestions are temporarily unavailable. Please try again later.', []],
            'auth 422 progression approval' => ['approval_invalid', 422, ['code', 'message'], null, 'invalid_proposal', 'That proposal is not valid. Refresh your recommendations and try again.', []],
            'auth 200 logout' => ['logout', 200, ['message'], null, null, 'Logged out.', []],
        ];
    }

    private function request(string $scenario, string $lang): TestResponse
    {
        return match ($scenario) {
            'unauthenticated' => $this->withHeaders(['X-App-Locale' => $lang])->getJson('/api/me'),

            'forbidden' => (function () use ($lang) {
                $owner = User::factory()->create();
                $plan = WorkoutPlan::factory()->for($owner)->create();
                $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

                return $this->asUser($lang)->postJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/image");
            })(),

            'not_found' => $this->asUser($lang)->getJson('/api/conversations/999999/messages'),

            'busy' => (function () use ($lang) {
                $user = $this->user($lang);
                $conversation = $user->conversations()->create(['title' => 'x']);
                Cache::lock("conversation-turn:{$conversation->id}", 30)->get();

                return $this->actingAs($user, 'sanctum')->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hi']);
            })(),

            'login_failed' => $this->withHeaders(['X-App-Locale' => $lang])->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']),

            'profile_invalid' => $this->asUser($lang)->putJson('/api/profile', ['weight_kg' => 'abc', 'age' => 'old']),

            'only_proposal' => $this->asUser($lang)->postJson('/api/progression/approvals', ['proposal' => 'abc', 'load_kg' => 100]),

            'set_needs_value' => (function () use ($lang) {
                $user = $this->user($lang);
                $plan = WorkoutPlan::factory()->for($user)->create();
                $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

                return $this->actingAs($user, 'sanctum')->putJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/performance", ['sets' => [[]]]);
            })(),

            'demo_unavailable' => (function () use ($lang) {
                $user = $this->user($lang);
                $plan = WorkoutPlan::factory()->for($user)->create();
                $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => '???']);

                return $this->actingAs($user, 'sanctum')->postJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/image");
            })(),

            'throttled' => (function () use ($lang) {
                $response = null;
                for ($i = 0; $i < 6; $i++) {
                    $response = $this->withServerVariables(['REMOTE_ADDR' => $lang === 'en' ? '10.9.0.1' : '10.9.0.2'])->withHeaders(['X-App-Locale' => $lang])
                        ->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
                }

                return $response;
            })(),

            'boom' => (function () use ($lang) {
                Route::middleware('api')->get('/api/_m3d/boom', fn () => throw new \RuntimeException('secret table users_internal'));

                return $this->withHeaders(['X-App-Locale' => $lang])->getJson('/api/_m3d/boom');
            })(),

            'provider_not_configured' => $this->provider(null, $lang),
            'provider_rate_limited' => $this->provider(429, $lang),
            'provider_unavailable' => $this->provider(500, $lang),

            'weekly_provider_unavailable' => (function () use ($lang) {
                $this->app->instance(AiProvider::class, new ThrowingAiProvider(500));

                return $this->asUser($lang)->postJson('/api/weekly-plan/adapt', ['input' => 'lighter week']);
            })(),

            'key_unusable' => (function () use ($lang) {
                config(['app.key' => '']);
                ApplicationKey::resetReporting();

                return $this->asUser($lang)->getJson('/api/progression/recommendations');
            })(),

            'approval_invalid' => $this->asUser($lang)->postJson('/api/progression/approvals', ['proposal' => 'not-a-proposal']),

            'logout' => $this->withToken($this->user($lang)->createToken('api')->plainTextToken)->postJson('/api/auth/logout'),
        };
    }

    private function provider(?int $status, string $lang): TestResponse
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider($status));
        $user = $this->user($lang);
        $conversation = $user->conversations()->create(['title' => 'x']);

        return $this->actingAs($user, 'sanctum')->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hello']);
    }

    /** @param array<int, string> $headers @param array<int, string>|null $errorKeys */
    #[DataProvider('scenarios')]
    public function test_the_contract_is_frozen_and_english_keeps_its_wording(string $scenario, int $status, array $keys, ?array $errorKeys, ?string $code, string $english, array $headers): void
    {
        $en = $this->request($scenario, 'en');
        $this->flush();
        $es = $this->request($scenario, 'es');

        foreach (['en' => $en, 'es' => $es] as $lang => $response) {
            $this->assertSame($status, $response->getStatusCode(), "{$lang}: status");
            $this->assertSame($keys, array_keys((array) $response->json()), "{$lang}: JSON keys, in order");

            if ($errorKeys !== null) {
                $this->assertSame($errorKeys, array_keys($response->json('errors')), "{$lang}: error-array keys");
                foreach ($response->json('errors') as $messages) {
                    $this->assertIsArray($messages);
                    $this->assertContainsOnly('string', $messages);
                }
            }

            $this->assertSame($code, $response->json('code'), "{$lang}: machine-readable code");
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), "{$lang}: content type");

            foreach ($headers as $header) {
                $this->assertTrue($response->headers->has($header), "{$lang}: header {$header} kept");
            }
        }

        // English: word for word what the API said before localization.
        $message = (string) $en->json('message');
        str_ends_with($english, '…')
            ? $this->assertStringStartsWith(rtrim($english, '…'), $message, 'English wording')
            : $this->assertSame($english, $message, 'English wording');

        // Spanish: the words differ, the rest does not.
        if ($english !== '') {
            $this->assertNotSame($message, (string) $es->json('message'));
        }
        $this->assertNotSame('', (string) $es->json('message'), 'A Spanish message is never empty.');
        $this->assertSame(self::withoutText($en->json()), self::withoutText($es->json()), 'Nothing but the text differs.');
        $this->assertSame($en->json('status'), $es->json('status'), 'status is a stable value, not wording.');
    }

    private function flush(): void
    {
        Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    // ---- 405 (a deferred case) and the whole-contract guard -------------------------------------------------

    public function test_a_wrong_method_is_a_405_in_both_languages_and_stays_english(): void
    {
        foreach (['en', 'es'] as $lang) {
            $response = $this->withHeaders(['X-App-Locale' => $lang])->deleteJson('/api/me');
            $response->assertStatus(405);
            $this->assertSame(['message'], array_keys($response->json()));
            $this->assertStringContainsString('method is not supported', $response->json('message'));
        }
    }

    public function test_no_error_response_discloses_internals_in_either_language(): void
    {
        Route::middleware('api')->get('/api/_m3d/boom2', fn () => throw new \RuntimeException('secret table users_internal in /var/www/app/Secret.php'));

        foreach (['en', 'es'] as $lang) {
            foreach ([
                $this->withHeaders(['X-App-Locale' => $lang])->getJson('/api/_m3d/boom2'),
                $this->asUser($lang)->getJson('/api/conversations/999999/messages'),
                $this->asUser($lang)->getJson('/api/exercises/999999/history'),
            ] as $response) {
                $body = $response->getContent();
                foreach (['trace', 'vendor/', '/var/www', 'users_internal', 'Illuminate\\', 'Symfony\\'] as $leak) {
                    $this->assertStringNotContainsString($leak, $body, "{$lang}: {$response->getStatusCode()} leaked {$leak}");
                }
                $this->assertArrayNotHasKey('exception', (array) $response->json());
                $this->assertArrayNotHasKey('file', (array) $response->json());
            }
        }

        $es404 = $this->asUser('es')->getJson('/api/conversations/999999/messages')->getContent();
        $this->assertStringNotContainsString('App\\Models', $es404, 'Spanish never names a model class (English non-debug has always shown it: unchanged).');
    }

    public function test_the_framework_validation_error_shape_is_unchanged_for_a_guest_in_both_languages(): void
    {
        foreach (['en', 'es'] as $lang) {
            $response = $this->withHeaders(['X-App-Locale' => $lang])->postJson('/api/auth/register', ['email' => 'not-an-email', 'password' => 'x']);
            $response->assertStatus(422);
            $this->assertSame(['message', 'errors'], array_keys($response->json()));
            $this->assertSame(['name', 'email', 'password'], array_keys($response->json('errors')));
        }
    }

    public function test_a_nested_request_for_another_language_does_not_leak_into_the_outer_one(): void
    {
        $outer = $this->user('es');
        $inner = null;

        Route::middleware(['api', 'auth:sanctum'])->get('/api/_m3d/nested', function () use (&$inner) {
            $before = app()->getLocale();
            // A second client, as a guest, in English (the guard is told to forget the outer user, as a second process would).
            app('auth')->forgetGuards();
            $nested = app(Kernel::class)->handle(Request::create('/api/auth/login', 'POST', ['email' => 'nobody@example.com', 'password' => 'wrong'], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_APP_LOCALE' => 'en']));
            $inner = [$before, $nested->getStatusCode(), json_decode($nested->getContent(), true)['errors']['email'][0], app()->getLocale()];

            return response()->json(['locale_after_nested' => app()->getLocale(), 'message' => __('errors.auth.logged_out')]);
        });

        $response = $this->actingAs($outer, 'sanctum')->getJson('/api/_m3d/nested')->assertOk();

        $this->assertSame('es', $inner[0], 'The outer request is Spanish before the nested one.');
        $this->assertSame(422, $inner[1]);
        $this->assertSame('These credentials do not match our records.', $inner[2], 'The nested guest is answered in English.');
        $this->assertSame('es', $inner[3], 'The nested request restored the outer language.');
        $this->assertSame('es', $response->json('locale_after_nested'));
        $this->assertSame('Sesión cerrada.', $response->json('message'));
        $this->assertSame('en', app()->getLocale());
    }

    /** The response with every string leaf replaced by its type, so only wording can differ. */
    private static function withoutText(mixed $value): mixed
    {
        return is_array($value) ? array_map(fn ($v) => self::withoutText($v), $value) : (is_string($value) ? 'string' : $value);
    }
}
