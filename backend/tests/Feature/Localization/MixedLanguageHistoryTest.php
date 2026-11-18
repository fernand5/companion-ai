<?php

namespace Tests\Feature\Localization;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\Notices\ApplicationNotices;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\ReplyText;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3d: a user who changes language mid-conversation, and confirmations minted in the other language.
 *
 *  - A "yes" to a replacement question asked in one language is honoured after the account switches to the other,
 *    and answered in the language the account has NOW. The sealed proposal carries no language.
 *  - A history that mixes English and Spanish notices is read through the structured notice record (M3a), so neither
 *    language's notice text is ever mistaken for the model's workout.
 *  - What a stored proposal holds is frozen: it has no language field and the same keys it has always had.
 */
class MixedLanguageHistoryTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    private const DESCRIBED = "Here's a 30-minute upper body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
    }

    private function in(string $language): void
    {
        $this->user->update(['locale' => $language]);
        $this->user = $this->user->fresh();
        $this->app['auth']->forgetGuards();
    }

    private function say(string $message, int $conversation, AiResponse ...$responses): Message
    {
        Cache::flush(); // chat allows ten messages a minute; these scenarios loop
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return $this->last($conversation);
    }

    private function last(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail();
    }

    private function saveCall(): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'Saved as asked.', 'reasoning_factors' => ['You asked to add it'],
            'exercises' => [
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ])]);
    }

    private function planOn(string $date): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $date)->first();
    }

    /** A conversation up to the question "replace today's plan?", asked while the account was in $language. */
    private function askedToReplaceIn(string $language): int
    {
        $this->in($language);
        $this->fake->queue(new AiResponse(text: self::DESCRIBED));
        $conversation = $this->ask($this->user, 'Give me a quick upper body workout')['conversation'];

        app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Old plan',
            'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ], replaceExisting: true);

        $question = $this->say('Add this workout to my dashboard', $conversation, $this->saveCall(), new AiResponse(text: 'Done!'));
        $this->assertIsArray($question->meta[PendingProposalStore::REPLACEMENT_META_KEY] ?? null, 'Precondition: the application asked.');
        $this->assertSame('Old plan', $this->planOn($this->today)->title, 'Precondition: nothing replaced yet.');

        return $conversation;
    }

    // ---- confirmations across a language switch ----------------------------------------------------------------

    public function test_a_question_asked_in_english_is_confirmed_in_spanish_after_the_account_switches(): void
    {
        $conversation = $this->askedToReplaceIn('en');
        $question = $this->last($conversation);

        $this->in('es');
        $answer = $this->say('yes', $conversation);

        $this->assertSame('Upper body', $this->planOn($this->today)->title, 'The replacement the English question described was applied.');
        $this->assertSame('applied', $answer->meta['plan_replacement']);
        $this->assertSame([NoticeCode::REPLACEMENT_APPLIED], array_column($answer->meta[ReplyText::META_KEY]['segments'], 'code'));
        $this->assertStringContainsString('reemplac', mb_strtolower($answer->content), 'Answered in the language the account has now.');
        $this->assertStringNotContainsString('replaced', $answer->content);
        $this->assertNotSame($question->content, $answer->content);
    }

    public function test_a_question_asked_in_spanish_is_confirmed_in_english_after_the_account_switches(): void
    {
        $conversation = $this->askedToReplaceIn('es');
        $this->assertStringContainsString('reemplaz', mb_strtolower($this->last($conversation)->content), 'Precondition: the question was Spanish.');

        $this->in('en');
        $answer = $this->say('yes', $conversation);

        $this->assertSame('Upper body', $this->planOn($this->today)->title);
        $this->assertSame('applied', $answer->meta['plan_replacement']);
        $this->assertStringContainsString('replaced', $answer->content);
    }

    public function test_the_stored_proposal_has_no_language_and_keeps_its_established_shape(): void
    {
        $conversation = $this->askedToReplaceIn('es');
        $meta = $this->last($conversation)->meta[PendingProposalStore::REPLACEMENT_META_KEY];

        // The keys a proposal minted before the localization work had. Nothing was added (no locale, no wording).
        $this->assertEquals(PlanReplacementProposal::fromMeta($meta)?->toMeta(), $meta, 'It round-trips unchanged: sealed, with no language in it.');
        // (A JSON column may store keys in another order: compare the sets.)
        $keys = fn (array $a) => collect(array_keys($a))->sort()->values()->all();
        $this->assertSame($keys(array_flip(['version', 'user_id', 'conversation_id', 'operation', 'changes', 'created_at', 'expires_at', 'signature'])), $keys($meta), 'The stored keys a proposal has always had.');
        $this->assertSame($keys(array_flip(['date', 'existing_plan_id', 'existing_revision', 'existing_summary', 'action', 'reason', 'replacement'])), $keys($meta['changes'][0]));
        $this->assertStringNotContainsString('locale', json_encode(array_keys($meta)));
        $this->assertStringNotContainsStringIgnoringCase('"es"', json_encode($meta));
    }

    public function test_a_proposal_sealed_for_one_user_still_cannot_be_redeemed_by_another_whatever_the_language(): void
    {
        $conversation = $this->askedToReplaceIn('en');
        $other = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
        $this->app['auth']->forgetGuards();

        // The other user cannot even post into this conversation, in either language.
        Cache::flush();
        $this->actingAs($other, 'sanctum')->postJson("/api/conversations/{$conversation}/messages", ['content' => 'yes'])->assertStatus(403);
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
    }

    // ---- a confirmation minted before the localization work ---------------------------------------------------------

    /**
     * A real "replace today's plan?" question, captured from the application as it was before Phase 7 / M3c
     * (commit d758a15): the exact assistant message, its sealed proposal and its notice record. Same user, conversation
     * and plan ids, the same fixed clock and key as when it was minted.
     */
    private function oldQuestion(string $language): int
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'America/Bogota')); // ten minutes into its fifteen
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/pre_m3c_replacement_proposal.json')), true, flags: JSON_THROW_ON_ERROR);

        // The proposal is signed over these ids, so the rows get the ids they had when it was minted (a database that
        // does not restart its counters between tests, such as MySQL, would otherwise number them differently).
        [$userId, $conversationId, $planId] = $fixture['ids'];
        User::query()->whereKey($this->user->id)->delete();
        $this->user = User::factory()->create(['id' => $userId, 'timezone' => 'America/Bogota', 'locale' => $language]);
        $this->app['auth']->forgetGuards();

        $conversation = Conversation::forceCreate(['id' => $conversationId, 'user_id' => $userId, 'title' => 'old']);

        $plan = WorkoutPlan::forceCreate([
            'id' => $planId, 'user_id' => $userId, 'planned_date' => $this->user->localToday(), 'activity_type' => 'strength',
            'title' => 'Old plan', 'status' => WorkoutPlan::STATUS_PLANNED, 'source' => 'ai', 'duration_minutes' => null,
        ]);
        WorkoutPlanExercise::forceCreate([
            'id' => 1, 'workout_plan_id' => $planId, 'exercise_name' => 'Squats', 'exercise_slug' => 'squats', 'planned_sets' => 3, 'planned_reps' => 10,
            'planned_weight_kg' => null, 'planned_duration_seconds' => null, 'status' => WorkoutPlanExercise::STATUS_PENDING,
        ]);
        $this->assertSame($fixture['meta']['pending_plan_replacement']['changes'][0]['existing_revision'], $plan->fresh()->revision(), 'The plan is the one the proposal was minted against.');

        Message::create(['conversation_id' => $conversation->id, 'role' => Message::ROLE_ASSISTANT, 'content' => $fixture['content'], 'meta' => $fixture['meta']]);

        return $conversation->id;
    }

    public static function accountLanguages(): array
    {
        return ['english account' => ['en', 'replaced'], 'spanish account' => ['es', 'reemplac']];
    }

    #[DataProvider('accountLanguages')]
    public function test_a_confirmation_minted_before_the_localization_work_is_still_honoured_in_either_language(string $language, string $word): void
    {
        $conversation = $this->oldQuestion($language);
        $answer = $this->say('yes', $conversation);

        $this->assertSame('Upper body', $this->planOn($this->user->localToday())->title, "{$language}: the old proposal was redeemed.");
        $this->assertSame('applied', $answer->meta['plan_replacement']);
        $this->assertStringContainsString($word, mb_strtolower($answer->content), "{$language}: answered in the account's language.");
    }

    #[DataProvider('accountLanguages')]
    public function test_an_old_proposal_that_was_altered_is_refused_in_either_language(string $language): void
    {
        $conversation = $this->oldQuestion($language);
        $message = $this->last($conversation);
        $meta = $message->meta;
        $meta[PendingProposalStore::REPLACEMENT_META_KEY]['changes'][0]['replacement']['title'] = 'Something else';
        $message->update(['meta' => $meta]);

        $answer = $this->say('yes', $conversation);

        $this->assertSame('Old plan', $this->planOn($this->user->localToday())->title, "{$language}: a tampered proposal changes nothing.");
        $this->assertSame('invalid', $answer->meta['plan_replacement']);
    }

    // ---- a history that mixes the two languages' notices ------------------------------------------------------------

    private function describedWithNotice(int $conversation, string $notice, string $code): Message
    {
        $reply = ReplyText::fromModel(self::DESCRIBED)->with($code, $notice);

        return Message::create(['conversation_id' => $conversation, 'role' => Message::ROLE_ASSISTANT, 'content' => $reply->text(), 'meta' => $reply->meta()]);
    }

    public function test_notices_in_either_language_are_never_taken_for_the_models_workout(): void
    {
        $this->in('es');
        $conversation = $this->user->conversations()->create(['title' => 'mixed'])->id;

        // An earlier English turn, then a Spanish one, each ending in the application's own notice. The notices
        // contain numbered, exercise-looking words that a parser reading the text would take for exercises.
        $this->describedWithNotice($conversation, "I haven't changed your plan: nothing was saved this time.\n1. Say \"yes\" 3x within 15 minutes", NoticeCode::SAVE_FAILED);
        Message::create(['conversation_id' => $conversation, 'role' => Message::ROLE_USER, 'content' => 'Gracias, ¿y para mañana?']);
        $spanish = $this->describedWithNotice($conversation, "No he cambiado tu plan: esta vez no se guardó nada.\n1. Responde \"yes\" 3x en 15 minutos", NoticeCode::SAVE_FAILED);

        $this->assertNotSame(self::DESCRIBED, $spanish->content);
        $this->assertSame(self::DESCRIBED, ApplicationNotices::modelText($spanish), 'The model\'s part only, whichever language the notice is in.');

        $this->say('Add this workout to my dashboard', $conversation, $this->saveCall(), new AiResponse(text: 'Hecho.'));

        $plan = $this->planOn($this->today);
        $this->assertNotNull($plan, 'The workout the model described was saved.');
        $this->assertSame(['Push-ups', 'Dumbbell rows', 'Plank'], $plan->exercises()->orderBy('id')->pluck('exercise_name')->all(), 'Nothing from either notice became an exercise.');
    }

    public function test_the_notice_record_of_old_english_messages_is_read_the_same_after_the_account_switches(): void
    {
        $this->in('en');
        $conversation = $this->user->conversations()->create(['title' => 'switch'])->id;
        $english = $this->describedWithNotice($conversation, "I haven't changed your plan: nothing was saved this time.", NoticeCode::SAVE_FAILED);

        $before = ApplicationNotices::modelText($english);
        $this->in('es');

        $this->assertSame($before, ApplicationNotices::modelText($english->fresh()), 'Reading a stored notice does not depend on the account\'s current language.');
        $this->assertSame(self::DESCRIBED, $before);
    }
}
