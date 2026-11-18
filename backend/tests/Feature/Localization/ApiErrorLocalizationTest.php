<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\ImageGenerator;
use App\Support\ApiErrorLocalizer;
use App\Support\ApiMessages;
use App\Support\ApplicationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeImageGenerator;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

/**
 * Phase 7 / M3c: the API's own messages in English and Spanish.
 *
 * The contract under test: the language changes the WORDS of `message` and nothing else. The same request gives the
 * same HTTP status, the same headers, the same JSON keys and the same machine-readable code in both languages;
 * English is byte-for-byte what it always was; and the words follow the saved account language for a signed-in user
 * and the explicit, validated header for a guest. Synthetic users, fake providers, no network.
 */
class ApiErrorLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $locale): User
    {
        return User::factory()->create(['locale' => $locale, 'timezone' => 'America/Bogota']);
    }

    /** Runs the same request once per language and returns [en, es]. */
    private function both(callable $request): array
    {
        return [$request('en'), $request('es')];
    }

    /** Status, header names, JSON key shape and any machine-readable code are the same; only `message` differs. */
    private function assertSameContract(TestResponse $en, TestResponse $es, bool $messagesDiffer = true): void
    {
        $this->assertSame($en->getStatusCode(), $es->getStatusCode(), 'Same HTTP status.');

        $headers = fn (TestResponse $r) => collect($r->headers->all())->keys()->reject(fn ($h) => in_array($h, ['date', 'content-length', 'x-request-id', 'x-ratelimit-reset', 'retry-after', 'set-cookie'], true))->sort()->values()->all();
        $this->assertSame($headers($en), $headers($es), 'Same response headers.');
        $this->assertSame($en->headers->has('Retry-After'), $es->headers->has('Retry-After'), 'Retry-After is kept.');

        $keys = fn (TestResponse $r) => array_keys((array) $r->json());
        $this->assertSame($keys($en), $keys($es), 'Same JSON keys, in the same order.');

        foreach (['code', 'status', 'image_url'] as $stable) {
            $this->assertSame($en->json($stable), $es->json($stable), "`{$stable}` never changes with the language.");
        }

        if ($messagesDiffer) {
            $this->assertIsString($en->json('message'));
            $this->assertIsString($es->json('message'));
            $this->assertNotSame($en->json('message'), $es->json('message'), 'The words are in the language of the request.');
            $this->assertNotSame('', $es->json('message'), 'A Spanish message is never empty.');
        }
    }

    // ---- 401 / 403 / 404 / 405 / 429 / 500 -------------------------------------------------------------------

    public function test_unauthenticated_requests_use_the_guest_header_and_stay_401(): void
    {
        [$en, $es] = $this->both(fn (string $lang) => $this->withHeaders(['X-App-Locale' => $lang])->getJson('/api/me'));

        $en->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
        $es->assertStatus(401)->assertExactJson(['message' => 'No has iniciado sesión.']);
        $this->assertSameContract($en, $es);
    }

    public function test_a_guest_without_a_valid_header_gets_english(): void
    {
        foreach ([[], ['X-App-Locale' => 'fr'], ['X-App-Locale' => 'ES'], ['X-App-Locale' => 'es-CO'], ['Accept-Language' => 'es']] as $headers) {
            $this->withHeaders($headers)->getJson('/api/me')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_a_rejected_token_is_worded_by_the_guest_header_not_by_anything_in_the_token(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token', 'X-App-Locale' => 'es'])->getJson('/api/me')
            ->assertStatus(401)->assertJsonPath('message', 'No has iniciado sesión.');
    }

    public function test_forbidden_is_localized_and_still_403(): void
    {
        config(['app.debug' => false]); // as in production: the framework words a 403 by its status text
        $plan = WorkoutPlan::factory()->for($this->user('en'))->create();
        $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create();

        [$en, $es] = $this->both(function (string $lang) use ($plan, $exercise) {
            return $this->actingAs($this->user($lang), 'sanctum')->postJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/image");
        });

        $en->assertStatus(403);
        // Untouched English: an abort(403) carries no text of its own, so the framework's message is empty.
        $this->assertSame('', $en->json('message'));
        $this->assertSame('No tienes permiso para hacer esto.', $es->json('message'));
        $this->assertSameContract($en, $es);
    }

    public function test_not_found_never_exposes_a_model_class_name_in_spanish(): void
    {
        [$en, $es] = $this->both(fn (string $lang) => $this->actingAs($this->user($lang), 'sanctum')->getJson('/api/conversations/999999/messages'));

        $en->assertStatus(404);
        $this->assertSame('No se encontró lo que buscas.', $es->json('message'));
        $this->assertStringNotContainsString('App\\', $es->getContent());
        $this->assertSameContract($en, $es);
    }

    public function test_unmatched_routes_are_deferred_and_stay_english(): void
    {
        // A URL that matches no route never reaches the language middleware: nothing says what language to use. Deferred.
        $this->withHeaders(['X-App-Locale' => 'es'])->getJson('/api/does-not-exist')->assertStatus(404);
        $this->assertStringNotContainsString('No se encontró', $this->withHeaders(['X-App-Locale' => 'es'])->getJson('/api/does-not-exist')->getContent());

        $wrongMethod = $this->withHeaders(['X-App-Locale' => 'es'])->deleteJson('/api/me');
        $wrongMethod->assertStatus(405);
        $this->assertStringNotContainsString('permitido', $wrongMethod->getContent());
    }

    public function test_the_login_rate_limit_is_localized_and_keeps_its_status_and_retry_after(): void
    {
        $hit = function (string $lang): TestResponse {
            $response = null;
            // 5 attempts are allowed a minute; the 6th is throttled. Each language has its own client address.
            for ($i = 0; $i < 6; $i++) {
                $response = $this->withServerVariables(['REMOTE_ADDR' => $lang === 'en' ? '10.1.1.1' : '10.1.1.2'])
                    ->withHeaders(['X-App-Locale' => $lang])
                    ->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
            }

            return $response;
        };

        [$en, $es] = $this->both($hit);

        $en->assertStatus(429)->assertHeader('Retry-After');
        $es->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame('Too Many Attempts.', $en->json('message'));
        $this->assertSame('Demasiados intentos. Inténtalo de nuevo en un momento.', $es->json('message'));
        $this->assertSameContract($en, $es);
    }

    public function test_an_unhandled_failure_is_a_500_with_a_localized_generic_message_and_no_internals(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/_m3c/boom', fn () => throw new \RuntimeException('secret table users_internal'));

        [$en, $es] = $this->both(fn (string $lang) => $this->withHeaders(['X-App-Locale' => $lang])->getJson('/api/_m3c/boom'));

        $en->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $es->assertStatus(500)->assertExactJson(['message' => 'Error del servidor. Inténtalo de nuevo en un momento.']);
        $this->assertStringNotContainsString('secret', $es->getContent());
        $this->assertSameContract($en, $es);
    }

    public function test_debug_diagnostics_are_left_alone_in_a_500(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/_m3c/boom', fn () => throw new \RuntimeException('developer detail'));

        $this->withHeaders(['X-App-Locale' => 'es'])->getJson('/api/_m3c/boom')->assertStatus(500)->assertJsonPath('message', 'developer detail');
    }

    // ---- auth and controllers ----------------------------------------------------------------------------------

    public function test_login_failure_is_a_422_with_the_same_field_key(): void
    {
        $this->user('en');
        [$en, $es] = $this->both(fn (string $lang) => $this->withHeaders(['X-App-Locale' => $lang])->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']));

        $en->assertStatus(422)->assertExactJson(['message' => 'These credentials do not match our records.', 'errors' => ['email' => ['These credentials do not match our records.']]]);
        $es->assertStatus(422)->assertExactJson(['message' => 'Estas credenciales no coinciden con nuestros registros.', 'errors' => ['email' => ['Estas credenciales no coinciden con nuestros registros.']]]);
    }

    public function test_the_login_language_is_the_guest_header_then_the_saved_language_afterwards(): void
    {
        // A guest has only the explicit header...
        $this->withHeaders(['X-App-Locale' => 'es'])->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');

        // ...a signed-in user is answered in the language saved on the account, whatever header the client sends.
        $spanish = $this->user('es')->createToken('api')->plainTextToken;
        $this->withToken($spanish)->withHeaders(['X-App-Locale' => 'en'])->postJson('/api/auth/logout')
            ->assertOk()->assertExactJson(['message' => 'Sesión cerrada.']);

        $this->app['auth']->forgetGuards(); // a second request, as a second client
        $english = $this->user('en')->createToken('api')->plainTextToken;
        $this->withToken($english)->withHeaders(['X-App-Locale' => 'es'])->postJson('/api/auth/logout')
            ->assertOk()->assertExactJson(['message' => 'Logged out.']);
    }

    public function test_a_busy_conversation_is_a_localized_409(): void
    {
        $makeBusy = function (string $lang) {
            $user = $this->user($lang);
            $conversation = $user->conversations()->create(['title' => 'x']);
            $lock = Cache::lock("conversation-turn:{$conversation->id}", 30);
            $this->assertTrue($lock->get());

            return $this->actingAs($user, 'sanctum')->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hi']);
        };

        [$en, $es] = $this->both($makeBusy);

        $en->assertStatus(409)->assertExactJson(['message' => 'Your coach is still working on your previous message — please wait for the reply first.']);
        $es->assertStatus(409)->assertExactJson(['message' => 'Tu coach sigue trabajando en tu mensaje anterior; espera primero su respuesta.']);
    }

    public function test_exercise_demonstration_errors_are_localized_and_keep_their_codes_and_statuses(): void
    {
        $this->app->instance(ImageGenerator::class, new FakeImageGenerator);
        config(['filesystems.disks.exercise-images' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/exercise-images')]]);
        Storage::fake('exercise-images');

        $request = function (string $lang) {
            $user = $this->user($lang);
            $plan = WorkoutPlan::factory()->for($user)->create();
            $exercise = WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => '???']);

            return $this->actingAs($user, 'sanctum')->postJson("/api/workout-plans/{$plan->id}/exercises/{$exercise->id}/image");
        };

        [$en, $es] = $this->both($request);

        $en->assertStatus(422)->assertJsonPath('status', 'unavailable')->assertJsonPath('image_url', null);
        $this->assertSame('No demonstration is available for this exercise.', $en->json('message'));
        $this->assertSame('No hay una demostración disponible para este ejercicio.', $es->json('message'));
        $this->assertSameContract($en, $es);
    }

    // ---- AI provider failures ----------------------------------------------------------------------------------

    public static function providerFailures(): array
    {
        return [
            'not configured' => [null, 'ai_not_configured', 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.', 'El coach de IA aún no está configurado. Define AI_API_KEY en el archivo .env del backend.'],
            'rate limited' => [429, 'ai_rate_limited', 'Your coach is at its request limit for now — please wait a moment and try again.', 'Tu coach alcanzó su límite de solicitudes por ahora; espera un momento e inténtalo de nuevo.'],
            'unavailable' => [500, 'ai_unavailable', 'The AI coach is temporarily unavailable — please try again in a moment.', 'El coach de IA no está disponible por el momento; inténtalo de nuevo en un momento.'],
        ];
    }

    /** @dataProvider providerFailures */
    #[DataProvider('providerFailures')]
    public function test_a_provider_failure_is_a_503_with_a_stable_code_and_the_established_message(?int $status, string $code, string $english, string $spanish): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider($status));

        $send = function (string $lang) {
            $user = $this->user($lang);
            $conversation = $user->conversations()->create(['title' => 'x']);

            return $this->actingAs($user, 'sanctum')->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hello']);
        };

        [$en, $es] = $this->both($send);

        $en->assertStatus(503)->assertExactJson(['message' => $english, 'code' => $code]);
        $es->assertStatus(503)->assertExactJson(['message' => $spanish, 'code' => $code]);
        $this->assertSameContract($en, $es);

        // The same wording and code from the other AI endpoints.
        $weekly = $this->actingAs($this->user('es'), 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'make it lighter']);
        $weekly->assertStatus(503)->assertExactJson(['message' => $spanish, 'code' => $code]);

        $summary = $this->actingAs($this->user('en'), 'sanctum')->getJson('/api/coach/weekly-summary');
        $summary->assertStatus(503)->assertExactJson(['message' => $english, 'code' => $code]);
    }

    public function test_the_provider_reason_codes_come_from_the_reason_not_the_words(): void
    {
        foreach ([['not_configured', 'ai_not_configured'], ['rate_limited', 'ai_rate_limited'], ['unavailable', 'ai_unavailable'], ['incomplete', 'ai_unavailable'], ['invalid_image', 'ai_unavailable']] as [$reason, $code]) {
            $e = new AiProviderException('some provider detail', reason: $reason);
            $this->assertSame($code, ApiMessages::providerCode($e->userFacingReason()));
        }

        $this->assertSame('ai_rate_limited', ApiMessages::providerCode((new AiProviderException('x', statusCode: 429))->userFacingReason()));
    }

    public function test_an_unusable_application_key_is_a_localized_503_with_its_existing_code(): void
    {
        config(['app.key' => '']);
        ApplicationKey::resetReporting();

        [$en, $es] = $this->both(fn (string $lang) => $this->actingAs($this->user($lang), 'sanctum')->getJson('/api/progression/recommendations'));

        $en->assertStatus(503)->assertExactJson(['code' => 'progression_unavailable', 'message' => 'Progression suggestions are temporarily unavailable. Please try again later.']);
        $es->assertStatus(503)->assertExactJson(['code' => 'progression_unavailable', 'message' => 'Las sugerencias de progresión no están disponibles por el momento. Inténtalo de nuevo más tarde.']);
    }

    // ---- validation --------------------------------------------------------------------------------------------

    public function test_validation_is_a_422_with_the_same_field_keys_and_structure_in_both_languages(): void
    {
        $payload = ['weight_kg' => 'abc', 'height_cm' => 5000, 'age' => 'old'];

        [$en, $es] = $this->both(fn (string $lang) => $this->actingAs($this->user($lang), 'sanctum')->putJson('/api/profile', $payload));

        $en->assertStatus(422);
        $es->assertStatus(422);
        $this->assertSame(array_keys($en->json('errors')), array_keys($es->json('errors')), 'Same field keys.');
        $this->assertSame(
            array_map('count', $en->json('errors')),
            array_map('count', $es->json('errors')),
            'Same number of messages per field.',
        );
        $this->assertSameContract($en, $es);

        // English is the framework's own wording, unchanged; Spanish reads in Spanish with its field labels.
        $this->assertSame('The weight kg field must be a number.', $en->json('errors.weight_kg.0'));
        $this->assertSame('El campo peso (kg) debe ser un número.', $es->json('errors.weight_kg.0'));
        $this->assertStringContainsString('estatura (cm)', implode(' ', $es->json('errors.height_cm')));
    }

    public function test_the_validation_summary_counts_the_remaining_errors_in_the_request_language(): void
    {
        $payload = ['weight_kg' => 'abc', 'height_cm' => 5000, 'age' => 'old'];

        [$en, $es] = $this->both(fn (string $lang) => $this->actingAs($this->user($lang), 'sanctum')->putJson('/api/profile', $payload));

        $this->assertMatchesRegularExpression('/\(and \d+ more errors?\)$/', $en->json('message'));
        $this->assertMatchesRegularExpression('/\(y \d+ errores? más\)$/u', $es->json('message'));
    }

    public function test_the_summary_pluralises_one_more_error_and_many(): void
    {
        $suffix = function (array $payload) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($this->user('es'), 'sanctum')->putJson('/api/profile', $payload)->assertStatus(422);
            $more = count(Arr::flatten($response->json('errors'))) - 1;

            return [$more, $response->json('message')];
        };

        // Different payloads give different numbers of messages; whatever the count, the suffix agrees with it.
        $seen = [];
        foreach ([['sex' => 'x'], ['sex' => 'x', 'fitness_level' => 'x'], ['sex' => 'x', 'fitness_level' => 'x', 'primary_goal' => ['a']]] as $payload) {
            [$more, $message] = $suffix($payload);
            $seen[] = $more;

            if ($more === 0) {
                $this->assertStringNotContainsString('(y ', $message, 'One error: no suffix.');
            } elseif ($more === 1) {
                $this->assertStringEndsWith('(y 1 error más)', $message);
            } else {
                $this->assertStringEndsWith("(y {$more} errores más)", $message);
            }
        }

        $this->assertContains(0, $seen);
        $this->assertContains(1, $seen);
        $this->assertGreaterThan(1, max($seen));
    }

    public function test_validation_pluralisation_and_parameters_are_worded_in_spanish(): void
    {
        $es = $this->actingAs($this->user('es'), 'sanctum')->putJson('/api/profile', ['age' => 5, 'height_cm' => 400, 'primary_goal' => str_repeat('x', 300)]);

        $messages = implode(' | ', array_merge(...array_values($es->json('errors'))));
        $this->assertStringNotContainsString('field', $messages, 'No English left in the messages: '.$messages);
        $this->assertMatchesRegularExpression('/:?\d+/', $messages, 'The rule parameters (limits) are filled in.');
    }

    public function test_the_two_custom_request_messages_come_from_the_catalog(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $user = $this->user('es');

        $this->actingAs($user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => 'abc', 'load_kg' => 100])
            ->assertStatus(422)->assertJsonPath('errors.proposal.0', 'Solo se puede enviar la propuesta.');
        $this->actingAs($this->user('en'), 'sanctum')->postJson('/api/progression/approvals', ['proposal' => 'abc', 'load_kg' => 100])
            ->assertStatus(422)->assertJsonPath('errors.proposal.0', 'Only the proposal can be submitted.');
    }

    public function test_a_service_raised_validation_sentence_is_said_in_the_request_language_and_an_unknown_one_stays_as_it_was(): void
    {
        $known = ValidationException::withMessages([
            'planned_date' => 'This day already has recorded workout performance, so its plan can no longer be replaced.',
            'changes.0.date' => 'Date 2026-11-02 is outside the current week (2026-11-09 to 2026-11-15).',
            'other' => 'Some sentence this release has never seen.',
        ]);

        app()->setLocale('es');
        $response = ApiErrorLocalizer::localize(response()->json(['message' => $known->getMessage(), 'errors' => $known->errors()], 422), $known);
        app()->setLocale('en');

        $errors = $response->getData(true)['errors'];
        $this->assertSame('Este día ya tiene un entrenamiento registrado, por lo que su plan ya no se puede reemplazar.', $errors['planned_date'][0]);
        $this->assertSame('La fecha 2026-11-02 está fuera de la semana actual (2026-11-09 a 2026-11-15).', $errors['changes.0.date'][0]);
        $this->assertSame('Some sentence this release has never seen.', $errors['other'][0], 'An unrecognised sentence is never guessed at.');
        $this->assertStringStartsWith('Este día ya tiene', $response->getData(true)['message']);
        $this->assertStringEndsWith('(y 2 errores más)', $response->getData(true)['message']);
    }

    public function test_english_responses_are_returned_exactly_as_built(): void
    {
        $original = response()->json(['message' => 'The given data was invalid.', 'errors' => ['x' => ['This day already has recorded workout performance, so its plan can no longer be replaced.']]], 422);
        $before = $original->getContent();

        $result = ApiErrorLocalizer::localize($original, ValidationException::withMessages(['x' => 'y']));

        $this->assertSame($before, $result->getContent());
    }
}
