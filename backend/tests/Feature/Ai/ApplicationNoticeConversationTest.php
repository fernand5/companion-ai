<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\Notices\ApplicationNotices;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\ReplyText;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3a, end to end through the real endpoint with a scripted model: the application's own words under a coach
 * reply are recorded as notices, never read as a workout or a request, whatever language they are in or whether they were
 * recorded at all; and what the model claims about the plan is checked against what the turn actually did.
 */
class ApplicationNoticeConversationTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    private const DESCRIBED = "Here's a 30-minute upper body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds";

    private const NOTICE_WORKOUT = "1. Burpees 5x20\n2. Lunges 3x10";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
        Cache::flush();
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function saveCall(?string $date = null, string $title = 'Upper body', ?array $exercises = null): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'Saved as asked.', 'reasoning_factors' => ['You asked to add it'],
            'planned_date' => $date,
            'exercises' => $exercises ?? [
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ]))]);
    }

    private function conversation(?User $as = null): int
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
    }

    private function coachDescribesAWorkout(?User $user = null, string $text = self::DESCRIBED): int
    {
        Cache::flush();
        $this->fake->queue(new AiResponse(text: $text));

        return $this->ask($user ?? $this->user, 'Give me a quick upper body workout')['conversation'];
    }

    /** @return array{result: array, calls: int, message: Message} */
    private function say(string $message, int $conversation, ?User $as = null, AiResponse ...$responses): array
    {
        Cache::flush();
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($as ?? $this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return ['result' => $result, 'calls' => count($this->fake->calls) - $before, 'message' => $this->lastAssistant($conversation)];
    }

    private function lastAssistant(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail();
    }

    private function history(int $conversation, string $role, string $content, ?array $meta = null): Message
    {
        return Message::create(['conversation_id' => $conversation, 'role' => $role, 'content' => $content, 'meta' => $meta]);
    }

    /** An assistant message as this release stores it: the model's words, then recorded notices. */
    private function storedReply(int $conversation, string $model, array $notices, array $more = []): Message
    {
        $reply = ReplyText::fromModel($model);

        foreach ($notices as $code => $text) {
            $reply->with($code, $text);
        }

        return $this->history($conversation, 'assistant', $reply->text(), $reply->meta() + $more);
    }

    private function existingPlan(string $title = 'Old plan', ?User $user = null): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($user ?? $this->user, [
            'planned_date' => $this->today, 'activity_type' => 'strength', 'title' => $title,
            'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ], replaceExisting: true);
    }

    private function planOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    private function plans(?User $user = null): int
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function record(Message $message): array
    {
        return $message->meta[ReplyText::META_KEY] ?? [];
    }

    private function codes(Message $message): array
    {
        return array_column($this->record($message)['segments'] ?? [], 'code');
    }

    private const NO_WORKOUT = "I haven't changed your plan: I don't see a workout in my last reply";

    // ---- structured notices are created and persisted by the real flow -----------------------------------------------

    public function test_a_question_the_application_asks_is_recorded_as_one_notice_covering_the_whole_reply(): void
    {
        $turn = $this->say('Add this workout to my dashboard', $this->conversation());

        $this->assertSame(0, $turn['calls']);
        $this->assertSame(['save_question_no_workout_to_save'], $this->codes($turn['message']));
        // assertEquals: a JSON column may reorder an object's keys, which is why the reader looks them up by name.
        $this->assertEquals([['code' => 'save_question_no_workout_to_save', 'start' => 0, 'end' => mb_strlen($turn['message']->content)]], $this->record($turn['message'])['segments']);
        $this->assertSame('', ApplicationNotices::modelText($turn['message']));
        $this->assertTrue($turn['message']->meta['save_clarification'], 'The existing flags are still stored beside the record.');
    }

    public function test_a_plain_coach_reply_has_no_notice_record(): void
    {
        $conversation = $this->conversation();
        $turn = $this->say('How was my week?', $conversation, null, new AiResponse(text: 'Solid week, keep it up.'));

        $this->assertSame('Solid week, keep it up.', $turn['message']->content);
        $this->assertNull($turn['message']->meta);
    }

    public function test_a_save_that_did_not_happen_is_recorded_under_the_coachs_words(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->fake->queue(new AiResponse(text: 'I cannot do that.'));

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'I cannot do that.'));

        $this->assertSame(0, $this->plans());
        $this->assertSame([NoticeCode::SAVE_FAILED], $this->codes($turn['message']));
        $this->assertSame('I cannot do that.', ApplicationNotices::modelText($turn['message']));
        $this->assertStringContainsString("I haven't changed your plan: the workout was not saved.", $turn['message']->content);
    }

    public function test_several_notices_from_one_turn_are_each_recorded_and_the_models_words_are_what_remains(): void
    {
        $conversation = $this->coachDescribesAWorkout(null, "Try this:\n1. Goblet Squat 3x10 at 24 kg\n2. Push-ups 3x12");
        $this->existingPlan();

        $turn = $this->say("Replace today's workout with this one", $conversation, null, $this->saveCall(null, 'Legs', [
            ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24],
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
        ]), new AiResponse(text: 'Done.'));

        $this->assertSame('Legs', $this->planOn($this->today)->title);
        $this->assertSame([NoticeCode::REPLACED_AS_ASKED, NoticeCode::LOAD_LEFT_UNSET], $this->codes($turn['message']));
        $this->assertStringContainsString('As you asked, I replaced', $turn['message']->content);
        $this->assertStringContainsString('I left the starting weight for Goblet Squat unset', $turn['message']->content);
        $this->assertSame('Done.', ApplicationNotices::modelText($turn['message']));
    }

    public function test_every_kind_of_stored_notice_verifies_when_read_back(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done! I replaced your old plan.'));

        $this->assertSame([NoticeCode::REPLACEMENT_QUESTION], $this->codes($turn['message']));
        $this->assertSame('', ApplicationNotices::modelText($turn['message']), "The model's 'Done!' is replaced by the application's question, so no model words remain.");

        $confirmed = $this->say('yes', $conversation);

        $this->assertSame([NoticeCode::REPLACEMENT_APPLIED], $this->codes($confirmed['message']));
        $this->assertSame('applied', $confirmed['message']->meta['plan_replacement'], 'The existing status is kept beside the record.');
        $this->assertSame('', ApplicationNotices::modelText($confirmed['message']));
    }

    // ---- signed proposals are untouched ------------------------------------------------------------------------------

    public function test_a_signed_proposal_serialises_exactly_as_before_and_still_verifies(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));

        $proposal = PlanReplacementProposal::create(7, 9, [[
            'date' => '2026-11-04', 'existing_plan_id' => 3, 'existing_revision' => 'abc', 'existing_summary' => '“Old plan” (Squats 3×10)',
            'action' => 'replace', 'reason' => '',
            'replacement' => ['planned_date' => '2026-11-04', 'activity_type' => 'strength', 'title' => 'Upper body', 'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]],
        ]]);

        // Captured from the code before this release: the serialized form and its signature must not move.
        $golden = '{"version":2,"user_id":7,"conversation_id":9,"operation":"replace_day_plans","changes":[{"date":"2026-11-04","existing_plan_id":3,"existing_revision":"abc","existing_summary":"“Old plan” (Squats 3×10)","action":"replace","reason":"","replacement":{"activity_type":"strength","title":"Upper body","duration_minutes":null,"reasoning":null,"reasoning_factors":[],"exercises":[{"exercise_name":"Push-ups","planned_sets":3,"planned_reps":12,"planned_weight_kg":null,"planned_duration_seconds":null}]}}],"created_at":"2026-11-04T12:00:00+00:00","expires_at":"2026-11-04T12:15:00+00:00","signature":"e222b40224e7300ceb2156db2dde266c417bd7db81b020c305ea745e8992a6db"}';

        $this->assertSame($golden, json_encode($proposal->toMeta(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertTrue(PlanReplacementProposal::fromMeta(json_decode($golden, true))->isAuthentic());

        CarbonImmutable::setTestNow();
    }

    public function test_the_signed_proposal_stored_with_a_question_is_authentic_and_has_no_notice_data_inside_it(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

        $meta = $this->lastAssistant($conversation)->meta;
        $signed = $meta[PendingProposalStore::REPLACEMENT_META_KEY];

        $this->assertEqualsCanonicalizing(['version', 'user_id', 'conversation_id', 'operation', 'changes', 'created_at', 'expires_at', 'signature'], array_keys(array_diff_key($signed, ['consumed_at' => 1])));
        $this->assertTrue(PlanReplacementProposal::fromMeta($signed)->isAuthentic());
        $this->assertStringNotContainsString('application_notices', json_encode($signed));
        $this->assertSame('“Old plan” (Squats 3×10)', $signed['changes'][0]['existing_summary'], 'Stored text is not translated or reworded.');
    }

    public function test_a_confirmation_asked_before_records_existed_is_still_valid(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

        // The same reply as an older release stored it: no notice record.
        $question = $this->lastAssistant($conversation);
        $meta = $question->meta;
        unset($meta[ReplyText::META_KEY]);
        $question->update(['meta' => $meta]);

        $this->say('yes', $conversation);

        $this->assertSame('Upper body', $this->planOn($this->today)->title, 'The pending confirmation redeems exactly as it did.');
    }

    // ---- an example workout inside a notice is never a workout ---------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function noticeShapes(): array
    {
        return [
            'recorded English' => ['recorded_en'],
            'recorded Spanish' => ['recorded_es'],
            'recorded, language unknown' => ['recorded_xx'],
            'historical save failure' => ['legacy_failed'],
            'historical range note' => ['legacy_range'],
            'historical "to be clear"' => ['legacy_clear'],
            'historical replacement question' => ['legacy_held'],
        ];
    }

    /** A coach reply that holds no workout, with a notice under it that does (as examples, dates and quotes do). */
    private function replyWithWorkoutOnlyInANotice(int $conversation, string $shape): Message
    {
        $coach = 'Rest is part of training; keep your sleep steady.';

        return match ($shape) {
            'recorded_en' => $this->storedReply($conversation, $coach, [NoticeCode::SAVE_FAILED => "I haven't changed your plan. For example:\n".self::NOTICE_WORKOUT]),
            'recorded_es' => $this->storedReply($conversation, $coach, [NoticeCode::SAVE_FAILED => "No he cambiado tu plan. Por ejemplo:\n".self::NOTICE_WORKOUT]),
            'recorded_xx' => $this->storedReply($conversation, $coach, [NoticeCode::SAVE_FAILED => "Pas de changement. Exemple :\n".self::NOTICE_WORKOUT]),
            'legacy_failed' => $this->history($conversation, 'assistant', $coach."\n\nI haven't changed your plan: the workout was not saved. For example:\n".self::NOTICE_WORKOUT),
            'legacy_range' => $this->history($conversation, 'assistant', $coach."\n\nYour plan keeps one number for each of these, so I saved the low end of every range:\n".self::NOTICE_WORKOUT),
            'legacy_clear' => $this->history($conversation, 'assistant', $coach."\n\nTo be clear, that is not a limit of the app: I can add workouts. For example:\n".self::NOTICE_WORKOUT),
            'legacy_held' => $this->history($conversation, 'assistant', $coach."\n\nI haven't changed your plan yet. These days already have a plan:\n".self::NOTICE_WORKOUT),
        };
    }

    #[DataProvider('noticeShapes')]
    public function test_an_example_workout_inside_an_application_notice_is_never_selected_to_be_saved(string $shape): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Any advice for recovery?');
        $this->replyWithWorkoutOnlyInANotice($conversation, $shape);

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'Added!'));

        $this->assertSame(0, $turn['calls'], 'The application asks; the model is not given a workout to save.');
        $this->assertSame(0, $this->plans(), $shape);
        $this->assertStringStartsWith(self::NO_WORKOUT, $turn['message']->content, $shape);
    }

    public function test_the_same_workout_in_the_coachs_own_words_is_found_which_shows_the_check_is_the_notice_filter(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Any advice for recovery?');
        // Control: an unrecorded sentence that is NOT one of the application's, so it is the coach's.
        $this->history($conversation, 'assistant', "Rest is part of training.\n\nFor example:\n".self::NOTICE_WORKOUT);

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'Added!'));

        $this->assertSame(1, $turn['calls'], 'A workout in the coach reply is resolved, so the model is asked to save exactly it.');
        $this->assertStringContainsString('Burpees', $this->fake->calls[array_key_last($this->fake->calls)]['messages'][0]['content']);
    }

    public function test_a_retry_after_an_application_notice_saves_the_coachs_workout_and_never_the_notices_example(): void
    {
        foreach (['recorded' => true, 'historical' => false] as $label => $recorded) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $conversation = $this->conversation();
            $this->history($conversation, 'user', 'Give me a quick upper body workout');
            $this->history($conversation, 'assistant', self::DESCRIBED);
            $this->history($conversation, 'user', 'Add this workout to my dashboard');

            if ($recorded) {
                $this->storedReply($conversation, 'I cannot do that.', [NoticeCode::SAVE_FAILED => "I haven't changed your plan: the workout was not saved. Example:\n".self::NOTICE_WORKOUT]);
            } else {
                $this->history($conversation, 'assistant', "I cannot do that.\n\nI haven't changed your plan: the workout was not saved. Example:\n".self::NOTICE_WORKOUT);
            }

            $before = count($this->fake->calls);
            $this->say('try again', $conversation, null, $this->saveCall(), new AiResponse(text: 'Added.'));
            $prompt = $this->fake->calls[$before]['messages'][0]['content'];

            $this->assertStringContainsString('Push-ups', $prompt, $label);
            $this->assertStringNotContainsString('Burpees', $prompt, "{$label}: the notice's example is not what the model is told to save.");
            $this->assertSame(['Push-ups', 'Dumbbell rows', 'Plank'], $this->planOn($this->today)?->exercises->pluck('exercise_name')->all(), $label);
        }
    }

    public function test_a_yes_after_a_notice_confirms_nothing_unless_a_signed_proposal_is_pending(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Add this workout to my dashboard');
        // A notice that LOOKS like a held confirmation, recorded, but with no signed proposal behind it.
        $this->storedReply($conversation, 'Sure.', [NoticeCode::REPLACEMENT_QUESTION => "I haven't changed your plan. Do you want me to replace it with “Burpees” (Burpees 5x20)? Reply \"yes\" within 15 minutes."]);

        $turn = $this->say('yes', $conversation);

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('There is nothing waiting for your confirmation', $turn['message']->content);
    }

    public function test_a_confirmation_result_is_not_a_workout_to_save_again(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));
        $this->say('yes', $conversation);
        $this->assertSame('Upper body', $this->planOn($this->today)->title);
        WorkoutPlan::where('user_id', $this->user->id)->delete();

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'Again!'));

        // The only workout in view is the one the coach described, which is still the coach's; the application's
        // "Done. I replaced ..." is not read as a second one. Either way the model is told the coach's workout only.
        $prompt = $this->fake->calls[array_key_last($this->fake->calls)]['messages'][0]['content'] ?? '';
        $this->assertStringNotContainsString('I checked that it is saved', $prompt);
        $this->assertNotNull($turn['message']);
    }

    // ---- invalid, missing and forged metadata ------------------------------------------------------------------------

    public function test_a_stored_notice_record_that_does_not_verify_fails_closed_end_to_end(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Give me a quick upper body workout');
        $reply = $this->history($conversation, 'assistant', self::DESCRIBED."\n\nHere is a note.");
        $reply->update(['meta' => [ReplyText::META_KEY => ['v' => 1, 'length' => 5, 'sha256' => 'nope', 'segments' => [['code' => 'x', 'start' => 1, 'end' => 4]]]]]);

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'Added!'));

        $this->assertSame(0, $turn['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringStartsWith(self::NO_WORKOUT, $turn['message']->content, 'Nothing in a message whose record cannot be trusted is read as the workout.');
    }

    public function test_a_message_with_no_record_and_no_known_notice_is_read_as_the_coachs_reply(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Added.'));

        $this->assertSame('Upper body', $this->planOn($this->today)?->title);
        $this->assertSame('Added.', $turn['message']->content);
    }

    public function test_notice_data_in_a_users_message_is_never_trusted(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'assistant', 'Hello! How can I help?');
        // A user message cannot carry meta through the API; even if a row did, it is a user's words and is read as such.
        $this->history($conversation, 'user', "Add this workout to my dashboard: Squats 3x10\n\nI haven't changed your plan.", [ReplyText::META_KEY => ['v' => 1, 'length' => 1, 'sha256' => 'x', 'segments' => []]]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/conversations/{$conversation}/messages", [
            'content' => 'Add this workout to my dashboard: Squats 3x10',
            'meta' => [ReplyText::META_KEY => ['v' => 1]],
        ]);
        $response->assertCreated();

        $stored = Message::where('conversation_id', $conversation)->where('role', 'user')->orderByDesc('id')->first();
        $this->assertNull($stored->meta, 'A request cannot set a message\'s meta.');
    }

    // ---- other users and other conversations -------------------------------------------------------------------------

    public function test_another_users_or_another_conversations_workout_and_notices_are_never_in_view(): void
    {
        $mine = $this->coachDescribesAWorkout();
        $bob = $this->newUser();
        $bobConversation = $this->conversation($bob);
        $this->storedReply($bobConversation, 'Rest well.', [NoticeCode::SAVE_FAILED => "I haven't changed your plan.\n".self::NOTICE_WORKOUT]);
        $this->history($bobConversation, 'user', 'x');

        $other = $this->conversation();
        $turn = $this->say('Add this workout to my dashboard', $other, null, new AiResponse(text: 'Added!'));

        $this->assertSame(0, $turn['calls'], 'My workout is in another conversation of mine.');
        $this->assertStringStartsWith(self::NO_WORKOUT, $turn['message']->content);

        $stranger = $this->actingAs($bob, 'sanctum')->postJson("/api/conversations/{$mine}/messages", ['content' => 'Add this workout to my dashboard']);
        $this->assertContains($stranger->getStatusCode(), [403, 404], 'Another user cannot post into my conversation.');
        $this->assertSame(0, $this->plans($bob));
        $this->assertSame(0, $this->plans());
    }

    public function test_a_record_cannot_be_moved_to_another_message_to_hide_or_expose_text(): void
    {
        $conversation = $this->conversation();
        $donor = $this->storedReply($conversation, 'Short.', [NoticeCode::SAVE_FAILED => 'App words.']);
        $copy = $this->history($conversation, 'assistant', "A longer coach reply with a workout:\n".self::NOTICE_WORKOUT."\n\nAnd more.", $donor->meta);

        $this->assertSame('', ApplicationNotices::modelText($copy), 'A record bound to other content does not verify.');
    }

    // ---- claims about the plan are checked against what happened ------------------------------------------------------

    public function test_an_english_false_save_claim_is_corrected_with_the_original_wording_and_nothing_is_written(): void
    {
        $conversation = $this->conversation();

        $turn = $this->say('How is my week going?', $conversation, null, new AiResponse(text: "Great! I've added it to your dashboard."));

        $this->assertSame("Great! I've added it to your dashboard.\n\nI haven't changed your plan: nothing was saved this time, whatever the message above says. To add a workout I have just shown you, say \"add this workout to my dashboard\".", $turn['message']->content);
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($turn['message']));
        $this->assertSame("Great! I've added it to your dashboard.", ApplicationNotices::modelText($turn['message']));
        $this->assertSame(0, $this->plans(), 'Detecting a claim writes nothing.');
    }

    private function spanishUser(): User
    {
        return User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
    }

    public function test_a_spanish_false_save_claim_is_corrected_in_spanish_for_a_user_who_chose_spanish_and_nothing_is_written(): void
    {
        $user = $this->spanishUser();
        $conversation = $this->conversation($user);

        $turn = $this->say('¿Cómo va mi semana?', $conversation, $user, new AiResponse(text: '¡Genial! Ya lo agregué a tu plan.'));

        $this->assertStringStartsWith('¡Genial! Ya lo agregué a tu plan.', $turn['message']->content);
        $this->assertStringContainsString('No he cambiado tu plan: esta vez no se guardó nada', $turn['message']->content);
        $this->assertStringContainsString('"agrega este entrenamiento a mi plan"', $turn['message']->content, 'The phrase it offers is a Spanish one, and the application reads it (SpanishCommands).');
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($turn['message']));
        $this->assertSame('¡Genial! Ya lo agregué a tu plan.', ApplicationNotices::modelText($turn['message']));
        $this->assertSame(0, $this->plans($user));
    }

    public function test_the_claim_is_recognised_in_either_language_and_the_correction_is_in_the_language_the_user_chose(): void
    {
        foreach (['en' => 'No he cambiado tu plan', 'es' => "I haven't changed your plan"] as $locale => $notThis) {
            $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $locale]);

            $english = $this->say('How is my week?', $this->conversation($user), $user, new AiResponse(text: "I've added it to your dashboard."));
            $spanish = $this->say('¿Cómo va mi semana?', $this->conversation($user), $user, new AiResponse(text: 'Ya lo guardé en tu dashboard.'));

            // Which claims are recognised does not depend on the language; the words of the correction do.
            foreach ([$english, $spanish] as $turn) {
                $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($turn['message']), $locale);
                $this->assertStringNotContainsString($notThis, $turn['message']->content, $locale);
                $this->assertStringContainsString($locale === 'en' ? "I haven't changed your plan: nothing was saved" : 'No he cambiado tu plan: esta vez no se guardó nada', $turn['message']->content, $locale);
            }
        }
    }

    public function test_a_tool_call_the_turn_refused_does_not_make_a_saved_claim_true(): void
    {
        $conversation = $this->conversation();

        // The model tries to write a plan nobody asked for (refused), then says in Spanish that it did.
        $turn = $this->say('¿Cómo va mi semana?', $conversation, null, $this->saveCall($this->today), new AiResponse(text: 'He añadido el entrenamiento a tu plan.'));

        $this->assertSame(0, $this->plans(), 'The turn refused the write.');
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($turn['message']));
        $this->assertNotEmpty($turn['message']->meta['blocked_tool_calls']);
    }

    public function test_a_real_save_is_never_called_false(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'He añadido el entrenamiento a tu plan.'));

        $this->assertNotNull($this->planOn($this->today));
        $this->assertSame('He añadido el entrenamiento a tu plan.', $turn['message']->content, 'It was written, so nothing is added.');
        $this->assertNull($turn['message']->meta[ReplyText::META_KEY] ?? null);
    }

    public function test_english_and_spanish_no_access_claims_get_a_correction_that_says_nothing_changed(): void
    {
        $spanishUser = $this->spanishUser();
        $english = $this->say('Can you change my plan?', $this->conversation(), null, new AiResponse(text: "I'm sorry, I don't have access to the plan tool."));
        $spanish = $this->say('¿Puedes cambiar mi plan?', $this->conversation($spanishUser), $spanishUser, new AiResponse(text: 'Lo siento, no tengo acceso a la herramienta del plan.'));

        $this->assertStringContainsString("To be clear, that is not a limit of the app: I can add and change workouts on your plan whenever you ask. I haven't changed anything this time.", $english['message']->content);
        $this->assertStringContainsString('Para ser claro: eso no es un límite de la aplicación', $spanish['message']->content);
        $this->assertStringContainsString('Esta vez no he cambiado nada.', $spanish['message']->content);
        $this->assertSame([NoticeCode::CLAIM_ACCESS_CORRECTED], $this->codes($english['message']));
        $this->assertSame([NoticeCode::CLAIM_ACCESS_CORRECTED], $this->codes($spanish['message']));
        $this->assertSame(0, $this->plans());
        $this->assertSame(0, $this->plans($spanishUser));
    }

    public function test_the_nothing_changed_sentence_is_not_repeated_when_the_application_already_said_it(): void
    {
        // A day that needs the user's confirmation: the application's question already says nothing changed.
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();

        $turn = $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: "I've saved it to your plan."));

        $this->assertSame([NoticeCode::REPLACEMENT_QUESTION], $this->codes($turn['message']), 'The model\'s false "saved" is replaced by the question; no second correction.');
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
    }

    public function test_questions_hypotheticals_and_negations_in_the_models_reply_are_not_corrected(): void
    {
        foreach ([
            '¿Quieres que lo añada a tu plan?',
            'Puedo añadirlo a tu plan si quieres.',
            'No he guardado nada en tu plan.',
            'Would you like me to add it to your dashboard?',
            "I haven't added anything to your plan yet.",
            'You can say "I\'ve added it to your plan" once it is true.',
        ] as $text) {
            $turn = $this->say('What next?', $this->conversation(), null, new AiResponse(text: $text));

            $this->assertSame($text, $turn['message']->content);
            $this->assertNull($turn['message']->meta, $text);
        }
    }

    public function test_the_applications_own_notices_are_never_taken_for_the_models_claims(): void
    {
        // The application's replacement result says "I replaced ... " and "I added ..."; correcting the model never reads it.
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

        $confirmed = $this->say('yes', $conversation);

        $this->assertSame([NoticeCode::REPLACEMENT_APPLIED], $this->codes($confirmed['message']));
        $this->assertSame('Upper body', $this->planOn($this->today)->title);
    }

    // ---- identical outcomes whichever language the user chose -----------------------------------------------------------

    public function test_the_same_conversation_stores_the_same_plans_and_the_same_notice_codes_in_either_language(): void
    {
        $outcomes = [];
        $texts = [];

        foreach (['en', 'es'] as $locale) {
            $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $locale]);
            $conversation = $this->coachDescribesAWorkout($user);
            $this->existingPlan('Old plan', $user);

            $held = $this->say('Add this workout to my dashboard', $conversation, $user, $this->saveCall(), new AiResponse(text: 'Done!'));
            $applied = $this->say('yes', $conversation, $user);
            $failed = $this->say('Add this workout to my dashboard', $this->conversation($user), $user);

            $plan = $this->planOn($this->today, $user);
            $outcomes[$locale] = [
                $plan->title, $plan->exercises->pluck('exercise_name')->all(), $plan->exercises->pluck('planned_reps')->all(),
                $this->codes($held['message']), $this->codes($applied['message']), $this->codes($failed['message']),
            ];
            $texts[$locale] = [$held['message']->content, $applied['message']->content, $failed['message']->content];
        }

        $this->assertSame($outcomes['en'], $outcomes['es'], 'Language changes no stored plan and no notice code; only the words of the notices.');
        $this->assertStringContainsString('Do you want me to replace it', $texts['en'][0]);
        $this->assertStringContainsString('¿Quieres que lo reemplace', $texts['es'][0]);
        $this->assertStringStartsWith('Done. I replaced', $texts['en'][1]);
        $this->assertStringStartsWith('Listo. Reemplacé', $texts['es'][1]);
        $this->assertStringContainsString('I don\'t see a workout', $texts['en'][2]);
        $this->assertStringContainsString('no veo un entrenamiento', $texts['es'][2]);
    }
}
