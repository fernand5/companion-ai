<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\UsesSpanishLocale;

/**
 * M4 / checkpoint C: a plan of several days shown in Spanish is saved whole or not at all, on exactly the days shown, under
 * every protection a single workout has (signed single-use confirmation, started/completed days, isolation, no invented
 * weights), and the result the client shows is read back from the database.
 */
class SpanishWeekPlanSaveTest extends PerformanceEvalCase
{
    use UsesSpanishLocale;

    private User $user;

    private FakeAiProvider $fake;

    /** The week is Mon 26 Oct - Sun 1 Nov 2026; "today" is Thursday the 29th. */
    private const SATURDAY = '2026-10-31';

    private const SUNDAY = '2026-11-01';

    private const PLAN = "Aquí tienes un plan para el resto de la semana:\n\n**Sábado: Cuerpo completo**\n* Sentadillas goblet: 3 series de 10-12 repeticiones\n* Flexiones: 3 series de 8-12 repeticiones\n* Plancha: 3 series de 30 segundos\n\n**Domingo: Recuperación activa**\n* Caminata suave: 30 minutos\n* Estiramientos ligeros: 10 minutos\n\nSi este plan te parece bien, di \"agrega este plan a mi panel\" y lo actualizo para el sábado y el domingo.";

    private const PLAN_WITH_REST = "Tu fin de semana:\n\n**Sábado: Tren inferior**\n1. Sentadillas goblet 3x10\n2. Plancha 3 x 30 segundos\n\n**Domingo: Día de descanso**\nTómalo con calma y deja que tu cuerpo se recupere.\n\nDi \"agrega este plan a mi panel\".";

    private const REQUEST = 'Agrega este plan a mi panel';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function saturday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Cuerpo completo', 'reason' => 'Como en el plan que viste',
            'exercises' => [
                ['exercise_name' => 'Sentadillas goblet', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Flexiones', 'planned_sets' => 3, 'planned_reps' => 8],
                ['exercise_name' => 'Plancha', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ];
    }

    private function sunday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Recuperación activa', 'reason' => 'Como en el plan que viste',
            'exercises' => [
                ['exercise_name' => 'Caminata suave', 'planned_duration_seconds' => 1800],
                ['exercise_name' => 'Estiramientos ligeros', 'planned_duration_seconds' => 600],
            ],
        ];
    }

    private function restSunday(): array
    {
        return ['date' => self::SUNDAY, 'action' => 'clear', 'reason' => 'Día de descanso, como en el plan'];
    }

    private function weekCall(array $changes): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'El plan que viste.', 'reasoning_factors' => ['Pediste agregar este plan'], 'changes' => $changes,
        ])]);
    }

    private function conversationWith(string $reply, string $request = 'crea un plan para el resto de la semana', ?User $as = null): int
    {
        $user = $as ?? $this->user;
        $conversation = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        Message::create(['conversation_id' => $conversation, 'role' => 'user', 'content' => $request, 'meta' => null]);
        Message::create(['conversation_id' => $conversation, 'role' => 'assistant', 'content' => $reply, 'meta' => null]);

        return $conversation;
    }

    /** @return array{answer: string, calls: int, message: Message} */
    private function say(string $message, int $conversation, ?User $as = null, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($as ?? $this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'], 'calls' => count($this->fake->calls) - $before,
            'message' => Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function plansOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::with('exercises')->where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    private function plans(?User $user = null): int
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function existing(string $date, string $title = 'Mi propia sesión'): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $date, 'activity_type' => 'strength', 'title' => $title, 'source' => 'manual',
            'exercises' => [['exercise_name' => 'Press de banca', 'planned_sets' => 3, 'planned_reps' => 8]],
        ]);
    }

    // ---- saving ------------------------------------------------------------------------------------------------------------

    public function test_a_spanish_plan_saves_both_days_exactly_and_the_result_is_read_back(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say(self::REQUEST, $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Listo, los dos días ya están en tu panel.'));

        $this->assertSame(['Sentadillas goblet', 'Flexiones', 'Plancha'], $this->plansOn(self::SATURDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame(['Caminata suave', 'Estiramientos ligeros'], $this->plansOn(self::SUNDAY)->exercises->sortBy('position')->pluck('exercise_name')->all());
        $this->assertSame(2, $this->plans(), 'Exactly the two days shown, no other day.');
        $this->assertNull($this->plansOn('2026-10-29'), 'Not today.');
        $this->assertStringStartsWith('Listo. Añadí esto a tu plan', $turn['answer']);
        $this->assertStringNotContainsString('ya están en tu panel', $turn['answer'], 'The application speaks for what was saved, not the model.');

        $result = $turn['message']->meta['save_result'];
        // (MySQL stores JSON objects with their own key order: compare the content.)
        $this->assertEquals([
            ['date' => self::SATURDAY, 'outcome' => 'added', 'title' => 'Cuerpo completo'],
            ['date' => self::SUNDAY, 'outcome' => 'added', 'title' => 'Recuperación activa'],
        ], $result['days'], 'Names and dates come from the stored plans.');
    }

    public function test_a_rest_day_is_saved_as_rest_and_listed_as_such_with_no_workout_invented(): void
    {
        $conversation = $this->conversationWith(self::PLAN_WITH_REST);

        $turn = $this->say(self::REQUEST, $conversation, null, $this->weekCall([
            $this->saturday(['title' => 'Tren inferior', 'exercises' => [['exercise_name' => 'Sentadillas goblet', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Plancha', 'planned_sets' => 3, 'planned_duration_seconds' => 30]]]),
            $this->restSunday(),
        ]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertNull($this->plansOn(self::SUNDAY)?->exercises->first(), 'No exercise was invented for the rest day.');
        $days = $turn['message']->meta['save_result']['days'];
        $this->assertSame(['added', 'rest'], array_column($days, 'outcome'));
        $this->assertNull($days[1]['title']);
        $this->assertSame(self::SUNDAY, $days[1]['date']);
    }

    public function test_a_wrong_exercise_on_any_day_saves_nothing_on_every_day(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $bad = $this->weekCall([$this->saturday(), $this->sunday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]])]);

        $turn = $this->say(self::REQUEST, $conversation, null, $bad, $bad, $bad, new AiResponse(text: '¡Guardado!'));

        $this->assertSame(0, $this->plans(), 'All or none: Saturday was fine, and it was not saved either.');
        $this->assertStringContainsString('No he cambiado tu plan', $turn['answer']);
        $this->assertArrayNotHasKey('save_result', $turn['message']->meta ?? []);
    }

    public function test_no_weight_is_invented_for_any_day(): void
    {
        $conversation = $this->conversationWith("**Sábado: Piernas**\n* Sentadilla goblet: 3 series de 10 repeticiones con 24 kg\n\n**Domingo: Descanso**");

        $this->say(self::REQUEST, $conversation, null, $this->weekCall([
            $this->saturday(['title' => 'Piernas', 'exercises' => [['exercise_name' => 'Sentadilla goblet', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24]]]),
            $this->restSunday(),
        ]), new AiResponse(text: 'ok'));

        $row = $this->plansOn(self::SATURDAY)?->exercises->first();
        $this->assertNull($row?->planned_weight_kg, 'The coach\'s own 24 kg is not a baseline and the user did not choose it.');
    }

    // ---- protection ------------------------------------------------------------------------------------------------------

    public function test_a_started_or_completed_day_stops_the_whole_plan(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $done = WorkoutPlan::factory()->for($this->user)->create(['planned_date' => self::SUNDAY, 'status' => WorkoutPlan::STATUS_COMPLETED, 'title' => 'Ya hecho']);
        WorkoutPlanExercise::factory()->for($done, 'workoutPlan')->create(['exercise_name' => 'Sentadilla', 'status' => WorkoutPlanExercise::STATUS_COMPLETED, 'position' => 0]);

        $turn = $this->say(self::REQUEST, $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: '¡Todo guardado!'));

        $this->assertNull($this->plansOn(self::SATURDAY), 'Saturday is not saved either: a plan is saved whole or not at all.');
        $this->assertSame('Ya hecho', $this->plansOn(self::SUNDAY)->title);
        $this->assertStringContainsString('2026-11-01', $turn['answer']);
        $this->assertStringNotContainsString('Todo guardado', $turn['answer']);

        // The user asks for the other day only: that is now a plain, complete save.
        $this->say('Sábado', $conversation, null, $this->weekCall([$this->saturday()]), new AiResponse(text: 'ok'));
        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertSame('Ya hecho', $this->plansOn(self::SUNDAY)->title);
    }

    // ---- a day that already has a plan: signed confirmation, in Spanish --------------------------------------------------------

    public function test_an_existing_day_holds_the_whole_week_and_a_spanish_yes_makes_exactly_those_changes_once(): void
    {
        $this->existing(self::SATURDAY);
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say(self::REQUEST, $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));

        $this->assertSame('Mi propia sesión', $this->plansOn(self::SATURDAY)->title, 'Nothing is replaced yet.');
        $this->assertNull($this->plansOn(self::SUNDAY), 'And nothing is added either: the whole set waits.');
        $this->assertStringContainsString('Responde "sí"', $turn['answer']);
        $this->assertArrayNotHasKey('save_result', $turn['message']->meta ?? []);

        $messages = $this->actingAs($this->user, 'sanctum')->getJson("/api/conversations/{$conversation}/messages")->json();
        $held = collect($messages['data'] ?? $messages)->last(fn ($m) => isset($m['confirmation']));
        $this->assertTrue($held['confirmation']['actionable']);

        $done = $this->say('si', $conversation);

        $this->assertSame('Cuerpo completo', $this->plansOn(self::SATURDAY)->title);
        $this->assertSame('Recuperación activa', $this->plansOn(self::SUNDAY)->title);
        $this->assertSame(['replaced', 'added'], array_column($done['message']->meta['save_result']['days'], 'outcome'));
        $this->assertSame(2, $this->plans());

        // Single use.
        $before = WorkoutPlan::pluck('title', 'id')->all();
        $this->say('Sí', $conversation, null, new AiResponse(text: 'Hola.'));
        $this->assertSame($before, WorkoutPlan::pluck('title', 'id')->all());
        $this->assertSame(2, $this->plans(), 'Confirming twice leaves exactly one copy of the week.');
    }

    public function test_an_expired_or_foreign_confirmation_saves_nothing(): void
    {
        $this->existing(self::SATURDAY);
        $conversation = $this->conversationWith(self::PLAN);
        $this->say(self::REQUEST, $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));

        // Another user, in a conversation of their own, answering "sí" to nothing.
        $this->app['auth']->forgetGuards();
        $other = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
        $theirs = $this->conversationWith('Hola.', 'hola', $other);
        $this->say('Sí', $theirs, $other, new AiResponse(text: 'Hola.'));
        $this->say('si', $theirs, $other, new AiResponse(text: 'Hola.'));
        $this->assertSame(0, $this->plans($other));

        $this->app['auth']->forgetGuards();
        $this->travelTo(Carbon::parse('2026-10-29 10:20', 'America/Bogota'));
        $late = $this->say('Sí', $conversation);

        $this->assertSame('Mi propia sesión', $this->plansOn(self::SATURDAY)->title);
        $this->assertNull($this->plansOn(self::SUNDAY));
        $this->assertStringContainsString('ya no es válida', $late['answer']);
    }

    public function test_a_plan_shown_to_another_user_cannot_be_saved_by_this_one(): void
    {
        $other = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
        $theirConversation = $this->conversationWith(self::PLAN, 'crea un plan', $other);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/conversations/{$theirConversation}/messages", ['content' => self::REQUEST])->assertForbidden();

        $this->assertSame(0, $this->plans());
        $this->assertSame(0, $this->plans($other));
    }

    // ---- retries ------------------------------------------------------------------------------------------------------------

    public function test_a_retry_saves_the_same_plan_once(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $bad = $this->weekCall([$this->saturday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]]), $this->sunday()]);
        $this->say(self::REQUEST, $conversation, null, $bad, $bad, $bad, new AiResponse(text: '¡Listo!'));
        $this->assertSame(0, $this->plans());

        $this->travelTo(Carbon::parse('2026-10-29 10:05', 'America/Bogota'));
        $this->say('Inténtalo de nuevo', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));
        $this->assertSame(2, $this->plans(), 'A retry is the same request.');

        $this->say('Inténtalo de nuevo', $conversation, null, new AiResponse(text: 'ok'));
        $this->assertSame(2, $this->plans(), 'No duplicates.');
    }

    // ---- dates ---------------------------------------------------------------------------------------------------------------

    public function test_a_numeric_date_that_could_mean_two_days_is_asked_about_and_nothing_is_saved(): void
    {
        $conversation = $this->conversationWith("**Lunes 7/11 – Piernas**\n* Sentadillas: 3 series de 10 repeticiones\n\n**Martes 8/11 – Cardio**\n* Caminata 30 minutos");

        $turn = $this->say(self::REQUEST, $conversation, null, $this->weekCall([$this->saturday()]));

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('No he cambiado tu plan', $turn['answer']);
    }

    public function test_naming_only_some_days_saves_those_and_says_which_were_left_out(): void
    {
        $conversation = $this->conversationWith(self::PLAN);

        $turn = $this->say('Guarda el entrenamiento del sábado en mi plan', $conversation, null, $this->weekCall([$this->saturday()]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY));
        $this->assertNull($this->plansOn(self::SUNDAY));
        $this->assertStringContainsString('Domingo: Recuperación activa', $turn['answer']);
        $this->assertSame([self::SATURDAY], array_column($turn['message']->meta['save_result']['days'], 'date'));
    }

    // ---- both languages in one conversation ---------------------------------------------------------------------------------

    public function test_an_english_request_saves_a_spanish_plan_and_a_spanish_request_saves_an_english_one(): void
    {
        $conversation = $this->conversationWith(self::PLAN);
        $this->say('add this plan to my dashboard', $conversation, null, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));
        $this->assertSame(2, $this->plans());

        $this->app['auth']->forgetGuards();
        $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
        $english = $this->conversationWith("**Saturday: Legs**\n* Squats: 3 sets of 10 reps\n\n**Sunday: Rest day**\nTake it easy.", 'plan my weekend', $user);

        $this->say(self::REQUEST, $english, $user, $this->weekCall([
            ['date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Legs', 'reason' => 'As shown', 'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]]],
            $this->restSunday(),
        ]), new AiResponse(text: 'ok'));

        $this->assertNotNull($this->plansOn(self::SATURDAY, $user));
    }
}
