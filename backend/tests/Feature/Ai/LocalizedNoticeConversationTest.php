<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\Notices\ApplicationNotices;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\ReplyText;
use App\Services\Ai\WeeklyPlanCoachService;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3b, end to end through the real endpoint with a scripted model: the application's own notices come in the
 * language the user chose, every outcome (plans written, notice codes stored, who may change what) is the same in both,
 * and the language changes nothing but the words.
 */
class LocalizedNoticeConversationTest extends PerformanceEvalCase
{
    private FakeAiProvider $fake;

    private string $today;

    private const DESCRIBED = "Here's a 30-minute upper body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds";

    private const WITH_RANGES = "A short session:\n1. Goblet Squat 3 x 8-12 reps\n2. Plank 3 x 30-45 seconds";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        Cache::flush();
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function userWho(string $locale): User
    {
        $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $locale]);
        $this->today = $user->localToday();

        return $user;
    }

    private function conversation(User $user): int
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
    }

    private function saveCall(?array $exercises = null, string $title = 'Upper body'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'Saved as asked.', 'reasoning_factors' => ['You asked to add it'],
            'exercises' => $exercises ?? [
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ])]);
    }

    private function say(User $user, string $message, int $conversation, AiResponse ...$responses): Message
    {
        Cache::flush();
        $this->fake->queue(...$responses);
        $result = $this->ask($user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail();
    }

    /** @return array<int, string> */
    private function codes(Message $message): array
    {
        return array_column($message->meta[ReplyText::META_KEY]['segments'] ?? [], 'code');
    }

    private function existingPlan(User $user, string $title = 'Old plan'): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $user->localToday(), 'activity_type' => 'strength', 'title' => $title,
            'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ], replaceExisting: true);
    }

    /** @return array<int, array<string, mixed>> the plans in the database as data the language must not be able to change */
    private function plansOf(User $user): array
    {
        return WorkoutPlan::with('exercises')->where('user_id', $user->id)->orderBy('planned_date')->get()->map(fn (WorkoutPlan $p) => [
            'date' => $p->planned_date->toDateString(), 'title' => $p->title, 'type' => $p->activity_type, 'status' => $p->status,
            'exercises' => $p->exercises->sortBy('position')->map(fn ($e) => [$e->exercise_name, $e->exercise_slug, $e->planned_sets, $e->planned_reps, $e->planned_weight_kg, $e->planned_duration_seconds])->values()->all(),
        ])->all();
    }

    /** A held replacement: the coach describes a workout, there is a plan on the day, the user asks to add it. */
    private function held(User $user): array
    {
        $conversation = $this->conversation($user);
        $this->say($user, 'Give me a quick upper body workout', $conversation, new AiResponse(text: self::DESCRIBED));
        $this->existingPlan($user);

        return [$conversation, $this->say($user, 'Add this workout to my dashboard', $conversation, $this->saveCall(), new AiResponse(text: 'Done!'))];
    }

    // ---- the question, in the user's language, with the same signed proposal ------------------------------------------------

    public function test_a_replacement_is_asked_about_in_the_users_language_and_the_signed_proposal_is_the_same(): void
    {
        $outcomes = [];

        foreach (['en', 'es'] as $locale) {
            $user = $this->userWho($locale);
            [, $question] = $this->held($user);

            $signed = $question->meta[PendingProposalStore::REPLACEMENT_META_KEY];
            $this->assertTrue(PlanReplacementProposal::fromMeta($signed)->isAuthentic(), "{$locale}: the signature verifies");

            // The stored fields: identical in both languages (the proposal is never translated), apart from ids and clocks.
            $change = $signed['changes'][0];
            unset($change['existing_plan_id'], $change['existing_revision']);
            $outcomes[$locale] = [$change, array_keys($signed), $this->codes($question), $this->plansOf($user), ApplicationNotices::modelText($question)];
            $outcomes[$locale.'_text'] = $question->content;
        }

        $this->assertSame($outcomes['en'], $outcomes['es'], 'Same stored proposal, same notice codes, same plan in the database, no model words left.');
        $this->assertSame([NoticeCode::REPLACEMENT_QUESTION], $outcomes['en'][2]);
        $this->assertStringStartsWith("I haven't changed your plan. ", $outcomes['en_text']);
        $this->assertStringContainsString('Reply "yes" within 15 minutes to replace it with exactly that', $outcomes['en_text']);
        $this->assertStringStartsWith('No he cambiado tu plan. El ', $outcomes['es_text']);
        $this->assertStringContainsString('Responde "yes" en los próximos 15 minutos para reemplazarlo exactamente por eso', $outcomes['es_text']);
        $this->assertStringContainsString('Por ahora, las frases entre comillas deben escribirse en inglés.', $outcomes['es_text']);
    }

    public function test_the_english_yes_the_spanish_question_asks_for_confirms_exactly_what_was_shown(): void
    {
        $user = $this->userWho('es');
        [$conversation] = $this->held($user);

        $done = $this->say($user, 'yes', $conversation);

        $this->assertSame('Upper body', WorkoutPlan::where('user_id', $user->id)->first()->title);
        $this->assertStringStartsWith('Listo. Reemplacé “Old plan” (Squats 3×10) el ', $done->content);
        $this->assertStringEndsWith('Comprobé que quedó guardado.', $done->content);
        $this->assertSame([NoticeCode::REPLACEMENT_APPLIED], $this->codes($done));
        $this->assertSame('applied', $done->meta['plan_replacement']);
        $this->assertSame('', ApplicationNotices::modelText($done));
    }

    public function test_a_spanish_si_confirms_nothing_and_leaves_the_question_unanswered(): void
    {
        $user = $this->userWho('es');
        [$conversation] = $this->held($user);

        $this->say($user, 'sí', $conversation, new AiResponse(text: 'Entendido.'));
        $this->assertSame('Old plan', WorkoutPlan::where('user_id', $user->id)->first()->title, 'Only what the parsers read confirms, and the notice said what that is.');

        // The question is no longer the message just before: a later "yes" confirms nothing, and says so in Spanish.
        $later = $this->say($user, 'yes', $conversation);

        $this->assertSame('Old plan', WorkoutPlan::where('user_id', $user->id)->first()->title);
        $this->assertStringContainsString('No hay nada esperando tu confirmación', $later->content);
        $this->assertSame([NoticeCode::NOTHING_PENDING], $this->codes($later));
    }

    public function test_the_application_does_not_treat_its_own_spanish_notice_as_the_users_authorization(): void
    {
        $user = $this->userWho('es');
        [$conversation, $question] = $this->held($user);

        // The notice is text the application wrote: asking to save "this workout" again points at the coach's, not at the notice's.
        $again = $this->say($user, 'Add this workout to my dashboard', $conversation, $this->saveCall(), new AiResponse(text: 'Hecho.'));

        $this->assertSame('Old plan', WorkoutPlan::where('user_id', $user->id)->first()->title, 'Held again; nothing was written.');
        $this->assertSame([NoticeCode::REPLACEMENT_QUESTION], $this->codes($again));
        $this->assertNotSame($question->id, $again->id);
    }

    // ---- mixed history -----------------------------------------------------------------------------------------------------------

    public function test_a_question_asked_in_english_is_answered_in_spanish_after_the_user_switches_language(): void
    {
        $user = $this->userWho('en');
        [$conversation, $question] = $this->held($user);
        $this->assertStringStartsWith("I haven't changed your plan.", $question->content);

        $user->forceFill(['locale' => 'es'])->save();
        $done = $this->say($user->fresh(), 'yes', $conversation);

        $this->assertSame('Upper body', WorkoutPlan::where('user_id', $user->id)->first()->title, 'The pending confirmation redeems exactly as it did.');
        $this->assertStringStartsWith('Listo. Reemplacé', $done->content);
        $this->assertSame([NoticeCode::REPLACEMENT_APPLIED], $this->codes($done));
    }

    public function test_a_question_asked_in_spanish_is_answered_in_english_after_the_user_switches_language(): void
    {
        $user = $this->userWho('es');
        [$conversation] = $this->held($user);

        $user->forceFill(['locale' => 'en'])->save();
        $done = $this->say($user->fresh(), 'yes', $conversation);

        $this->assertSame('Upper body', WorkoutPlan::where('user_id', $user->id)->first()->title);
        $this->assertStringStartsWith('Done. I replaced', $done->content);
    }

    public function test_a_confirmation_asked_by_an_earlier_release_in_english_is_redeemed_by_a_user_who_now_reads_spanish(): void
    {
        $user = $this->userWho('es');
        [$conversation, $question] = $this->held($user);

        // The same reply as an older release stored it: English words, no notice record, the same signed proposal.
        $meta = $question->meta;
        unset($meta[ReplyText::META_KEY]);
        $question->update([
            'content' => "I haven't changed your plan. {$this->today} already has “Old plan” (Squats 3×10). Do you want me to replace it with “Upper body” (Push-ups 3×12, Dumbbell rows 3×10, Plank 30 s)? Reply \"yes\" within 15 minutes to replace it with exactly that; anything else leaves it as it is.",
            'meta' => $meta,
        ]);

        $done = $this->say($user, 'yes', $conversation);

        $this->assertSame('Upper body', WorkoutPlan::where('user_id', $user->id)->first()->title);
        $this->assertStringStartsWith('Listo.', $done->content);
    }

    public function test_an_old_english_notice_in_a_spanish_users_history_is_never_read_as_a_workout(): void
    {
        $user = $this->userWho('es');
        $conversation = $this->conversation($user);
        Message::create(['conversation_id' => $conversation, 'role' => 'user', 'content' => 'Algún consejo de recuperación?']);
        Message::create(['conversation_id' => $conversation, 'role' => 'assistant', 'content' => "El descanso también es entrenamiento.\n\nI haven't changed your plan: the workout was not saved. For example:\n1. Burpees 5x20\n2. Lunges 3x10"]);

        $turn = $this->say($user, 'Add this workout to my dashboard', $conversation, new AiResponse(text: 'Agregado!'));

        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count());
        $this->assertStringStartsWith('No he cambiado tu plan: no veo un entrenamiento en mi última respuesta', $turn->content);
        $this->assertSame([NoticeCode::SAVE_QUESTION_PREFIX.'no_workout_to_save'], $this->codes($turn));
    }

    // ---- saving, failing and ranges ------------------------------------------------------------------------------------------------

    public function test_a_failed_save_is_told_in_spanish_and_the_retry_saves_the_coachs_workout_not_the_notices(): void
    {
        $user = $this->userWho('es');
        $conversation = $this->conversation($user);
        $this->say($user, 'Dame un entrenamiento de tren superior', $conversation, new AiResponse(text: "Aquí tienes una sesión de tren superior:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds"));

        // The model never calls the tool: nothing is saved, and the application says so.
        $failed = $this->say($user, 'Add this workout to my dashboard', $conversation, new AiResponse(text: 'Ya lo agregué a tu plan.'));

        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count());
        $this->assertSame([NoticeCode::SAVE_FAILED], $this->codes($failed), 'The false claim is not corrected a second time: the notice already says nothing changed.');
        $this->assertStringContainsString('No he cambiado tu plan: el entrenamiento no se guardó. Pídemelo de nuevo con "add this workout to my dashboard"', $failed->content);
        $this->assertStringContainsString('Por ahora, las frases entre comillas deben escribirse en inglés.', $failed->content);

        $before = count($this->fake->calls);
        $this->say($user, 'try again', $conversation, $this->saveCall(), new AiResponse(text: 'Listo.'));

        $this->assertStringNotContainsString('Ya lo agregué', $this->fake->calls[$before]['messages'][0]['content'], 'Nothing the application or a false claim said is the workout.');
        $this->assertSame(['Push-ups', 'Dumbbell rows', 'Plank'], WorkoutPlan::where('user_id', $user->id)->first()->exercises->sortBy('position')->pluck('exercise_name')->all());
    }

    public function test_ranges_are_stored_the_same_and_explained_in_the_users_language(): void
    {
        $outcomes = [];
        $notes = [];

        foreach (['en', 'es'] as $locale) {
            $user = $this->userWho($locale);
            $conversation = $this->conversation($user);
            $this->say($user, 'Give me a short session', $conversation, new AiResponse(text: self::WITH_RANGES));

            $saved = $this->say($user, 'Add this workout to my dashboard', $conversation, $this->saveCall([
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 8],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ], 'Short session'), new AiResponse(text: 'Saved!'));

            $outcomes[$locale] = [$this->plansOf($user), $this->codes($saved)];
            $notes[$locale] = $saved->content;
        }

        $this->assertSame($outcomes['en'], $outcomes['es'], 'The same low end of each range is stored: same slugs, same numbers.');
        $this->assertSame([NoticeCode::RANGE_NOTE], $outcomes['en'][1]);
        $this->assertStringContainsString('I saved the low end of every range: Goblet Squat 8 reps (written 8-12); Plank 30 seconds (written 30-45).', $notes['en']);
        $this->assertStringContainsString('guardé el extremo inferior de cada rango: Goblet Squat 8 repeticiones (escrito 8-12); Plank 30 segundos (escrito 30-45).', $notes['es']);
    }

    // ---- nothing leaks between users or requests ---------------------------------------------------------------------------------------

    public function test_two_users_in_different_languages_never_see_each_others_language(): void
    {
        $spanish = $this->userWho('es');
        $english = $this->userWho('en');
        $conversations = [$this->conversation($spanish), $this->conversation($english)];

        for ($round = 0; $round < 2; $round++) {
            // Interleaved, and the Spanish user's client even names English in the header: the saved language decides.
            Cache::flush();
            $this->fake->queue(new AiResponse(text: 'Ok.'));
            $this->actingAs($spanish, 'sanctum')->postJson("/api/conversations/{$conversations[0]}/messages", ['content' => 'Add this workout to my dashboard'], ['X-App-Locale' => 'en'])->assertCreated();
            Cache::flush();
            $this->fake->queue(new AiResponse(text: 'Ok.'));
            $this->actingAs($english, 'sanctum')->postJson("/api/conversations/{$conversations[1]}/messages", ['content' => 'Add this workout to my dashboard'], ['X-App-Locale' => 'es'])->assertCreated();

            $es = Message::where('conversation_id', $conversations[0])->where('role', 'assistant')->latest('id')->first();
            $en = Message::where('conversation_id', $conversations[1])->where('role', 'assistant')->latest('id')->first();

            $this->assertStringStartsWith('No he cambiado tu plan: no veo un entrenamiento', $es->content);
            $this->assertStringStartsWith("I haven't changed your plan: I don't see a workout", $en->content);
            $this->assertSame($this->codes($es), $this->codes($en), 'Same notice, same code.');
        }

        $this->assertSame(0, WorkoutPlan::count());
    }

    public function test_a_users_language_is_never_taken_from_what_the_user_wrote(): void
    {
        $user = $this->userWho('en');
        $conversation = $this->conversation($user);

        $turn = $this->say($user, 'Añade este entrenamiento a mi dashboard por favor', $conversation, new AiResponse(text: 'Claro.'));

        $this->assertStringNotContainsString('No he cambiado', $turn->content, 'Writing in Spanish does not change the language the notices are in; the setting does.');
    }

    // ---- other surfaces --------------------------------------------------------------------------------------------------------------------

    public function test_the_dashboard_fallback_is_in_the_users_language(): void
    {
        foreach (['en' => "Log today's activity and I'll tailor a recommendation.", 'es' => 'Registra tu actividad de hoy y prepararé una recomendación para ti.'] as $locale => $expected) {
            $user = $this->userWho($locale);
            $this->fake->queue(new AiResponse(text: null));

            $this->assertSame($expected, app(AiCoachService::class)->dashboardRecommendation($user), $locale);
        }
    }

    public function test_the_weekly_plan_notes_are_in_the_users_language(): void
    {
        foreach (['en' => ['No change needed.', 'Could not determine a plan change from that — try rephrasing.'], 'es' => ['No hace falta ningún cambio.', 'No pude determinar un cambio en el plan a partir de eso; intenta reformularlo.']] as $locale => [$noChange, $notDetermined]) {
            $user = $this->userWho($locale);

            $this->fake->queue(new AiResponse(text: ''));
            $this->assertSame($noChange, app(WeeklyPlanCoachService::class)->run($user, 'Nothing changed this week.')['explanation'], $locale);

            // Tool calls forever, never a decision: it gives up in the user's language.
            for ($i = 0; $i < 3; $i++) {
                $this->fake->queue(new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: "r{$i}", name: 'get_user_profile', arguments: [])]));
            }
            $this->assertSame($notDetermined, app(WeeklyPlanCoachService::class)->run($user, 'Adjust my week.')['explanation'], $locale);
        }
    }
}
