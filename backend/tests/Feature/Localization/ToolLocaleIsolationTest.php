<?php

namespace Tests\Feature\Localization;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use App\Support\ModelFacingLocale;
use App\Support\WeeklyPlanMessages;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3c: what the model reads stays English, what the person reads is in their language.
 *
 * The coach's tool calls run in English whatever the request's language (a validation error in Spanish would be a
 * different input to the same decision, and the application rebuilds its Spanish notices from the English wording of
 * a refusal). Only the tool call is pinned, the language is restored in `finally`, and the application's own
 * messages to the person are still said in their language. Fake provider, synthetic users, no network.
 */
class ToolLocaleIsolationTest extends PerformanceEvalCase
{
    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'America/Bogota'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
    }

    private function userWho(string $language): User
    {
        $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $language]);

        foreach ([9, 5, 2] as $ago) {
            $this->logSets($user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }

        foreach ([5, 6] as $offset) {
            app(WorkoutPlanService::class)->createOrReplace($user, [
                'planned_date' => now()->startOfWeek()->addDays($offset)->toDateString(), 'activity_type' => 'strength', 'title' => "Bench {$offset}",
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
            ]);
        }

        return $user;
    }

    private function toolCall(string $name, array $arguments): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: $name, arguments: $arguments)]);
    }

    private function lastToolResult(User $user): array
    {
        $message = Message::query()->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))->where('role', 'assistant')->latest('id')->firstOrFail();

        return $message->meta['tool_calls'][0]['result'];
    }

    // ---- the pin itself -----------------------------------------------------------------------------------

    public function test_the_pin_runs_in_english_and_puts_the_language_back(): void
    {
        app()->setLocale('es');

        $inside = ModelFacingLocale::english(fn () => app()->getLocale());

        $this->assertSame('en', $inside);
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', Carbon::now()->locale);
    }

    public function test_the_language_is_put_back_even_when_the_call_fails(): void
    {
        app()->setLocale('es');

        try {
            ModelFacingLocale::english(function () {
                $this->assertSame('en', app()->getLocale());
                throw new \RuntimeException('a tool blew up');
            });
            $this->fail('The failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('a tool blew up', $e->getMessage());
        }

        $this->assertSame('es', app()->getLocale(), 'A failure cannot leave the application in English.');
    }

    public function test_pins_nest_and_each_restores_what_was_there_before(): void
    {
        app()->setLocale('es');

        $seen = ModelFacingLocale::english(function () {
            $outer = app()->getLocale();
            app()->setLocale('es');
            $inner = ModelFacingLocale::english(fn () => app()->getLocale());

            return [$outer, $inner, app()->getLocale()];
        });

        $this->assertSame(['en', 'en', 'es'], $seen);
        $this->assertSame('es', app()->getLocale());
    }

    public function test_the_return_value_is_passed_through_unchanged(): void
    {
        app()->setLocale('es');
        $value = ['a' => ['error' => 'The x field is required.'], 'b' => 1.5];

        $this->assertSame($value, ModelFacingLocale::english(fn () => $value));
    }

    public function test_every_tool_call_and_confirmation_in_the_coach_services_goes_through_the_pin(): void
    {
        // The boundaries where the coach hands work to the tools and the replacer: none may run in the request's language.
        foreach (['Services/Ai/AiCoachService.php', 'Services/Ai/WeeklyPlanCoachService.php'] as $file) {
            $source = (string) file_get_contents(app_path($file));

            foreach (['$turn->call(', '$this->planReplacer->apply('] as $call) {
                $count = substr_count($source, $call);

                if ($count === 0) {
                    continue;
                }

                $this->assertSame($count, substr_count($source, 'ModelFacingLocale::english(fn () => '.$call), "{$file}: every {$call} must run inside ModelFacingLocale::english().");
            }
        }

        $this->assertSame(2, substr_count((string) file_get_contents(app_path('Services/Ai/AiCoachService.php')), 'ModelFacingLocale::english('), 'Pinned narrowly: the tool call and the confirmation, not the request.');
        $this->assertSame(1, substr_count((string) file_get_contents(app_path('Services/Ai/WeeklyPlanCoachService.php')), 'ModelFacingLocale::english('));
    }

    // ---- through the coach -------------------------------------------------------------------------------

    public function test_a_spanish_users_tool_error_is_english_for_the_model_and_the_trace(): void
    {
        $user = $this->userWho('es');
        $this->fake->queue(
            $this->toolCall('log_workout', ['exercises' => [['exercise_name' => 'Press de banca', 'sets' => 'tres', 'reps' => 8, 'weight_kg' => 40]]]),
            new AiResponse(text: 'No se pudo registrar.'),
        );

        $this->ask($user, 'Hoy hice press de banca, 8 repeticiones con 40 kg.');

        $error = $this->lastToolResult($user)['error'];
        $this->assertStringContainsString('must be an integer', $error, 'What the model reads is the English validation message.');
        $this->assertStringNotContainsString('número entero', $error);

        // And that is what the model was actually given on its next call.
        $toolMessage = collect(end($this->fake->calls)['messages'])->firstWhere('role', 'tool');
        $this->assertStringContainsString('must be an integer', json_encode($toolMessage['content']));
    }

    public function test_the_same_failure_outside_a_tool_call_is_worded_in_spanish(): void
    {
        // The control for the test above: the very same validation, in the request's language, over HTTP.
        $user = $this->userWho('es');

        $this->actingAs($user, 'sanctum')->postJson('/api/workouts', ['logged_date' => '2999-01-01', 'exercises' => [['exercise_name' => 'x', 'sets' => 1, 'reps' => 1]]])
            ->assertStatus(422)->assertJsonPath('errors.logged_date.0', 'La fecha de registro no puede estar en el futuro: esto registra algo que ya ocurrió. Las sesiones próximas van en el horario o en el plan.');
    }

    public function test_the_application_still_speaks_to_the_person_in_their_language_after_a_tool_runs(): void
    {
        $user = $this->userWho('es');
        $date = now()->startOfWeek()->addDays(5)->toDateString();

        // A weekly adaptation the load guard refuses: the refusal is English for the model...
        $this->fake->queue($this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'Ajustando.', 'reasoning_factors' => ['prueba'],
            'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Reescrito', 'reason' => 'prueba',
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 50]]]],
        ]));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Reorganiza mi semana.'])->assertOk()->json();

        // ...and Spanish for the person, from the same refusal: M3b's rebuilding from the English wording still works.
        $this->assertFalse($response['applied']);
        $this->assertStringContainsString('No se cambió nada en tu plan.', $response['error']);
        $this->assertStringContainsString('superaría', $response['error']);
        $this->assertStringNotContainsString('would raise its load', $response['error']);
    }

    public function test_an_english_user_still_gets_the_original_refusal_text(): void
    {
        $user = $this->userWho('en');
        $date = now()->startOfWeek()->addDays(5)->toDateString();
        $this->fake->queue($this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'Adjusting.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Rewritten', 'reason' => 'test',
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 50]]]],
        ]));

        $error = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Rework my week.'])->assertOk()->json('error');

        $this->assertStringStartsWith('Not done, nothing was changed: planned_weight_kg 50 for "Bench Press"', $error);
        $this->assertStringContainsString('would raise its load', $error);
    }

    public function test_the_stored_plan_is_the_same_whichever_language_the_user_chose(): void
    {
        $rows = function (string $language) {
            $user = $this->userWho($language);
            $date = now()->startOfWeek()->addDays(5)->toDateString();
            $this->fake->queue($this->toolCall('propose_weekly_plan_changes', [
                'decision_summary' => 'x', 'reasoning_factors' => ['x'],
                'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Rewritten', 'reason' => 'x',
                    'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 50]]]],
            ]));
            $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'x'])->assertOk();

            return WorkoutPlan::where('user_id', $user->id)->orderBy('planned_date')->get()->map(fn ($p) => [$p->planned_date->toDateString(), $p->title, $p->exercises()->pluck('planned_weight_kg')->all()])->all();
        };

        $this->assertSame($rows('en'), $rows('es'), 'Nothing about what is stored depends on the language.');
    }

    // ---- request-level isolation ---------------------------------------------------------------------------

    public function test_interleaved_users_each_get_their_own_language_and_the_application_language_is_restored(): void
    {
        $es = User::factory()->create(['locale' => 'es']);
        $en = User::factory()->create(['locale' => 'en']);
        app()->setLocale('en');

        $messages = [];

        foreach ([$es, $en, $es, $en] as $user) {
            $this->app['auth']->forgetGuards();
            $messages[] = $this->actingAs($user, 'sanctum')->getJson('/api/conversations/999999/messages')->json('message');
            $this->assertSame('en', app()->getLocale(), 'The language the application had before the request is back after it.');
        }

        $this->assertSame($messages[0], $messages[2]);
        $this->assertSame($messages[1], $messages[3]);
        $this->assertSame('No se encontró lo que buscas.', $messages[0]);
        $this->assertStringNotContainsString('No se encontró', (string) $messages[1]);
    }

    public function test_a_failing_request_does_not_leave_the_language_behind(): void
    {
        $es = User::factory()->create(['locale' => 'es']);
        app()->setLocale('en');

        $this->actingAs($es, 'sanctum')->getJson('/api/conversations/999999/messages')->assertStatus(404);
        $this->assertSame('en', app()->getLocale());

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-App-Locale' => 'es'])->getJson('/api/me')->assertStatus(401);
        $this->assertSame('en', app()->getLocale());
    }

    // ---- weekly-plan messages --------------------------------------------------------------------------------

    public function test_a_weekly_plan_error_is_recognised_or_replaced_by_a_safe_line_never_guessed_at(): void
    {
        $user = User::factory()->create(['locale' => 'es']);
        app()->setLocale('es');

        $say = fn (string $english) => WeeklyPlanMessages::present(['applied' => false, 'explanation' => null, 'error' => $english], $user)['error'];

        $this->assertSame('No se pudo actualizar el plan semanal porque la decisión estaba incompleta. Inténtalo de nuevo.', $say('decision_summary and reasoning_factors are required when saving a plan.'));
        $this->assertSame('La fecha 2026-11-02 está fuera de la semana actual (2026-11-09 a 2026-11-15).', $say('Date 2026-11-02 is outside the current week (2026-11-09 to 2026-11-15).'));
        $this->assertSame('No se cambió nada en tu plan.', $say('Nothing was changed yet: some of those days already have a planned workout, so the application is asking the user to confirm exactly these changes.'));
        $this->assertSame('No se pudo actualizar el plan semanal. Revisa los cambios e inténtalo de nuevo.', $say('Some tool message this release has never seen: internal detail X.'));
        $this->assertStringNotContainsString('internal detail', $say('Some tool message this release has never seen: internal detail X.'));

        // Nothing but `error` changes.
        $result = ['applied' => false, 'explanation' => null, 'skipped' => [['date' => '2026-11-07', 'reason' => 'already_started']], 'error' => 'x'];
        $this->assertSame(array_keys($result), array_keys(WeeklyPlanMessages::present($result, $user)));
        $this->assertSame([['date' => '2026-11-07', 'reason' => 'already_started']], WeeklyPlanMessages::present($result, $user)['skipped']);

        app()->setLocale('en');
        $this->assertSame($result, WeeklyPlanMessages::present($result, $user), 'English is returned untouched.');
        $this->assertSame(['applied' => true], WeeklyPlanMessages::present(['applied' => true], $user), 'No error, nothing to say.');
    }

    public function test_the_week_already_covered_line_is_localized_and_changes_nothing_else(): void
    {
        foreach (['en' => 'This week already reflects your recurring schedule.', 'es' => 'Esta semana ya refleja tu horario recurrente.'] as $language => $line) {
            $user = $this->userWho($language);
            DB::table('training_schedules')->where('user_id', $user->id)->delete();
            $this->app['auth']->forgetGuards();

            $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/generate')->assertOk()->assertExactJson(['applied' => false, 'explanation' => $line]);
        }
    }
}
