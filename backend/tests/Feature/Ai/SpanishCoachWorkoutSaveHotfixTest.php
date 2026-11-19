<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\UsesSpanishLocale;

/**
 * Production incident: a Spanish-speaking user asked five times, in plain Spanish, to put the workout the coach had just
 * recommended on the dashboard. Nothing was ever saved and the coach kept re-showing the routine and suggesting other
 * phrases. Cause: every phrase the application reads to authorize "save this workout" was English, so none of the five
 * Spanish requests granted the plan-writing tool (and the application's own words were English too).
 *
 * These replay the real conversation through the real endpoint with a scripted model, and check the same protections
 * the English path has: one day, the exact exercises, replacement only after a signed single-use confirmation, nothing
 * for a started or completed day, no invented loads, and nothing claimed that did not happen.
 */
class SpanishCoachWorkoutSaveHotfixTest extends PerformanceEvalCase
{
    // The user under test chose Spanish: the application's own words are Spanish because of THAT, not because of the language
    // of the message (language is presentation, never permission). English requests are the control for the same decisions.
    use UsesSpanishLocale;

    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    private string $recommendation;

    private const REPORT = 'Hoy no entrene futbol en abc y acabo de hacer una sesión de 15min de caminadora y recorri 1km, que mas puedo hacer hoy?';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();

        $day = CarbonImmutable::parse($this->today);
        $weekday = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][$day->dayOfWeekIso - 1];
        $month = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$day->month - 1];

        $this->recommendation = ucfirst($weekday).", {$day->day} de {$month} — Torso y Core\n\n"
            ."* Flexiones de brazos: 3 series de 8-10 repeticiones\n"
            ."* Bird-Dog: 3 series de 10 repeticiones por lado\n"
            ."* Dead Bug: 3 series de 10 repeticiones por lado\n"
            .'* Sentadillas con peso corporal: 2 series de 10 repeticiones';
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    private function exercises(): array
    {
        return [
            ['exercise_name' => 'Flexiones de brazos', 'planned_sets' => 3, 'planned_reps' => 8],
            ['exercise_name' => 'Bird-Dog', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Dead Bug', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Sentadillas con peso corporal', 'planned_sets' => 2, 'planned_reps' => 10],
        ];
    }

    private function save(?array $exercises = null, ?string $date = null, string $title = 'Torso y Core'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'Guardado como pediste.', 'reasoning_factors' => ['Lo pediste'],
            'planned_date' => $date ?? $this->today, 'exercises' => $exercises ?? $this->exercises(),
        ]))]);
    }

    /** The incident's first two turns: the activity report and the coach's recommendation. Returns the conversation id. */
    private function incident(?string $recommendation = null): int
    {
        $this->fake->queue(new AiResponse(text: $recommendation ?? $this->recommendation));

        return $this->ask($this->user, self::REPORT)['conversation'];
    }

    /** @return array{result: array, calls: array} */
    private function say(string $message, ?int $conversation, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return ['result' => $result, 'calls' => array_slice($this->fake->calls, $before)];
    }

    private function offered(array $call): array
    {
        return array_column($call['tools'], 'name');
    }

    private function plans(?User $user = null): int
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function last(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    public static function theFiveRequests(): array
    {
        return [
            '1 agregar + entramiento' => ['Agregar este entramiento a mi dashboard'],
            '2 entretenimiento' => ['Agrega este entretenimiento a mi dashboard'],
            '4 actualiza mi plan de hoy' => ['Actualiza mi plan de hoy con esta rutina'],
            '5 panel' => ['agrega este plan a mi panel'],
            'recién recomendado' => ['Guarda el entrenamiento que acabas de recomendar'],
            'guarda esta rutina' => ['Guarda esta rutina en mi plan'],
            'canonical' => ['Agrega este entrenamiento a mi dashboard'],
            'english add' => ['Add this workout to my dashboard'],
            'english save' => ['Save this workout to my plan'],
        ];
    }

    // ---- the incident ----------------------------------------------------------------------------------

    #[DataProvider('theFiveRequests')]
    public function test_each_spanish_request_from_the_incident_saves_the_recommended_workout(string $request): void
    {
        $this->logRun($this->user, 0, 15, 1.0);
        $activityBefore = ActivityLog::where('user_id', $this->user->id)->get()->toArray();
        $conversation = $this->incident();

        $turn = $this->say($request, $conversation, $this->save(), new AiResponse(text: 'Listo, quedó en tu plan de hoy.'));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]), 'The plan tool is offered for this message (it was withheld in production).');

        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->firstOrFail();
        $this->assertSame(['Flexiones de brazos', 'Bird-Dog', 'Dead Bug', 'Sentadillas con peso corporal'], $plan->exercises->pluck('exercise_name')->all(), 'All four exercises, as written.');
        $this->assertSame([3, 3, 3, 2], $plan->exercises->pluck('planned_sets')->all());
        $this->assertSame([8, 10, 10, 10], $plan->exercises->pluck('planned_reps')->all(), 'A range is stored as its low end, as the application has always done.');
        $this->assertSame([null, null, null, null], $plan->exercises->pluck('planned_weight_kg')->all(), 'No weight was named, so none is set.');
        $this->assertSame(['pending'], $plan->exercises->pluck('status')->unique()->values()->all(), 'A recommended workout is not logged as performed.');

        $this->assertSame(0, WorkoutSession::where('user_id', $this->user->id)->count());
        $this->assertSame($activityBefore, ActivityLog::where('user_id', $this->user->id)->get()->toArray(), 'The treadmill activity the user recorded is untouched.');

        $reply = $this->last($conversation);
        $this->assertStringContainsString('Listo, quedó en tu plan de hoy.', $reply->content);
        $this->assertStringContainsString('extremo inferior', $reply->content, 'The 8-10 range is said by the application, in the language the user chose.');
        $this->assertStringContainsString('(escrito 8-10)', $reply->content);
        $this->assertArrayNotHasKey('blocked_tool_calls', $reply->meta ?? []);
    }

    public function test_the_whole_incident_conversation(): void
    {
        $this->logRun($this->user, 0, 15, 1.0);
        $conversation = $this->incident();

        $this->say('Agregar este entramiento a mi dashboard', $conversation, $this->save(), new AiResponse(text: 'Listo.'));
        $this->assertSame(1, $this->plans());

        // Asked again right after the save, the reply before is the save, not a workout: the application says so and writes nothing more.
        $turn = $this->say('Agrega este entretenimiento a mi dashboard', $conversation);
        $this->assertSame(1, $this->plans(), 'No duplicate.');
        $this->assertSame([], $turn['calls']);
        $this->assertStringContainsString('no veo un entrenamiento', $turn['result']['answer']);

        // "Why didn't you add it?" after a real save is for the model to describe; the application does not deny the save.
        $why = $this->say('Porque no lo agregaste al dashboard?', $conversation, new AiResponse(text: 'Sí quedó guardado en tu plan.'));
        $this->assertSame(1, count($why['calls']));
        $this->assertNotContains('create_workout_plan', $this->offered($why['calls'][0]), 'A question grants no write.');

        $this->say('Actualiza mi plan de hoy con esta rutina', $conversation, new AiResponse(text: 'Hecho.'));
        $this->say('agrega este plan a mi panel', $conversation, new AiResponse(text: 'Hecho.'));
        $this->assertSame(1, $this->plans(), 'Five requests, one plan.');
    }

    public function test_why_didnt_you_add_it_when_nothing_was_saved_is_answered_from_what_happened(): void
    {
        $conversation = $this->incident();

        $turn = $this->say('Porque no lo agregaste al dashboard?', $conversation);

        $this->assertSame([], $turn['calls'], 'The application answers; the model is not asked to explain (or to claim a save).');
        $this->assertStringContainsString('No se ha guardado ningún entrenamiento en tu plan en esta conversación', $turn['result']['answer']);
        $this->assertStringContainsString('agrega este entrenamiento a mi plan', $turn['result']['answer']);
        $this->assertSame(0, $this->plans());
    }

    public function test_why_after_a_blocked_save_repeats_the_blocker_not_a_made_up_reason(): void
    {
        $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $conversation = $this->incident();
        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save(), new AiResponse(text: 'No pude.'));

        $turn = $this->say('Por qué no lo agregaste?', $conversation);

        $this->assertSame([], $turn['calls']);
        $this->assertStringContainsString('ya tiene un entrenamiento realizado y registrado', $turn['result']['answer']);
        $this->assertStringNotContainsString('revisar', $turn['result']['answer']);
    }

    public function test_the_english_request_after_a_spanish_recommendation_uses_the_same_path(): void
    {
        $conversation = $this->incident();

        $turn = $this->say('Add this workout to my dashboard', $conversation, $this->save(), new AiResponse(text: 'Done.'));

        $this->assertContains('create_workout_plan', $this->offered($turn['calls'][0]));
        $this->assertSame(4, WorkoutPlan::where('user_id', $this->user->id)->firstOrFail()->exercises->count());
    }

    // ---- parsing -----------------------------------------------------------------------------------------

    public function test_durations_and_rest_between_sets_are_read_without_becoming_exercises(): void
    {
        $conversation = $this->incident("Hoy, 20 minutos:\n\nUna sesión de 30 minutos para activarte.\n* Plancha: 3 series de 30 segundos\n* Sentadillas: 3 series de 12 repeticiones\n\nDescansa 60 segundos entre series.");

        $this->say('Guarda esta rutina en mi plan', $conversation, $this->save([
            ['exercise_name' => 'Plancha', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ['exercise_name' => 'Sentadillas', 'planned_sets' => 3, 'planned_reps' => 12],
        ], null, 'Core'), new AiResponse(text: 'Listo.'));

        $plan = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(['Plancha', 'Sentadillas'], $plan->exercises->pluck('exercise_name')->all(), '"Una sesión de 30 minutos" and "Descansa 60 segundos" are not exercises.');
        $this->assertSame(30, (int) $plan->exercises->first()->planned_duration_seconds);
    }

    public function test_a_day_of_rest_has_no_workout_to_save_and_the_user_is_told_so(): void
    {
        $conversation = $this->incident('Hoy toca descanso: no hace falta entrenar más. Hidrátate y duerme bien.');

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save());

        $this->assertSame([], $turn['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('no veo un entrenamiento', $turn['result']['answer']);
    }

    public function test_two_options_ask_which_one_and_write_nothing(): void
    {
        $conversation = $this->incident("Opción 1\n* Plancha: 3 series de 30 segundos\n* Sentadillas: 3 series de 12 repeticiones\n\nOpción 2\n* Flexiones: 3 series de 10 repeticiones\n* Remo: 3 series de 10 repeticiones");

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save());

        $this->assertSame([], $turn['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('podrías referirte a más de un entrenamiento', $turn['result']['answer']);
    }

    public function test_an_unfinished_routine_is_not_partly_saved(): void
    {
        $conversation = $this->incident("Prueba esto:\n* Plancha: 3 series de 30 segundos\n* Sentadillas: 3 series de 12 repeticiones\n* ...\n\nY más ejercicios después.");

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save());

        $this->assertSame([], $turn['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('parece incompleto', $turn['result']['answer']);
    }

    public function test_someone_elses_routine_is_not_saved_without_asking(): void
    {
        $conversation = $this->incident("Mi entrenador dijo que hicieras esto:\n* Plancha: 3 series de 30 segundos\n* Sentadillas: 3 series de 12 repeticiones");

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save());

        $this->assertSame(0, $this->plans());
        $this->assertSame([], $turn['calls']);
    }

    // ---- who may write, and what -------------------------------------------------------------------------

    public function test_entertainment_is_not_a_workout(): void
    {
        $conversation = $this->incident('Para relajarte esta noche puedes ver una serie o una película. Hay mucho entretenimiento disponible.');

        $turn = $this->say('Agrega este entretenimiento a mi dashboard', $conversation, $this->save());

        $this->assertSame(0, $this->plans());
        $this->assertSame([], $turn['calls'], 'No workout in the conversation: the application asks and the model is not given the plan tool.');
    }

    public static function notRequests(): array
    {
        return [
            'liking' => ['Me gusta el entretenimiento de la noche'],
            'wish' => ['Quiero más entretenimiento en mi plan'],
            'negated' => ['No guardes este entrenamiento en mi plan'],
            'attributed' => ['Mi entrenador dijo: agrega este entrenamiento a mi plan'],
            'question' => ['¿Debería agregar este entrenamiento a mi plan?'],
            'past' => ['No lo guardaste en mi plan'],
        ];
    }

    #[DataProvider('notRequests')]
    public function test_things_that_are_not_requests_to_save_grant_no_write(string $message): void
    {
        $conversation = $this->incident();

        $turn = $this->say($message, $conversation, $this->save(), new AiResponse(text: 'Ok.'));

        $this->assertNotContains('create_workout_plan', $this->offered($turn['calls'][0]), $message);
        $this->assertSame(0, $this->plans(), $message);
    }

    public function test_an_exercise_that_is_not_in_the_recommendation_saves_nothing(): void
    {
        $conversation = $this->incident();
        $exercises = $this->exercises();
        $exercises[3] = ['exercise_name' => 'Burpees', 'planned_sets' => 2, 'planned_reps' => 10];

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save($exercises), new AiResponse(text: 'Ya lo agregué a tu dashboard.'));

        $this->assertSame(0, $this->plans(), 'No partial save.');
        $reply = $this->last($conversation);
        $this->assertStringContainsString('No he cambiado tu plan', $reply->content);
        $this->assertStringContainsString('el entrenamiento no se guardó', $reply->content, 'A false "already added" is corrected by the application.');
        $this->assertTrue($reply->meta['save_clarification']);
    }

    public function test_a_dropped_exercise_saves_nothing(): void
    {
        $conversation = $this->incident();

        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save(array_slice($this->exercises(), 0, 3)), new AiResponse(text: 'Listo.'));

        $this->assertSame(0, $this->plans());
    }

    public function test_a_save_the_model_claims_without_a_write_is_corrected_when_nothing_was_asked(): void
    {
        $conversation = $this->incident();

        $this->say('Qué más me recomiendas?', $conversation, new AiResponse(text: 'Ya lo agregué a tu dashboard.'));

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('esta vez no se guardó nada', $this->last($conversation)->content);
    }

    public function test_a_false_claim_of_having_no_access_is_corrected_in_spanish(): void
    {
        $conversation = $this->incident();

        $this->say('Qué más me recomiendas?', $conversation, new AiResponse(text: 'No tengo acceso para actualizar tu dashboard.'));

        $this->assertStringContainsString('eso no es un límite de la aplicación', $this->last($conversation)->content);
        $this->assertStringContainsString('agrega este entrenamiento a mi plan', $this->last($conversation)->content);
    }

    public function test_the_retry_after_a_failed_save_saves_once(): void
    {
        $conversation = $this->incident();
        $bad = $this->exercises();
        $bad[0]['exercise_name'] = 'Algo distinto';

        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save($bad), new AiResponse(text: 'Hecho.'));
        $this->assertSame(0, $this->plans());

        $this->say('Inténtalo de nuevo.', $conversation, $this->save(), new AiResponse(text: 'Listo.'));
        $this->assertSame(1, $this->plans());

        $this->say('Inténtalo de nuevo.', $conversation, $this->save(), new AiResponse(text: 'Listo.'));
        $this->assertSame(1, $this->plans(), 'A second retry does not write a second plan.');
    }

    public function test_no_weight_is_invented_for_a_spanish_exercise(): void
    {
        $conversation = $this->incident("Prueba esto:\n* Sentadilla goblet: 3 series de 10 repeticiones con 24 kg\n* Flexiones: 3 series de 12 repeticiones");

        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save([
            ['exercise_name' => 'Sentadilla goblet', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24],
            ['exercise_name' => 'Flexiones', 'planned_sets' => 3, 'planned_reps' => 12],
        ], null, 'Piernas'), new AiResponse(text: 'Listo.'));

        $row = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail()->exercises->first();
        $this->assertSame('Sentadilla goblet', $row->exercise_name);
        $this->assertNull($row->planned_weight_kg, 'The coach\'s own 24 kg is not a baseline and the user did not choose it.');
    }

    public function test_it_writes_only_to_the_signed_in_users_plan_and_never_on_another_day(): void
    {
        $other = $this->newUser();
        app(WorkoutPlanService::class)->createOrReplace($other, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Su plan']);
        $conversation = $this->incident();

        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save(null, CarbonImmutable::parse($this->today)->addDay()->toDateString()), new AiResponse(text: 'Listo.'));

        $this->assertSame(0, $this->plans(), 'The day is the application\'s: another date is refused.');
        $this->assertSame('Su plan', WorkoutPlan::where('user_id', $other->id)->value('title'));
    }

    public function test_the_user_can_name_the_day_in_spanish_and_a_numeric_date_is_asked_about(): void
    {
        $day = CarbonImmutable::parse($this->today)->addDays(2);
        $weekday = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][$day->dayOfWeekIso - 1];
        $conversation = $this->incident();

        $this->say("Guárdalo para el {$weekday}", $conversation, $this->save(null, $day->toDateString()), new AiResponse(text: 'Listo.'));
        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $day->toDateString())->count());

        $conversation = $this->incident();
        $turn = $this->say('Guárdalo en mi plan para el 7/11', $conversation, $this->save());
        $this->assertSame([], $turn['calls']);
        $this->assertStringContainsString('qué día', $turn['result']['answer']);
    }

    // ---- an existing plan, confirmation, protection --------------------------------------------------------------

    private function existingPlanToday(): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Plan anterior',
            'exercises' => [['exercise_name' => 'Sentadillas', 'planned_sets' => 3, 'planned_reps' => 10]],
        ]);
    }

    public function test_an_existing_workout_is_replaced_only_after_a_signed_spanish_confirmation(): void
    {
        $this->existingPlanToday();
        $conversation = $this->incident();

        $turn = $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save(), new AiResponse(text: 'Listo.'));

        $this->assertSame('Plan anterior', WorkoutPlan::where('user_id', $this->user->id)->value('title'), 'Nothing is replaced yet.');
        $this->assertStringContainsString('Responde "sí" en los próximos 15 minutos', $turn['result']['answer']);
        $this->assertStringContainsString('Plan anterior', $turn['result']['answer']);

        $done = $this->say('Sí, reemplázalo.', $conversation);
        $this->assertSame('Torso y Core', WorkoutPlan::where('user_id', $this->user->id)->value('title'));
        $this->assertStringContainsString('Reemplacé', $done['result']['answer']);
        $this->assertSame([], $done['calls'], 'The application made the replacement; the model was not asked.');

        // Single use: the same answer again changes nothing and says so.
        WorkoutPlan::where('user_id', $this->user->id)->update(['title' => 'Cambiado a mano']);
        $again = $this->say('sí', $conversation);
        $this->assertSame('Cambiado a mano', WorkoutPlan::where('user_id', $this->user->id)->value('title'));
        $this->assertStringContainsString('No hay nada esperando tu confirmación', $again['result']['answer']);
    }

    private function heldReplacement(): int
    {
        $this->existingPlanToday();
        $conversation = $this->incident();
        $this->say('Agrega este entrenamiento a mi dashboard', $conversation, $this->save(), new AiResponse(text: 'Listo.'));

        return $conversation;
    }

    private function title(): string
    {
        return (string) WorkoutPlan::where('user_id', $this->user->id)->value('title');
    }

    public function test_a_hedge_asks_and_leaves_the_pending_confirmation_untouched(): void
    {
        $conversation = $this->heldReplacement();
        $question = $this->last($conversation);
        $before = $question->fresh()->meta;

        $unclear = $this->say('tal vez', $conversation);

        $this->assertSame([], $unclear['calls'], 'The application asks; the model is not consulted.');
        $this->assertSame('Plan anterior', $this->title(), 'A hedge approves nothing.');
        $this->assertStringContainsString('responde "sí" (con tilde)', $unclear['result']['answer']);
        $this->assertSame($before, $question->fresh()->meta, 'The held proposal is neither spent nor renewed: its signed content and expiry are untouched.');

        $this->say('depende', $conversation);
        $this->assertSame($before, $question->fresh()->meta);

        // A clear answer afterwards redeems that very proposal, once.
        $done = $this->say('Sí', $conversation);
        $this->assertSame('Torso y Core', $this->title());
        $this->assertStringContainsString('Reemplacé', $done['result']['answer']);
    }

    public function test_si_with_a_clear_verb_is_a_yes(): void
    {
        $conversation = $this->heldReplacement();

        $this->say('Si, reemplázalo', $conversation);

        $this->assertSame('Torso y Core', $this->title());
    }

    public function test_a_no_after_the_clarification_declines_and_a_later_si_does_not_revive_it(): void
    {
        $conversation = $this->heldReplacement();
        $this->say('tal vez', $conversation);

        $this->say('No', $conversation, new AiResponse(text: 'De acuerdo, lo dejo como está.'));
        $this->say('Sí', $conversation, new AiResponse(text: '¿Sí a qué?'));

        $this->assertSame('Plan anterior', $this->title(), 'Declined: the answer that follows a "no" is not an answer to the old question.');
    }

    public function test_the_clarification_does_not_extend_the_expiry(): void
    {
        $conversation = $this->heldReplacement();

        $this->travel(10)->minutes();
        $this->say('tal vez', $conversation);
        $this->travel(10)->minutes(); // 20 minutes after the question: past its 15

        $late = $this->say('Sí', $conversation);

        $this->assertSame('Plan anterior', $this->title());
        $this->assertStringContainsString('ya no es válida', $late['result']['answer']);
    }

    public function test_an_expired_proposal_is_not_clarified_and_a_used_one_is_not_revived(): void
    {
        $conversation = $this->heldReplacement();
        $this->travel(20)->minutes();

        $expired = $this->say('si', $conversation, new AiResponse(text: 'Hola.'));
        $this->assertCount(1, $expired['calls'], 'Nothing is pending, so it is an ordinary message.');
        $this->assertStringNotContainsString('con tilde', $expired['result']['answer']);

        $this->app['auth']->forgetGuards();
        $this->user = $this->newUser();
        $conversation = $this->heldReplacement();
        $this->say('Sí', $conversation);
        $this->assertSame('Torso y Core', $this->title());
        WorkoutPlan::where('user_id', $this->user->id)->update(['title' => 'Cambiado a mano']);

        $replay = $this->say('si', $conversation, new AiResponse(text: 'Hola.'));
        $this->assertStringNotContainsString('con tilde', $replay['result']['answer']);
        $this->say('Sí', $conversation, new AiResponse(text: 'Hola.'));
        $this->assertSame('Cambiado a mano', $this->title(), 'A replayed answer redeems nothing.');
    }

    public function test_a_stale_proposal_is_refused_after_a_clarification(): void
    {
        $conversation = $this->heldReplacement();
        $this->say('tal vez', $conversation);
        WorkoutPlan::where('user_id', $this->user->id)->first()->update(['title' => 'Editado después']);

        $turn = $this->say('si', $conversation);

        $this->assertSame('Editado después', $this->title(), 'The day changed since it was asked: nothing is replaced.');
        $this->assertStringContainsString('No lo reemplacé', $turn['result']['answer']);
    }

    public function test_a_confirmation_cannot_be_used_across_users_or_conversations(): void
    {
        $conversation = $this->heldReplacement();
        $this->say('tal vez', $conversation);

        $fresh = $this->ask($this->user, 'Hola')['conversation'];
        foreach (['si', 'Sí', 'dale', 'claro'] as $answer) {
            $this->say($answer, $fresh, new AiResponse(text: 'Hola.'));
        }
        $this->assertSame('Plan anterior', $this->title(), 'Another conversation of the same user has nothing pending.');

        $this->app['auth']->forgetGuards();
        $other = $this->newUser();
        $theirs = $this->ask($other, 'Hola')['conversation'];
        foreach (['si', 'Sí', 'dale'] as $answer) {
            $this->fake->queue(new AiResponse(text: 'Hola.'));
            $this->ask($other, $answer, $theirs);
        }
        $this->assertSame('Plan anterior', $this->title());
        $this->assertSame(0, $this->plans($other));

        // The original is still open for its own user and conversation.
        $this->app['auth']->forgetGuards();
        $this->say('si', $conversation);
        $this->assertSame('Torso y Core', $this->title());
    }

    public static function notConfirmations(): array
    {
        return [
            'conditional' => ['si mañana entreno puedo hacerlo después'],
            'conditional 2' => ['si tengo tiempo lo reemplazo yo'],
            'quoted' => ['"sí"'],
            'quoted 2' => ['«dale»'],
            'negated' => ['no, sí no'],
            'mixed' => ['sí pero mejor mañana'],
            'mixed 2' => ['claro, pero cámbialo por otro'],
            'a question' => ['¿sí o no?'],
            'attributed' => ['mi entrenador dijo sí'],
        ];
    }

    #[DataProvider('notConfirmations')]
    public function test_conditional_quoted_negated_and_mixed_answers_confirm_nothing_and_the_question_survives(string $answer): void
    {
        $conversation = $this->heldReplacement();
        $question = $this->last($conversation);
        $before = $question->fresh()->meta;

        $this->say($answer, $conversation, new AiResponse(text: 'Entendido.'));

        $this->assertSame('Plan anterior', $this->title(), $answer);
        $this->assertSame($before, $question->fresh()->meta, 'Nothing was spent.');
    }

    public static function clearYes(): array
    {
        return [['sí'], ['Sí'], ['si'], ['Si.'], ['Si, reemplázalo'], ['claro'], ['dale'], ['Dale!'], ['de acuerdo']];
    }

    #[DataProvider('clearYes')]
    public function test_a_clear_yes_confirms_the_pending_replacement(string $answer): void
    {
        $conversation = $this->heldReplacement();

        $this->say($answer, $conversation);

        $this->assertSame('Torso y Core', $this->title(), $answer);
    }

    #[DataProvider('clearYes')]
    public function test_without_a_pending_replacement_none_of_them_authorizes_anything(string $answer): void
    {
        $this->existingPlanToday();
        $conversation = $this->incident();

        $this->say($answer, $conversation, new AiResponse(text: 'Hola.'));

        $this->assertSame('Plan anterior', $this->title(), $answer);
        $this->assertSame(1, $this->plans());
    }

    public function test_a_started_or_completed_day_is_never_replaced(): void
    {
        $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $before = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail();
        $conversation = $this->incident();

        $turn = $this->say('Reemplaza el entrenamiento de hoy con este', $conversation, $this->save(), new AiResponse(text: 'No pude.'));

        $after = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame($before->id, $after->id);
        $this->assertSame(['Bench Press'], $after->exercises->pluck('exercise_name')->all());
        $this->assertStringContainsString('ya tiene un entrenamiento realizado y registrado', $turn['result']['answer']);
    }

    public function test_saying_replace_outright_replaces_an_untouched_day_without_asking(): void
    {
        $this->existingPlanToday();
        $conversation = $this->incident();

        $this->say('Reemplaza el entrenamiento de hoy con este', $conversation, $this->save(), new AiResponse(text: 'Listo.'));

        $this->assertSame('Torso y Core', WorkoutPlan::where('user_id', $this->user->id)->value('title'));
        $this->assertStringContainsString('Como pediste, reemplacé', $this->last($conversation)->content);
    }

    // ---- one date rule, in both languages ---------------------------------------------------------------------------------

    private function heading(string $lang, CarbonImmutable $day, bool $withDate = true): string
    {
        $en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day->dayOfWeekIso - 1];
        $es = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$day->dayOfWeekIso - 1];
        $monthEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][$day->month - 1];
        $monthEs = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$day->month - 1];

        return $lang === 'es'
            ? $es.($withDate ? ", {$day->day} de {$monthEs}" : '').' — Torso y Core'
            : $en.($withDate ? ", {$monthEn} {$day->day}" : '').' — Upper body';
    }

    private function body(string $lang): string
    {
        return $lang === 'es' ? "\n* Plancha: 3 series de 30 segundos\n* Sentadillas: 3 series de 12 repeticiones" : "\n* Plank: 3 sets of 30 seconds\n* Squats: 3 sets of 12 reps";
    }

    private function request(string $lang): string
    {
        return $lang === 'es' ? 'Agrega este entrenamiento a mi dashboard' : 'Add this workout to my dashboard';
    }

    private function planFor(string $lang, ?string $date = null): AiResponse
    {
        return $this->save($lang === 'es'
            ? [['exercise_name' => 'Plancha', 'planned_sets' => 3, 'planned_duration_seconds' => 30], ['exercise_name' => 'Sentadillas', 'planned_sets' => 3, 'planned_reps' => 12]]
            : [['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30], ['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 12]], $date, 'Test');
    }

    public static function languages(): array
    {
        return ['english' => ['en'], 'spanish' => ['es']];
    }

    #[DataProvider('languages')]
    public function test_a_consistent_dated_heading_names_the_day_of_the_workout_it_belongs_to(string $lang): void
    {
        $day = CarbonImmutable::parse($this->today)->addDays(2);
        $conversation = $this->incident($this->heading($lang, $day).$this->body($lang));

        $this->say($this->request($lang), $conversation, $this->planFor($lang, $day->toDateString()), new AiResponse(text: 'ok'));

        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $day->toDateString())->count());
        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->today)->count(), 'Never silently today.');
    }

    #[DataProvider('languages')]
    public function test_a_conflicting_past_or_dateless_heading_asks_in_both_languages_and_writes_nothing(string $lang): void
    {
        $day = CarbonImmutable::parse($this->today)->addDays(2);
        $wrongWeekday = $this->heading($lang, $day);
        $wrongWeekday = $lang === 'es' ? preg_replace('/^\\S+,/u', 'Lunes,', $wrongWeekday) : preg_replace('/^\\S+,/u', 'Monday,', $wrongWeekday);
        if ($day->dayOfWeekIso === 1) {
            $wrongWeekday = $lang === 'es' ? preg_replace('/^\\S+,/u', 'Martes,', $this->heading($lang, $day)) : preg_replace('/^\\S+,/u', 'Tuesday,', $this->heading($lang, $day));
        }

        foreach ([
            'conflicting weekday and date' => $wrongWeekday,
            'a day already past' => $this->heading($lang, CarbonImmutable::parse($this->today)->subDays(1)),
            'weekday only' => $this->heading($lang, $day, withDate: false),
        ] as $case => $heading) {
            $this->app['auth']->forgetGuards();
            $this->user = $this->newUser();
            $conversation = $this->incident($heading.$this->body($lang));

            $turn = $this->say($this->request($lang), $conversation, $this->planFor($lang, $this->today));

            $this->assertSame([], $turn['calls'], $case);
            $this->assertSame(0, $this->plans(), $case.': nothing is written, and never silently today.');
            $this->assertStringContainsString('Qué día debo usar', $turn['result']['answer'], $case);
        }
    }

    #[DataProvider('languages')]
    public function test_the_users_own_day_decides_and_a_heading_alone_grants_nothing(string $lang): void
    {
        $day = CarbonImmutable::parse($this->today)->addDays(2);
        $other = CarbonImmutable::parse($this->today)->addDay();
        $conversation = $this->incident($this->heading($lang, $day).$this->body($lang));

        // No request: the heading grants no write, in either language.
        $turn = $this->say($lang === 'es' ? '¿Qué más me recomiendas?' : 'What else do you recommend?', $conversation, $this->planFor($lang, $day->toDateString()), new AiResponse(text: 'ok'));
        $this->assertNotContains('create_workout_plan', $this->offered($turn['calls'][0]));
        $this->assertSame(0, $this->plans());

        // An explicit request that names another day is saved on that day.
        $conversation = $this->incident($this->heading($lang, $day).$this->body($lang));
        $this->say($lang === 'es' ? 'Guárdalo en mi plan para mañana' : 'Save it to my plan for tomorrow', $conversation, $this->planFor($lang, $other->toDateString()), new AiResponse(text: 'ok'));
        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $other->toDateString())->count());
    }

    // ---- the language of the account is presentation, never permission ---------------------------------------------------

    public static function accountsAndRequests(): array
    {
        return [
            'spanish account, spanish request' => ['es', 'Agrega este entrenamiento a mi dashboard'],
            'english account, spanish request' => ['en', 'Agrega este entrenamiento a mi dashboard'],
            'spanish account, english request' => ['es', 'Add this workout to my dashboard'],
            'english account, english request' => ['en', 'Add this workout to my dashboard'],
        ];
    }

    #[DataProvider('accountsAndRequests')]
    public function test_the_same_workout_is_saved_whatever_the_account_language_or_the_request_language(string $account, string $request): void
    {
        $this->user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $account]);
        $this->today = $this->user->localToday();
        $conversation = $this->incident();

        $this->say($request, $conversation, $this->save(), new AiResponse(text: 'Listo.'));

        $plan = WorkoutPlan::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(['Flexiones de brazos', 'Bird-Dog', 'Dead Bug', 'Sentadillas con peso corporal'], $plan->exercises->pluck('exercise_name')->all());
        $this->assertSame([null, null, null, null], $plan->exercises->pluck('planned_weight_kg')->all());
        // Only the words of the application's own note follow the account.
        $this->assertStringContainsString($account === 'es' ? 'extremo inferior' : 'saved the low end', $this->last($conversation)->content);
    }

    #[DataProvider('accountsAndRequests')]
    public function test_an_existing_workout_needs_the_signed_confirmation_whatever_the_languages(string $account, string $request): void
    {
        $this->user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $account]);
        $this->today = $this->user->localToday();
        $this->existingPlanToday();
        $conversation = $this->incident();

        $this->say($request, $conversation, $this->save(), new AiResponse(text: 'Listo.'));
        $this->assertSame('Plan anterior', $this->title(), 'Nothing is replaced before a confirmation.');

        $this->say('tal vez', $conversation, new AiResponse(text: 'ok'));
        $this->assertSame('Plan anterior', $this->title(), 'A hedge confirms nothing, in any account language.');

        $this->say($account === 'es' ? 'si' : 'yes', $conversation);
        $this->assertSame('Torso y Core', $this->title());
    }
}
