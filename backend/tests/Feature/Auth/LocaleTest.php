<?php

namespace Tests\Feature\Auth;

use App\Models\FitnessProfile;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Support\AppLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The language preference (users.locale): where it is stored, who can change it, and that it only ever
 * chooses wording. Synthetic users only.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $extra = [], array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/auth/register', array_merge([
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $extra));
    }

    // ---- defaults ---------------------------------------------------------------------------------

    public function test_an_existing_user_defaults_to_english(): void
    {
        // A row as it existed before the column: created without naming a locale.
        DB::table('users')->insert(['name' => 'Old', 'email' => 'old@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::where('email', 'old@example.com')->firstOrFail();

        $this->assertSame('en', $user->locale);
        $this->actingAs($user, 'sanctum')->getJson('/api/me')->assertOk()->assertJsonPath('locale', 'en');
    }

    public function test_a_new_user_defaults_to_english(): void
    {
        $this->register()->assertCreated()->assertJsonPath('user.locale', 'en');

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'locale' => 'en']);
    }

    public function test_registration_saves_the_language_chosen_on_the_sign_up_screen(): void
    {
        $this->register(['locale' => 'es'])->assertCreated()->assertJsonPath('user.locale', 'es');

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'locale' => 'es']);
    }

    public function test_registration_ignores_the_ui_header_for_what_it_saves(): void
    {
        // Only the form field is the user's explicit choice; a header only words this response.
        $this->register([], [AppLocale::HEADER => 'es'])->assertCreated()->assertJsonPath('user.locale', 'en');
    }

    public function test_a_null_locale_on_registration_means_english(): void
    {
        $this->register(['locale' => null])->assertCreated()->assertJsonPath('user.locale', 'en');
    }

    /** @return array<string, array{0: mixed}> */
    public static function unsupportedLocales(): array
    {
        return [
            'another language' => ['fr'],
            'upper case' => ['ES'],
            'region tag' => ['es-CO'],
            'region tag underscore' => ['es_CO'],
            'empty' => [''],
            'number' => [123],
            'array' => [['es']],
            'long' => [str_repeat('e', 40)],
            'injection' => ["es'; drop table users;--"],
        ];
    }

    #[DataProvider('unsupportedLocales')]
    public function test_registration_rejects_an_unsupported_locale(mixed $value): void
    {
        if ($value === '') {
            // Laravel turns an empty string into null for optional fields, which means "not chosen".
            $this->register(['locale' => $value])->assertCreated()->assertJsonPath('user.locale', 'en');

            return;
        }

        $this->register(['locale' => $value])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    // ---- updating ---------------------------------------------------------------------------------

    public function test_a_user_without_a_fitness_profile_can_change_language(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->fitnessProfile);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => 'es'])
            ->assertOk()->assertJsonPath('locale', 'es');

        $this->assertSame('es', $user->fresh()->locale);
        $this->assertSame(0, FitnessProfile::count(), 'changing language must not create a profile');
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/me')->assertJsonPath('locale', 'es');
    }

    public function test_every_supported_value_can_be_saved_and_read_back(): void
    {
        $user = User::factory()->create();

        foreach (['es', 'en', 'es'] as $value) {
            $this->actingAs($user->fresh(), 'sanctum')->putJson('/api/me/locale', ['locale' => $value])->assertOk()->assertJsonPath('locale', $value);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => $value]);
        }
    }

    #[DataProvider('unsupportedLocales')]
    public function test_an_unsupported_locale_is_rejected_with_422_and_changes_nothing(mixed $value): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('locale');

        $this->assertSame('es', $user->fresh()->locale);
    }

    public function test_a_missing_or_null_locale_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', [])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => null])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_changing_language_needs_authentication(): void
    {
        $this->putJson('/api/me/locale', ['locale' => 'es'])->assertUnauthorized();
    }

    public function test_the_update_only_changes_the_locale_even_if_other_fields_are_sent(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Bogota', 'name' => 'Ana']);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', [
            'locale' => 'es', 'name' => 'Mallory', 'email' => 'mallory@example.com', 'timezone' => 'Asia/Tokyo', 'password' => 'hacked-password',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('es', $fresh->locale);
        $this->assertSame('Ana', $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame('America/Bogota', $fresh->timezone);
        $this->assertSame($user->password, $fresh->password);
    }

    // ---- persistence and the sign-in screen ------------------------------------------------------

    public function test_the_saved_language_survives_logout_and_login(): void
    {
        $this->register(['locale' => 'es'])->assertCreated();

        $login = fn (array $extra = [], array $headers = []) => $this->withHeaders($headers)->postJson('/api/auth/login', array_merge([
            'email' => 'ana@example.com', 'password' => 'password123',
        ], $extra));

        $this->assertSame('es', $login()->assertOk()->json('user.locale'));
        $this->assertSame('es', User::where('email', 'ana@example.com')->value('locale'));
    }

    public function test_a_language_picked_on_the_sign_in_screen_never_replaces_the_saved_one(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'password' => 'password123', 'locale' => 'es']);
        User::factory()->create(['email' => 'ben@example.com', 'password' => 'password123', 'locale' => 'en']);

        // Both the form field and the header carry a guest choice; neither is persisted by login.
        $this->withHeaders([AppLocale::HEADER => 'en'])->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'password123', 'locale' => 'en'])
            ->assertOk()->assertJsonPath('user.locale', 'es');
        $this->withHeaders([AppLocale::HEADER => 'es'])->postJson('/api/auth/login', ['email' => 'ben@example.com', 'password' => 'password123', 'locale' => 'es'])
            ->assertOk()->assertJsonPath('user.locale', 'en');

        $this->assertSame('es', User::where('email', 'ana@example.com')->value('locale'));
        $this->assertSame('en', User::where('email', 'ben@example.com')->value('locale'));
    }

    public function test_an_unrecognised_stored_value_reads_as_english(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['locale' => 'xx']);

        $this->assertSame(AppLocale::English, $user->fresh()->appLocale());
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/me')->assertJsonPath('locale', 'en');
    }

    // ---- isolation --------------------------------------------------------------------------------

    public function test_one_users_language_never_reaches_another_user(): void
    {
        $ana = User::factory()->create(['locale' => 'en']);
        $ben = User::factory()->create(['locale' => 'en']);

        $this->actingAs($ana, 'sanctum')->putJson('/api/me/locale', ['locale' => 'es'])->assertOk();

        $this->assertSame('en', $ben->fresh()->locale);
        $this->actingAs($ben->fresh(), 'sanctum')->getJson('/api/me')->assertJsonPath('locale', 'en')->assertJsonMissing(['id' => $ana->id]);
        $this->actingAs($ana->fresh(), 'sanctum')->getJson('/api/me')->assertJsonPath('locale', 'es');
    }

    public function test_changing_language_touches_nothing_but_the_locale(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Bogota']);
        FitnessProfile::factory()->for($user)->create(['coach_style' => 'intense', 'response_detail' => 'concise']);
        $plan = WorkoutPlan::factory()->for($user)->create(['planned_date' => '2026-11-04']);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create(['exercise_name' => 'Bench press', 'position' => 0, 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 42.5]);

        $snapshot = fn () => [
            DB::table('fitness_profiles')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('workout_plans')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('workout_plan_exercises')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            $user->fresh()->timezone,
        ];
        $before = $snapshot();

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => 'es'])->assertOk();
        $this->actingAs($user->fresh(), 'sanctum')->putJson('/api/me/locale', ['locale' => 'en'])->assertOk();

        $this->assertSame($before, $snapshot(), 'profile (coach style/detail), plan dates, exercise slugs, loads and timezone are unchanged');
        $this->assertSame('bench-press', WorkoutPlanExercise::first()->exercise_slug);
    }

    // ---- the per-request language -----------------------------------------------------------------

    private function probe(): void
    {
        Route::middleware('api')->get('/api/_locale-probe', fn () => response()->json(['locale' => app()->getLocale()]));
        Route::middleware('api')->get('/api/_locale-boom', function () {
            throw new \RuntimeException('boom '.app()->getLocale());
        });
    }

    public function test_a_guest_is_answered_in_english_unless_a_supported_language_is_named(): void
    {
        $this->probe();

        $this->getJson('/api/_locale-probe')->assertJsonPath('locale', 'en');
        $this->getJson('/api/_locale-probe', [AppLocale::HEADER => 'es'])->assertJsonPath('locale', 'es');
        $this->getJson('/api/_locale-probe', [AppLocale::HEADER => 'en'])->assertJsonPath('locale', 'en');
    }

    public function test_only_an_exact_explicit_value_counts_and_nothing_is_inferred(): void
    {
        $this->probe();

        foreach (['ES', 'es-CO', 'es_419', 'fr', 'de', '*', 'es,en', ''] as $bad) {
            $this->getJson('/api/_locale-probe', [AppLocale::HEADER => $bad])->assertJsonPath('locale', 'en');
        }

        // The browser's language, and where the request seems to come from, are not an explicit choice.
        $this->getJson('/api/_locale-probe', ['Accept-Language' => 'es-CO,es;q=0.9'])->assertJsonPath('locale', 'en');
        $this->withServerVariables(['REMOTE_ADDR' => '190.85.0.1'])->getJson('/api/_locale-probe', ['CF-IPCountry' => 'CO'])->assertJsonPath('locale', 'en');
    }

    public function test_a_signed_in_user_is_answered_in_their_saved_language_and_a_header_cannot_override_it(): void
    {
        $this->probe();
        $es = User::factory()->create(['locale' => 'es']);
        $en = User::factory()->create(['locale' => 'en']);

        $this->actingAs($es, 'sanctum')->getJson('/api/_locale-probe')->assertJsonPath('locale', 'es');
        $this->actingAs($es, 'sanctum')->getJson('/api/_locale-probe', [AppLocale::HEADER => 'en'])->assertJsonPath('locale', 'es');
        $this->actingAs($en, 'sanctum')->getJson('/api/_locale-probe', [AppLocale::HEADER => 'es'])->assertJsonPath('locale', 'en');
    }

    public function test_a_real_token_is_resolved_to_its_own_user(): void
    {
        $this->probe();
        $es = User::factory()->create(['locale' => 'es']);
        $token = $es->createToken('t')->plainTextToken;

        $this->getJson('/api/_locale-probe', ['Authorization' => 'Bearer '.$token])->assertJsonPath('locale', 'es');
        // A bad token is a guest: the header decides. (A fresh guard per request, as in production.)
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/_locale-probe', ['Authorization' => 'Bearer nope', AppLocale::HEADER => 'es'])->assertJsonPath('locale', 'es');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/_locale-probe', ['Authorization' => 'Bearer nope'])->assertJsonPath('locale', 'en');
    }

    public function test_the_language_is_put_back_after_every_request_so_nothing_leaks(): void
    {
        $this->probe();
        $es = User::factory()->create(['locale' => 'es']);
        $en = User::factory()->create(['locale' => 'en']);

        app()->setLocale('en');
        // Interleaved requests from different users, as a worker would serve them one after another.
        foreach ([$es, $en, $es, $en, null, $es] as $actor) {
            $request = $actor ? $this->actingAs($actor, 'sanctum') : $this->withoutToken();
            $expected = $actor ? $actor->locale : 'en';
            $request->getJson('/api/_locale-probe')->assertJsonPath('locale', $expected);

            $this->assertSame('en', app()->getLocale(), 'the request language must not outlive the request');
        }

        $this->getJson('/api/_locale-probe', [AppLocale::HEADER => 'es'])->assertJsonPath('locale', 'es');
        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_language_is_restored_even_when_a_request_throws(): void
    {
        $this->probe();
        $es = User::factory()->create(['locale' => 'es']);

        app()->setLocale('en');
        $this->actingAs($es, 'sanctum')->getJson('/api/_locale-boom')->assertStatus(500);

        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_language_is_restored_after_a_validation_error(): void
    {
        $es = User::factory()->create(['locale' => 'es']);

        app()->setLocale('en');
        $this->actingAs($es, 'sanctum')->putJson('/api/me/locale', ['locale' => 'fr'])->assertUnprocessable();

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('es', $es->fresh()->locale);
    }

    public function test_english_is_the_fallback_language(): void
    {
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertSame('en', config('app.locale'));
        $this->assertSame(['en', 'es'], AppLocale::values());
    }
}
