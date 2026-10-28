<?php

namespace Tests\Feature\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\PlanReplacer;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * "Add this workout to my dashboard" on a day that already has a planned workout must never silently replace it.
 * The application asks, once, in its own words, for the exact replacement; a "yes" makes exactly that replacement,
 * only if the day is still as it was shown, once, for the user who was asked. The model does not make it and its
 * words about it count for nothing.
 */
class ReplaceDayPlanConfirmationTest extends PerformanceEvalCase
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

    private function coachDescribesAWorkout(?User $user = null, string $text = self::DESCRIBED): int
    {
        // Chat allows ten messages a minute per user, and these scenarios loop; each starts with a clear allowance.
        Cache::flush();
        $this->fake->queue(new AiResponse(text: $text));

        return $this->ask($user ?? $this->user, 'Give me a quick upper body workout')['conversation'];
    }

    /** @return array{result: array, calls: int} */
    private function say(string $message, int $conversation, ?User $as = null, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($as ?? $this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return ['result' => $result, 'calls' => count($this->fake->calls) - $before];
    }

    private function lastAssistant(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail();
    }

    private function existingPlan(string $title = 'Old plan', ?string $date = null, ?User $user = null): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($user ?? $this->user, [
            'planned_date' => $date ?? $this->today, 'activity_type' => 'strength', 'title' => $title,
            'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ]);
    }

    private function planOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    /** The conversation up to the question: a routine, a plan already on today, and the request. */
    private function askedToReplace(string $title = 'Upper body'): int
    {
        $conversation = $this->coachDescribesAWorkout();
        $this->existingPlan();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(null, $title), new AiResponse(text: 'Done! I replaced your old plan.'));

        return $conversation;
    }

    private function pendingMeta(int $conversation): ?array
    {
        return $this->lastAssistant($conversation)->meta[PendingProposalStore::REPLACEMENT_META_KEY] ?? null;
    }

    // ---- a day with nothing on it ---------------------------------------------------------------------------------

    public function test_a_day_with_no_plan_is_written_straight_away_with_no_question(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Added.'));

        $this->assertSame('Upper body', $this->planOn($this->today)?->title);
        $this->assertNull($this->pendingMeta($conversation));
        $this->assertSame('Added.', $this->lastAssistant($conversation)->content);
    }

    // ---- a day that already has a planned workout -------------------------------------------------------------------

    public function test_a_planned_day_is_not_replaced_and_the_user_is_asked_in_the_applications_words(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        $existing = $this->existingPlan();
        $revision = $existing->fresh()->revision();

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done! I replaced your old plan with the new one.'));

        $plan = $this->planOn($this->today);
        $this->assertSame($existing->id, $plan->id);
        $this->assertSame($revision, $plan->revision(), 'Nothing about the existing plan changed.');

        $reply = $this->lastAssistant($conversation);
        $this->assertStringNotContainsString('Done!', $reply->content, "The model's claim that it replaced the plan is not passed on.");
        $this->assertStringContainsString("I haven't changed your plan", $reply->content);
        $this->assertStringContainsString('“Old plan” (Squats 3×10)', $reply->content, 'It shows what is there now.');
        $this->assertStringContainsString('“Upper body” (Push-ups 3×12, Dumbbell rows 3×10, Plank 30 s)', $reply->content, 'And exactly what would replace it.');
        $this->assertStringContainsString('Reply "yes" within 15 minutes', $reply->content);
        $this->assertNotNull($this->pendingMeta($conversation));
    }

    public function test_yes_makes_exactly_the_replacement_that_was_shown_and_the_model_is_not_involved(): void
    {
        $conversation = $this->askedToReplace();

        $turn = $this->say('yes', $conversation);

        $this->assertSame(0, $turn['calls'], 'The application makes the change and reports it; no model call.');

        $plan = $this->planOn($this->today);
        $this->assertSame('Upper body', $plan->title);
        $this->assertSame(['Push-ups', 'Dumbbell rows', 'Plank'], $plan->exercises->pluck('exercise_name')->all());
        $this->assertSame('planned', $plan->status);
        $this->assertSame(1, WorkoutPlan::where('user_id', $this->user->id)->count());

        $reply = $this->lastAssistant($conversation);
        $this->assertStringStartsWith('Done. I replaced “Old plan” (Squats 3×10)', $reply->content);
        $this->assertSame('applied', $reply->meta['plan_replacement']);
    }

    #[DataProvider('affirmations')]
    public function test_the_usual_ways_of_saying_yes_work(string $yes): void
    {
        $conversation = $this->askedToReplace();

        $this->say($yes, $conversation);

        $this->assertSame('Upper body', $this->planOn($this->today)->title, $yes);
    }

    /** @return array<string, array{string}> */
    public static function affirmations(): array
    {
        return ['yes' => ['yes'], 'Yes!' => ['Yes!'], 'ok' => ['ok'], 'go ahead' => ['go ahead'], 'confirm' => ['confirm']];
    }

    public function test_anything_other_than_a_plain_yes_confirms_nothing(): void
    {
        foreach (['yes but make it 5 sets', 'no', 'maybe', 'replace it with squats instead', 'yes, and also add a run'] as $reply) {
            $conversation = $this->askedToReplace();
            $before = $this->planOn($this->today)->revision();

            $this->say($reply, $conversation, null, new AiResponse(text: 'Okay.'));

            $this->assertSame($before, $this->planOn($this->today)->revision(), $reply);
            $this->assertSame('Old plan', $this->planOn($this->today)->title, $reply);
        }
    }

    public function test_a_yes_that_does_not_directly_follow_the_question_confirms_nothing(): void
    {
        $conversation = $this->askedToReplace();
        $this->say('What is in it?', $conversation, null, new AiResponse(text: 'Push-ups, rows and a plank.'));

        $this->say('yes', $conversation);

        $this->assertSame('Old plan', $this->planOn($this->today)->title, 'The question was not the last thing said.');
        $this->assertStringContainsString('nothing waiting for your confirmation', $this->lastAssistant($conversation)->content);
    }

    public function test_a_rest_day_is_a_plan_too_and_needs_the_same_confirmation(): void
    {
        $conversation = $this->coachDescribesAWorkout();
        WorkoutPlan::create(['user_id' => $this->user->id, 'planned_date' => $this->today, 'activity_type' => 'recovery', 'title' => WorkoutPlan::REST_TITLE, 'status' => 'skipped']);

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

        $this->assertSame('Rest', $this->planOn($this->today)->title);
        $this->assertStringContainsString('already has a rest day', $this->lastAssistant($conversation)->content);

        $this->say('yes', $conversation);
        $this->assertSame('Upper body', $this->planOn($this->today)->title);
    }

    public function test_only_the_requested_day_is_touched(): void
    {
        $friday = now('America/Bogota')->next('Friday')->toDateString();
        $conversation = $this->coachDescribesAWorkout();
        $today = $this->existingPlan('Today plan');

        // Friday is empty: written straight away, today's plan is left alone.
        $this->say('Add this workout to my plan for Friday', $conversation, null, $this->saveCall($friday), new AiResponse(text: 'Added.'));

        $this->assertSame('Upper body', $this->planOn($friday)->title);
        $this->assertSame($today->revision(), $this->planOn($this->today)->revision());

        // Friday now has a plan: asking again is a replacement of Friday, and only Friday.
        $conversation = $this->coachDescribesAWorkout();
        $this->say('Add this workout to my plan for Friday', $conversation, null, $this->saveCall($friday, 'Second'), new AiResponse(text: 'Added.'));
        $this->assertStringContainsString("{$friday} already has", $this->lastAssistant($conversation)->content);
        $this->assertSame('Upper body', $this->planOn($friday)->title);
        $this->assertSame($friday, $this->pendingMeta($conversation)['date']);
        $this->assertNotSame($today->id, $this->pendingMeta($conversation)['existing_plan_id'], "The proposal is about Friday's plan, not today's.");
    }

    public function test_asking_again_after_the_question_finds_the_workout_and_asks_afresh(): void
    {
        $conversation = $this->askedToReplace();
        $first = $this->pendingMeta($conversation);

        // The user ignores the question and asks again: the application's question is not "the last reply".
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(null, 'Upper body'), new AiResponse(text: 'Done.'));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $second = $this->pendingMeta($conversation);
        $this->assertNotNull($second);
        $this->assertNotSame($first['signature'] ?? null, null);

        $this->say('yes', $conversation);
        $this->assertSame('Upper body', $this->planOn($this->today)->title);
    }

    // ---- an explicit replacement request -----------------------------------------------------------------------------

    public function test_a_user_who_says_replace_gets_the_replacement_without_a_second_question_and_is_told_what_was_replaced(): void
    {
        foreach (["Replace today's workout with this one", "Add this workout to my dashboard and replace what's there"] as $request) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $conversation = $this->coachDescribesAWorkout();
            $this->existingPlan();

            $this->say($request, $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

            $this->assertSame('Upper body', $this->planOn($this->today)->title, $request);
            $this->assertNull($this->pendingMeta($conversation), $request);
            $this->assertStringContainsString('As you asked, I replaced “Old plan” (Squats 3×10) on '.$this->today, $this->lastAssistant($conversation)->content, 'The application says it, not only the model.');
        }
    }

    public function test_a_denial_or_a_question_about_replacing_is_not_a_replacement_request(): void
    {
        foreach (["Add this workout to my dashboard, don't replace anything", 'Should I replace today\'s workout with this one?'] as $request) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $conversation = $this->coachDescribesAWorkout();
            $this->existingPlan();

            $this->say($request, $conversation, null, $this->saveCall(), new AiResponse(text: 'Okay.'));

            $this->assertSame('Old plan', $this->planOn($this->today)->title, $request);
        }
    }

    // ---- performed days are never replaced --------------------------------------------------------------------------

    public function test_a_day_with_recorded_performance_is_never_replaced_whatever_the_user_says(): void
    {
        foreach (['Add this workout to my dashboard', "Replace today's workout with this one"] as $request) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
            $before = $this->planOn($this->today)->revision();
            $conversation = $this->coachDescribesAWorkout();

            $this->say($request, $conversation, null, $this->saveCall(), new AiResponse(text: 'Done, replaced it.'));

            $this->assertSame($before, $this->planOn($this->today)->revision(), $request);
            $this->assertNull($this->pendingMeta($conversation), 'No question is asked about something that cannot be done.');
            $this->assertStringContainsString('already has recorded workout performance', $this->lastAssistant($conversation)->content, $request);
        }
    }

    public function test_a_plan_that_is_completed_partial_or_in_progress_is_not_replaceable(): void
    {
        foreach ([WorkoutPlan::STATUS_COMPLETED, WorkoutPlan::STATUS_PARTIAL, WorkoutPlan::STATUS_IN_PROGRESS] as $status) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $this->existingPlan()->update(['status' => $status]);
            $conversation = $this->coachDescribesAWorkout();

            $this->say("Replace today's workout with this one", $conversation, null, $this->saveCall(), new AiResponse(text: 'Done.'));

            $this->assertSame('Old plan', $this->planOn($this->today)->title, $status);
            $this->assertStringContainsString('recorded workout performance', $this->lastAssistant($conversation)->content, $status);
        }
    }

    public function test_performance_recorded_after_the_question_blocks_the_confirmation(): void
    {
        $conversation = $this->askedToReplace();
        $plan = $this->planOn($this->today);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}/performance", ['sets' => [['reps' => 10, 'weight_kg' => 20]]])
            ->assertOk();

        $this->say('yes', $conversation);

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame(['Squats'], $this->planOn($this->today)->exercises->pluck('exercise_name')->all());
        $this->assertSame('refused', $this->lastAssistant($conversation)->meta['plan_replacement']);
        $this->assertStringContainsString('changed after I asked', $this->lastAssistant($conversation)->content);
    }

    public function test_the_application_itself_refuses_to_replace_a_performed_day_even_if_everything_else_matches(): void
    {
        // A proposal whose fingerprint matches the day as it stands now, but the day has performance on it.
        $plan = $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $conversation = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->json('id');
        $proposal = PlanReplacementProposal::create($this->user->id, $conversation, $this->today, $plan->id, $plan->fresh()->revision(), 'x', ['activity_type' => 'strength', 'title' => 'New', 'decision_summary' => 'x', 'reasoning_factors' => ['y'], 'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]]]);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertSame('that day already has recorded workout performance, so its plan can no longer be replaced.', $result, "The application's own check, not just the plan service's.");
        $this->assertSame('Bench Press', $this->planOn($this->today)->exercises->first()->exercise_name);
    }

    // ---- stale, expired, replayed, forged, mismatched --------------------------------------------------------------------

    public function test_a_plan_edited_after_the_question_is_not_replaced(): void
    {
        $conversation = $this->askedToReplace();
        $plan = $this->planOn($this->today);
        $plan->exercises->first()->update(['planned_reps' => 12]); // an approved progression, say

        $this->say('yes', $conversation);

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame(12, $this->planOn($this->today)->exercises->first()->planned_reps, 'What changed since is kept.');
        $this->assertStringContainsString('changed after I asked', $this->lastAssistant($conversation)->content);
    }

    public function test_a_plan_removed_or_swapped_after_the_question_is_not_touched(): void
    {
        $conversation = $this->askedToReplace();
        $this->planOn($this->today)->delete();

        $this->say('yes', $conversation);
        $this->assertNull($this->planOn($this->today), 'It does not create the workout on a day that is now empty: the confirmation was for a replacement.');
        $this->assertStringContainsString('no longer there', $this->lastAssistant($conversation)->content);

        $conversation = $this->askedToReplace();
        $this->existingPlan('Swapped in a moment ago');
        $this->say('yes', $conversation);
        $this->assertSame('Swapped in a moment ago', $this->planOn($this->today)->title);
    }

    public function test_an_expired_confirmation_does_nothing(): void
    {
        $conversation = $this->askedToReplace();

        $this->travel(PlanReplacementProposal::TTL_MINUTES + 1)->minutes();
        $this->say('yes', $conversation);

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame('invalid', $this->lastAssistant($conversation)->meta['plan_replacement']);
        $this->assertStringContainsString('no longer valid', $this->lastAssistant($conversation)->content);
    }

    public function test_a_confirmation_works_up_to_the_limit(): void
    {
        $conversation = $this->askedToReplace();

        $this->travel(PlanReplacementProposal::TTL_MINUTES - 1)->minutes();
        $this->say('yes', $conversation);

        $this->assertSame('Upper body', $this->planOn($this->today)->title);
    }

    public function test_a_confirmation_cannot_be_replayed(): void
    {
        $conversation = $this->askedToReplace();
        $this->say('yes', $conversation);
        $this->assertSame('Upper body', $this->planOn($this->today)->title);

        // The user changes the plan again by hand, then someone says "yes" a second time.
        $this->planOn($this->today)->update(['title' => 'Edited by hand']);
        $this->say('yes', $conversation);

        $this->assertSame('Edited by hand', $this->planOn($this->today)->title);
    }

    public function test_two_confirmations_racing_each_other_make_one_replacement(): void
    {
        $conversation = $this->askedToReplace();
        $message = $this->lastAssistant($conversation);
        $store = app(PendingProposalStore::class);
        $conv = Conversation::findOrFail($conversation);

        // Both read the message before either used it: the conditional update lets exactly one through.
        $first = $store->claimReplacement($conv, $this->user, $message);
        $second = $store->claimReplacement($conv, $this->user, $message);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_a_forged_or_edited_proposal_is_refused(): void
    {
        foreach ([
            'another day' => fn (array $m) => ['date' => now('America/Bogota')->addDays(2)->toDateString()] + $m,
            'another plan' => fn (array $m) => ['existing_plan_id' => $m['existing_plan_id'] + 100] + $m,
            'another revision' => fn (array $m) => ['existing_revision' => str_repeat('a', 64)] + $m,
            'another workout' => fn (array $m) => ['replacement' => ['title' => 'Chosen by someone else'] + $m['replacement']] + $m,
            'another user' => fn (array $m) => ['user_id' => $m['user_id'] + 1] + $m,
            'no signature' => fn (array $m) => array_diff_key($m, ['signature' => 1]),
            'a longer life' => fn (array $m) => ['expires_at' => now()->addYear()->toIso8601String()] + $m,
        ] as $what => $tamper) {
            WorkoutPlan::where('user_id', $this->user->id)->delete();
            $conversation = $this->askedToReplace();
            $message = $this->lastAssistant($conversation);

            $message->update(['meta' => [PendingProposalStore::REPLACEMENT_META_KEY => $tamper($message->meta[PendingProposalStore::REPLACEMENT_META_KEY])]]);
            $this->say('yes', $conversation);

            $this->assertSame('Old plan', $this->planOn($this->today)->title, $what);
            $this->assertSame('invalid', $this->lastAssistant($conversation)->meta['plan_replacement'], $what);
        }
    }

    public function test_the_signature_survives_the_database_round_trip(): void
    {
        $conversation = $this->askedToReplace();

        $stored = DB::table('messages')->where('conversation_id', $conversation)->orderByDesc('id')->value('meta');
        $this->assertNotNull(PlanReplacementProposal::fromMeta(json_decode($stored, true)[PendingProposalStore::REPLACEMENT_META_KEY])?->isAuthentic(), 'Key order and number formats may change in the column; the signature must not care.');
    }

    // ---- other conversations and other users --------------------------------------------------------------------------

    public function test_the_confirmation_cannot_be_used_from_another_conversation(): void
    {
        $conversation = $this->askedToReplace();
        $meta = $this->lastAssistant($conversation)->meta;

        $other = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->json('id');
        Message::create(['conversation_id' => $other, 'role' => 'user', 'content' => 'hello']);
        Message::create(['conversation_id' => $other, 'role' => 'assistant', 'content' => 'Do you want me to replace it?', 'meta' => $meta]);

        $this->say('yes', $other);

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame('invalid', $this->lastAssistant($other)->meta['plan_replacement']);
    }

    public function test_another_user_cannot_confirm_for_this_user(): void
    {
        $conversation = $this->askedToReplace();
        $intruder = $this->newUser();
        $theirs = $this->existingPlan('Their plan', null, $intruder);

        // Into this user's conversation: not theirs to write in.
        $turn = $this->ask($intruder, 'yes', $conversation);
        $this->assertNotNull($turn['error'], 'Rejected: it is not their conversation.');

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame($theirs->revision(), $this->planOn($this->today, $intruder)->revision());
    }

    public function test_a_proposal_copied_into_another_users_conversation_is_worthless_there(): void
    {
        $conversation = $this->askedToReplace();
        $meta = $this->lastAssistant($conversation)->meta;

        $intruder = $this->newUser();
        $theirs = $this->existingPlan('Their plan', null, $intruder);
        $theirConversation = $this->actingAs($intruder, 'sanctum')->postJson('/api/conversations', [])->json('id');
        Message::create(['conversation_id' => $theirConversation, 'role' => 'assistant', 'content' => 'Replace it?', 'meta' => $meta]);

        $this->say('yes', $theirConversation, $intruder);

        $this->assertSame($theirs->revision(), $this->planOn($this->today, $intruder)->revision(), 'Their plan is untouched.');
        $this->assertSame('Old plan', $this->planOn($this->today)->title, 'And so is the plan it was written for.');
    }

    public function test_each_users_replacement_is_their_own(): void
    {
        $alice = $this->askedToReplace();

        $bob = $this->newUser();
        $bobConversation = $this->coachDescribesAWorkout($bob);
        $this->existingPlan('Bob old', null, $bob);
        $this->say('Add this workout to my dashboard', $bobConversation, $bob, $this->saveCall(), new AiResponse(text: 'Done.'));
        $this->say('yes', $bobConversation, $bob);

        $this->assertSame('Upper body', $this->planOn($this->today, $bob)->title);
        $this->assertSame('Old plan', $this->planOn($this->today)->title, 'Alice has not confirmed anything.');
        $this->assertNotNull($this->pendingMeta($alice));
    }

    // ---- the load rules still apply -----------------------------------------------------------------------------------------

    public function test_a_load_with_no_baseline_is_not_in_the_proposal_and_not_in_the_plan(): void
    {
        $this->fake->queue(new AiResponse(text: "Try this:\n1. Goblet Squat 3x10 at 24 kg\n2. Push-ups 3x12"));
        $conversation = $this->ask($this->user, 'Give me a quick leg workout')['conversation'];
        $this->existingPlan();

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(null, 'Legs', [
            ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24],
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
        ]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->pendingMeta($conversation)['replacement']['exercises'][0]['planned_weight_kg'], 'The weight in the coach\'s own reply is not signed into the proposal.');
        $this->assertStringContainsString('I left the starting weight for Goblet Squat unset', $this->lastAssistant($conversation)->content);

        $this->say('yes', $conversation);
        $this->assertNull($this->planOn($this->today)->exercises->first()->planned_weight_kg);
    }

    public function test_a_load_above_the_limit_is_refused_before_anything_is_asked(): void
    {
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->fake->queue(new AiResponse(text: "Try this:\n1. Bench Press 3x10 at 60 kg\n2. Push-ups 3x12"));
        $conversation = $this->ask($this->user, 'Give me a chest workout')['conversation'];
        $this->existingPlan();

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(null, 'Heavy', [
            ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 60],
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
        ]), new AiResponse(text: 'Done.'));

        $this->assertNull($this->pendingMeta($conversation), 'Nothing to confirm: the workout itself was refused.');
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
    }

    public function test_confirming_does_not_get_around_the_load_rules(): void
    {
        $conversation = $this->askedToReplace();
        $message = $this->lastAssistant($conversation);

        // As if a proposal had been built with a load nothing supports: re-signed by the application, so it is authentic.
        $meta = $message->meta[PendingProposalStore::REPLACEMENT_META_KEY];
        $meta['replacement']['exercises'][0]['planned_weight_kg'] = 90;
        $forged = PlanReplacementProposal::create($this->user->id, $conversation, $meta['date'], $meta['existing_plan_id'], $meta['existing_revision'], $meta['existing_summary'], ['activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'x', 'reasoning_factors' => ['y']] + ['exercises' => $meta['replacement']['exercises']]);
        $message->update(['meta' => [PendingProposalStore::REPLACEMENT_META_KEY => $forged->toMeta()]]);

        $this->say('yes', $conversation);

        $this->assertNull($this->planOn($this->today)->exercises->first()->planned_weight_kg, 'An exercise with no baseline gets no load, whoever asks.');
    }

    // ---- the model cannot widen what was asked ---------------------------------------------------------------------------------

    public function test_the_model_cannot_add_an_exercise_or_use_another_day(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $extra = $this->saveCall(null, 'Padded', [
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ['exercise_name' => 'Burpees', 'planned_sets' => 5, 'planned_reps' => 20],
        ]);
        $elsewhere = $this->saveCall(now('America/Bogota')->addDays(3)->toDateString(), 'Another day');

        $this->say('Add this workout to my dashboard', $conversation, null, $extra, $elsewhere, new AiResponse(text: 'Saved!'));

        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->count(), 'Neither attempt wrote anything.');
        $reply = $this->lastAssistant($conversation);
        $this->assertSame(['outside_requested_workout', 'outside_requested_workout'], array_column($reply->meta['blocked_tool_calls'], 'reason'));
        $this->assertStringContainsString('the workout was not saved', $reply->content, 'A model that says "Saved!" is corrected by the application.');
    }

    public function test_the_workout_is_written_once_per_request(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall(null, 'First'), $this->saveCall(now('America/Bogota')->addDay()->toDateString(), 'Second'), new AiResponse(text: 'Done.'));

        $this->assertSame(['First'], WorkoutPlan::where('user_id', $this->user->id)->pluck('title')->all());
        $this->assertSame('save_already_handled', $this->lastAssistant($conversation)->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_a_model_that_claims_a_save_it_never_made_is_corrected(): void
    {
        $conversation = $this->coachDescribesAWorkout();

        $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: "Done! It's on your dashboard."));

        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->count());
        $this->assertStringContainsString("I haven't changed your plan: the workout was not saved", $this->lastAssistant($conversation)->content);
    }
}
