<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Authorization\ProposalMatcher;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * A "yes" authorizes ONE thing: the exact change the application showed the user.
 *
 * The user says they want bench at 45 kg; the application builds one signed proposal from
 * their words and their plan, shows it, and a "yes" redeems it once. The model is scripted to
 * misbehave after the "yes" (a different plan, 47.5 kg, another exercise, another day) and
 * every such attempt must be refused at dispatch with nothing changed.
 */
class ConfirmedProposalAuthorizationTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $day;

    private string $otherDay;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        $this->logSets($this->user, 1, 'Bench Press', [3, 10, 42.5], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 42.5]));

        $this->day = $this->d(-2);
        $this->otherDay = $this->d(-4);

        foreach ([$this->day, $this->otherDay] as $date) {
            $plan = app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => "Strength {$date}", 'duration_minutes' => 50, 'notes' => 'Keep this note',
                'exercises' => [
                    ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 42.5],
                    ['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 60],
                ],
            ]);
            $plan->update(['notes' => 'Keep this note']);
        }
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function toolCall(string $name, array $arguments, string $id = 'c1'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: $id, name: $name, arguments: $arguments)]);
    }

    /** @return array{message: Message, conversation: int, calls: array} */
    private function say(?int $conversation, string $text, AiResponse ...$responses): array
    {
        return $this->sayAs($this->user, $conversation, $text, ...$responses);
    }

    private function sayAs(User $as, ?int $conversation, string $text, AiResponse ...$responses): array
    {
        $this->fake->calls = [];
        $this->fake->queue(...$responses);

        $result = $this->ask($as, $text, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'message' => Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail(),
            'conversation' => $result['conversation'],
            'calls' => $this->fake->calls,
        ];
    }

    /** The user states a wish; the application presents one exact proposal. */
    private function offer(string $wish = 'I want to go up to 45 kg on bench next session'): array
    {
        $turn = $this->say(null, $wish, new AiResponse(text: 'That is a modest step from 42.5 kg.'));

        $this->assertArrayHasKey('pending_plan_proposal', $turn['message']->meta ?? [], 'Precondition: a proposal was presented.');
        $this->assertStringContainsString('Reply "yes"', $turn['message']->content);

        return $turn;
    }

    private function proposalOf(Message $message): PlanChangeProposal
    {
        return PlanChangeProposal::fromMeta($message->meta['pending_plan_proposal']);
    }

    /** The arguments the application tells the model to send for the confirmed change. */
    private function exactCall(Message $offered, array $override = []): array
    {
        return $override + app(ProposalMatcher::class)->expectedArguments($this->user, $this->proposalOf($offered)) + [];
    }

    private function withText(array $arguments): array
    {
        return $arguments + ['decision_summary' => 'As confirmed by the user.', 'reasoning_factors' => ['The user confirmed it.']];
    }

    private function benchWeight(string $date): float
    {
        return (float) WorkoutPlanExercise::query()
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $this->user->id)->whereDate('workout_plans.planned_date', $date)
            ->where('exercise_slug', 'bench-press')->value('planned_weight_kg');
    }

    private function squatWeight(string $date): float
    {
        return (float) WorkoutPlanExercise::query()
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plans.id')
            ->where('exercise_slug', 'squat')->where('workout_plan_id', WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $date)->value('id'))
            ->value('planned_weight_kg');
    }

    // ---- the proposal itself -------------------------------------------------------------------------

    public function test_the_proposal_is_built_from_the_users_words_and_records_the_exact_change(): void
    {
        $offered = $this->offer();
        $p = $this->proposalOf($offered['message']);

        $this->assertSame(['set_exercise_target', $this->user->id, $offered['conversation'], 'bench-press', 'Bench Press', 45.0, $this->day], [$p->operation, $p->userId, $p->conversationId, $p->exerciseSlug, $p->exerciseName, $p->plannedWeightKg, $p->date]);
        $this->assertNull($p->plannedSets);
        $this->assertTrue($p->isAuthentic());
        $this->assertEquals(15, CarbonImmutable::parse($p->createdAt)->diffInMinutes(CarbonImmutable::parse($p->expiresAt)));
        $this->assertStringContainsString("set Bench Press to 45 kg on {$this->day}", $offered['message']->content);
        $this->assertStringContainsString('does not mean the load suits you', $offered['message']->content);
        $this->assertSame(42.5, $this->benchWeight($this->day), 'Offering changes nothing.');
    }

    // ---- 1: the exact write is allowed ---------------------------------------------------------------

    public function test_1_a_confirmed_change_to_45_kg_is_made_exactly_and_touches_nothing_else(): void
    {
        $offered = $this->offer();
        $before = DB::table('workout_plans')->where('user_id', $this->user->id)->get()->keyBy('id');

        $turn = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done: Bench Press is now 45 kg.'));

        $this->assertArrayNotHasKey('blocked_tool_calls', $turn['message']->meta ?? []);
        $this->assertSame(45.0, $this->benchWeight($this->day));
        $this->assertSame(60.0, $this->squatWeight($this->day), 'Another exercise in the same day is untouched.');
        $this->assertSame(42.5, $this->benchWeight($this->otherDay), 'The same exercise on another day is untouched.');

        $plan = WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $this->day)->first();
        $this->assertSame('Keep this note', $plan->notes, 'The rest of the plan is not rewritten the way replacing it would.');
        $this->assertSame(50, $plan->duration_minutes);
        $this->assertSame($before[$plan->id]->title, $plan->title);
        $this->assertStringNotContainsString("couldn't make the change", $turn['message']->content);
    }

    public function test_a_confirmed_change_that_names_sets_and_reps_binds_them_too(): void
    {
        $offered = $this->say(null, 'I want to go up to 45 kg for 3x8 on bench next session', new AiResponse(text: 'ok'));
        $p = $this->proposalOf($offered['message']);
        $this->assertSame([3, 8], [$p->plannedSets, $p->plannedReps]);

        $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $row = WorkoutPlanExercise::where('exercise_slug', 'bench-press')->whereKey($p->planExerciseId)->first();
        $this->assertSame([3, 8, '45.00'], [$row->planned_sets, $row->planned_reps, (string) $row->planned_weight_kg]);
    }

    // ---- 2-5: nothing near it is authorized ----------------------------------------------------------------

    public static function nearMisses(): array
    {
        return [
            '2: a different workout plan' => ['different_plan'],
            '3: 47.5 kg instead of 45' => ['weight_47_5'],
            '4: another exercise changed' => ['other_exercise'],
            '5: a different date' => ['other_date'],
            'an extra exercise added' => ['extra_exercise'],
            'an exercise dropped' => ['dropped_exercise'],
            'a different activity type' => ['other_type'],
            'a changed duration' => ['other_duration'],
            'other targets modified (squat reps)' => ['squat_reps'],
            'the date left out' => ['no_date'],
        ];
    }

    #[DataProvider('nearMisses')]
    public function test_a_confirmation_authorizes_only_what_was_shown(string $deviation): void
    {
        $offered = $this->offer();
        $exact = $this->exactCall($offered['message']);

        $call = match ($deviation) {
            'different_plan' => ['planned_date' => $this->d(-6), 'activity_type' => 'recovery', 'title' => 'Totally different plan', 'duration_minutes' => 20, 'exercises' => [['exercise_name' => 'Walk', 'planned_duration_seconds' => 1200]]],
            'weight_47_5' => ['exercises' => [array_replace($exact['exercises'][0], ['planned_weight_kg' => 47.5]), $exact['exercises'][1]]],
            'other_exercise' => ['exercises' => [$exact['exercises'][0], array_replace($exact['exercises'][1], ['planned_weight_kg' => 45])]],
            'other_date' => ['planned_date' => $this->otherDay],
            'extra_exercise' => ['exercises' => [...$exact['exercises'], ['exercise_name' => 'Curl', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 10]]],
            'dropped_exercise' => ['exercises' => [$exact['exercises'][0]]],
            'other_type' => ['activity_type' => 'recovery'],
            'other_duration' => ['duration_minutes' => 90],
            'squat_reps' => ['exercises' => [$exact['exercises'][0], array_replace($exact['exercises'][1], ['planned_reps' => 12])]],
            'no_date' => ['planned_date' => null],
        };

        $before = $this->snapshot();
        $turn = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($call + $exact)), new AiResponse(text: 'Done.'));

        $this->assertSame($before, $this->snapshot(), "{$deviation}: nothing may change.");
        $this->assertSame('does_not_match_confirmed_change', $turn['message']->meta['blocked_tool_calls'][0]['reason'], $deviation);
        $this->assertStringContainsString("couldn't make the change exactly as you confirmed it", $turn['message']->content);
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_the_model_cannot_widen_a_confirmation_to_other_tools(): void
    {
        $offered = $this->offer();
        $before = $this->snapshot();

        $turn = $this->say(
            $offered['conversation'],
            'yes',
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'a', name: 'propose_weekly_plan_changes', arguments: ['decision_summary' => 'x', 'reasoning_factors' => ['x'], 'changes' => [['date' => now()->toDateString(), 'action' => 'clear', 'reason' => 'x']]]),
                new ToolCallRequest(id: 'b', name: 'update_exercise_status', arguments: ['exercise_name' => 'Squat', 'status' => 'completed', 'date' => $this->day]),
            ]),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['propose_weekly_plan_changes', 'update_exercise_status'], array_column($turn['message']->meta['blocked_tool_calls'], 'tool'));
        $this->assertSame(['create_workout_plan'], array_values(array_intersect(['create_workout_plan', 'propose_weekly_plan_changes', 'update_exercise_status'], array_column($turn['calls'][0]['tools'], 'name'))), 'Only the one tool a confirmation can cover is offered.');
    }

    public function test_after_a_mismatch_the_exact_call_may_still_be_made_once_and_only_once_in_that_turn(): void
    {
        $offered = $this->offer();
        $exact = $this->exactCall($offered['message']);

        $turn = $this->say(
            $offered['conversation'],
            'yes',
            $this->toolCall('create_workout_plan', $this->withText(['planned_date' => $this->otherDay] + $exact), 'm1'),
            $this->toolCall('create_workout_plan', $this->withText($exact), 'm2'),
            $this->toolCall('create_workout_plan', $this->withText($exact), 'm3'),
            new AiResponse(text: 'Done.'),
        );

        $this->assertSame(45.0, $this->benchWeight($this->day));
        $this->assertSame(['does_not_match_confirmed_change', 'confirmation_already_used'], array_column($turn['message']->meta['blocked_tool_calls'], 'reason'));
    }

    // ---- 6-8: expiry, reuse, concurrency ----------------------------------------------------------------------

    public function test_6_an_expired_confirmation_authorizes_nothing(): void
    {
        $offered = $this->offer();

        $this->travel(16)->minutes();
        $turn = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertStringContainsString('may have expired', $turn['message']->content);
    }

    public function test_a_confirmation_just_inside_the_window_still_works(): void
    {
        $offered = $this->offer();

        $this->travel(14)->minutes();
        $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $this->assertSame(45.0, $this->benchWeight($this->day));
    }

    public function test_7_a_used_confirmation_cannot_be_used_again(): void
    {
        $offered = $this->offer();
        $store = app(PendingProposalStore::class);
        $conversation = $offered['message']->conversation;

        $copy = Message::find($offered['message']->id); // as a second request would have read it
        $this->assertNotNull($store->claim($conversation, $this->user, $offered['message']));
        $this->assertNull($store->claim($conversation, $this->user, $copy), 'A stale read of the same proposal is refused.');
        $this->assertNull($store->claim($conversation, $this->user, Message::find($offered['message']->id)), 'And so is a fresh one.');
        $this->assertNotNull(Message::find($offered['message']->id)->meta['pending_plan_proposal']['consumed_at']);
    }

    public function test_a_second_yes_in_the_conversation_does_nothing(): void
    {
        $offered = $this->offer();
        $first = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));
        $this->assertSame(45.0, $this->benchWeight($this->day));

        $second = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText(['exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 46]]] + $this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $this->assertSame(45.0, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $second['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertNotNull($first['message']);
    }

    public function test_8_concurrent_confirmations_of_one_proposal_let_at_most_one_through(): void
    {
        $offered = $this->offer();
        $store = app(PendingProposalStore::class);
        $conversation = $offered['message']->conversation;

        // Ten requests that all read the proposal before any of them redeemed it.
        $reads = array_map(fn () => Message::find($offered['message']->id), range(1, 10));
        $winners = array_filter(array_map(fn (Message $read) => $store->claim($conversation, $this->user, $read), $reads));

        $this->assertCount(1, $winners, 'Exactly one request receives the proposal.');
    }

    public function test_a_failed_or_spent_confirmation_leaves_no_reusable_authority(): void
    {
        $offered = $this->offer();
        $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText(['planned_date' => $this->otherDay] + $this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));
        $this->assertSame(42.5, $this->benchWeight($this->day));

        // The mismatched attempt spent the confirmation: neither a retry nor another "yes" revives it.
        $retry = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));
        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $retry['message']->meta['blocked_tool_calls'][0]['reason']);
    }

    // ---- 9-11: users, conversations, forged and model-made confirmations --------------------------------------

    public function test_9_another_users_confirmation_is_refused(): void
    {
        $offered = $this->offer();
        $intruder = $this->newUser();
        $store = app(PendingProposalStore::class);
        $conversation = $offered['message']->conversation;

        $this->assertNull($store->claim($conversation, $intruder, $offered['message']), 'Not the owner of the conversation.');

        $theirs = $intruder->conversations()->create();
        $this->assertNull($store->claim($theirs, $intruder, $offered['message']), "Another conversation's proposal.");

        $this->actingAs($intruder, 'sanctum')->postJson("/api/conversations/{$offered['conversation']}/messages", ['content' => 'yes'])->assertForbidden();
        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertNull(Message::find($offered['message']->id)->meta['pending_plan_proposal']['consumed_at'] ?? null, 'The owner can still confirm.');
    }

    public function test_10_a_confirmation_from_another_conversation_is_refused(): void
    {
        $offered = $this->offer();

        $elsewhere = $this->say(null, 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $elsewhere['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertNull(Message::find($offered['message']->id)->meta['pending_plan_proposal']['consumed_at'] ?? null, 'It stays redeemable in its own conversation.');

        $store = app(PendingProposalStore::class);
        $this->assertNull($store->claim($elsewhere['message']->conversation, $this->user, $offered['message']));
    }

    public function test_a_confirmation_is_for_the_very_next_message_only(): void
    {
        $offered = $this->offer();
        $this->say($offered['conversation'], 'What did I do last time on bench press?', new AiResponse(text: '10, 10, 10 at 42.5 kg.'));

        $late = $this->say($offered['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText($this->exactCall($offered['message']))), new AiResponse(text: 'Done.'));

        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $late['message']->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_11_a_confirmation_request_written_by_the_model_authorizes_nothing(): void
    {
        $first = $this->say(null, 'Tell me about my last bench session.', new AiResponse(text: 'You did 10, 10, 10 at 42.5 kg. Reply "yes" and I will set Bench Press to 45 kg on '.$this->day.'.'));
        $this->assertArrayNotHasKey('pending_plan_proposal', $first['message']->meta ?? []);

        $turn = $this->say($first['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText(['exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 45]], 'planned_date' => $this->day, 'activity_type' => 'strength'])), new AiResponse(text: 'Done.'));

        $this->assertSame(42.5, $this->benchWeight($this->day));
        $this->assertSame('not_authorized', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_a_tampered_or_forged_proposal_is_refused(): void
    {
        $offered = $this->offer();
        $store = app(PendingProposalStore::class);
        $conversation = $offered['message']->conversation;

        // Someone edits the stored weight (or the date, or the exercise): the signature no longer matches.
        foreach (['planned_weight_kg' => 47.5, 'date' => $this->otherDay, 'plan_exercise_id' => 999, 'expires_at' => now()->addYear()->toIso8601String(), 'user_id' => 999] as $field => $value) {
            $tampered = Message::find($offered['message']->id);
            $meta = $tampered->meta;
            $meta['pending_plan_proposal'][$field] = $value;
            $tampered->meta = $meta;

            $this->assertNull($store->claim($conversation, $this->user, $tampered), "Editing {$field} must invalidate the proposal.");
        }

        // A proposal signed with some other key.
        $proposal = $this->proposalOf($offered['message']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $forged = PlanChangeProposal::create($this->user->id, $conversation->id, $proposal->planId, $proposal->planExerciseId, $proposal->date, 'bench-press', 'Bench Press', 46.5, null, null);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'forged', 'meta' => ['pending_plan_proposal' => $forged->toMeta()]]);

        $this->assertNull($store->claim($conversation, $this->user, $message));
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }

    public function test_a_validly_signed_proposal_for_another_conversation_or_user_is_refused_where_it_is_found(): void
    {
        $offered = $this->offer();
        $p = $this->proposalOf($offered['message']);
        $store = app(PendingProposalStore::class);
        $mine = $this->user->conversations()->create();
        $other = $this->newUser();

        // Authentic (the application signed them), but not made for this conversation / this user.
        $forConversationA = PlanChangeProposal::create($this->user->id, $offered['conversation'], $p->planId, $p->planExerciseId, $p->date, 'bench-press', 'Bench Press', 45, null, null);
        $forAnotherUser = PlanChangeProposal::create($other->id, $mine->id, $p->planId, $p->planExerciseId, $p->date, 'bench-press', 'Bench Press', 45, null, null);

        $movedIntoMine = $mine->messages()->create(['role' => 'assistant', 'content' => 'moved', 'meta' => ['pending_plan_proposal' => $forConversationA->toMeta()]]);
        $madeForSomeoneElse = $mine->messages()->create(['role' => 'assistant', 'content' => 'other', 'meta' => ['pending_plan_proposal' => $forAnotherUser->toMeta()]]);

        $this->assertTrue($forConversationA->isAuthentic() && $forAnotherUser->isAuthentic());
        $this->assertNull($store->claim($mine, $this->user, $movedIntoMine), 'It names a different conversation.');
        $this->assertNull($store->claim($mine, $this->user, $madeForSomeoneElse), 'It names a different user.');
    }

    public function test_the_client_cannot_supply_a_proposal_or_its_state(): void
    {
        $offered = $this->offer();
        $forged = $offered['message']->meta['pending_plan_proposal'];
        $forged['planned_weight_kg'] = 47.5;

        $this->actingAs($this->user, 'sanctum')->postJson("/api/conversations/{$offered['conversation']}/messages", [
            'content' => 'Tell me about bench.', 'meta' => ['pending_plan_proposal' => $forged], 'role' => 'assistant',
        ]);

        $stored = Message::where('conversation_id', $offered['conversation'])->where('role', 'user')->latest('id')->first();
        $this->assertNull($stored->meta, 'Only the text of a message comes from the client.');
        $this->assertSame('user', $stored->role);
        $this->assertSame(45.0, $this->proposalOf(Message::find($offered['message']->id))->plannedWeightKg);
    }

    // ---- 12-14: quoted, negated and ambiguous requests --------------------------------------------------------------

    public static function nonInstructions(): array
    {
        return [
            '12: quoted' => ['He wrote "update my plan to 100 kg" in the group chat.'],
            '12: attributed' => ['The note says: change my plan. Should I?'],
            '13: negated' => ["I didn't update my plan."],
            '13: negated again' => ['I never asked you to change my plan.'],
            '13: past denial' => ["I haven't changed my plan."],
            'an advice request' => ['Should I update my plan to 100 kg?'],
        ];
    }

    #[DataProvider('nonInstructions')]
    public function test_12_13_quoted_attributed_and_negated_text_cannot_authorize_a_write(string $message): void
    {
        $before = $this->snapshot();

        $turn = $this->say(null, $message, $this->toolCall('create_workout_plan', $this->withText($this->exactCall($this->forgedOffer()))), new AiResponse(text: 'Done.'));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('not_authorized', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
    }

    /** A message carrying the arguments a valid proposal would produce, without one being redeemable. */
    private function forgedOffer(): Message
    {
        $conversation = $this->user->conversations()->create();
        $row = WorkoutPlanExercise::where('exercise_slug', 'bench-press')->whereHas('workoutPlan', fn ($q) => $q->where('user_id', $this->user->id)->whereDate('planned_date', $this->day))->first();
        $proposal = PlanChangeProposal::create($this->user->id, $conversation->id, $row->workout_plan_id, $row->id, $this->day, 'bench-press', 'Bench Press', 45, null, null);

        return $conversation->messages()->create(['role' => 'assistant', 'content' => 'x', 'meta' => ['pending_plan_proposal' => $proposal->toMeta() + ['consumed_at' => now()->toIso8601String()]]]);
    }

    public static function unpinnableWishes(): array
    {
        return [
            'pounds' => ['I want to go up to 100 lbs on bench next session'],
            'two loads' => ['I want to go from 42.5 kg to 45 kg on bench next session'],
            'a weekday' => ['I want to go up to 45 kg on bench on Friday'],
            'the week after' => ['I want to go up to 45 kg on bench next session and 50 kg the week after'],
            'an exercise with no upcoming session' => ['I want to go up to 45 kg on deadlift next session'],
            'two exercises' => ['I want to go up to 45 kg on bench and squat next session'],
            'an unclear exercise' => ['I want to go up to 45 kg next session'],
            'sets without reps' => ['I want to do more sets at 45 kg on bench next session'],
            'no change' => ['I want to stay at 42.5 kg on bench next session'],
            'a day with no plan' => ['I want to go up to 45 kg on bench on 2030-01-01'],
            'another language' => ['Quiero subir a 45 kg en press de banca la próxima sesión'],
        ];
    }

    #[DataProvider('unpinnableWishes')]
    public function test_14_an_ambiguous_proposal_fails_closed_without_a_generic_yes(string $wish): void
    {
        $turn = $this->say(null, $wish, new AiResponse(text: 'ok'));

        $this->assertArrayNotHasKey('pending_plan_proposal', $turn['message']->meta ?? [], $wish);
        $this->assertStringNotContainsString('Reply "yes"', $turn['message']->content);
        $this->assertSame(42.5, $this->benchWeight($this->day));

        $yes = $this->say($turn['conversation'], 'yes', $this->toolCall('create_workout_plan', $this->withText(['exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 45]], 'planned_date' => $this->day, 'activity_type' => 'strength'])), new AiResponse(text: 'ok'));
        $this->assertSame(42.5, $this->benchWeight($this->day), 'A bare yes after a wish that was not pinned down changes nothing.');
        $this->assertSame('not_authorized', $yes['message']->meta['blocked_tool_calls'][0]['reason']);
    }

    public function test_a_wish_above_the_maximum_change_is_refused_with_the_limit_stated_as_a_maximum(): void
    {
        $turn = $this->say(null, 'I want to go up to 60 kg on bench next session', new AiResponse(text: 'That is a big jump.'));

        $this->assertArrayNotHasKey('pending_plan_proposal', $turn['message']->meta ?? []);
        $this->assertStringContainsString('46.5 kg', $turn['message']->content);
        $this->assertStringContainsString('maximum, not a recommendation', $turn['message']->content);
        $this->assertStringNotContainsString('safe', mb_strtolower($turn['message']->content));
    }

    // ---- 15-16: legitimate flows are intact -----------------------------------------------------------------------

    public function test_15_an_explicit_request_still_changes_the_plan_directly(): void
    {
        $turn = $this->say(null, 'Please update my plan for the weekend.', $this->toolCall('create_workout_plan', $this->withText([
            'planned_date' => $this->d(-6), 'activity_type' => 'strength', 'title' => 'Weekend', 'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 45]],
        ])), new AiResponse(text: 'Done.'));

        $this->assertSame(1, WorkoutPlan::where('title', 'Weekend')->count());
        $this->assertArrayNotHasKey('blocked_tool_calls', $turn['message']->meta ?? []);
    }

    public function test_16_the_weekly_plan_page_is_unaffected_by_a_pending_proposal(): void
    {
        $this->offer();
        $sunday = now()->startOfWeek()->addDays(6)->toDateString();

        $this->fake->queue($this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'Lighter Sunday.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $sunday, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Sunday walk', 'reason' => 'test']],
        ]));
        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Make Sunday easy.'])->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(1, WorkoutPlan::where('title', 'Sunday walk')->count());
    }

    // ---- the Phase 4C unauthorized writes, replayed ---------------------------------------------------------------

    public static function phase4cQuestions(): array
    {
        $texts = [
            'What should I focus on next?', 'Can I lift 60 kg on bench press now?', "So I'm strong enough to move up to 25 kg on rows, right?",
            'My shoulder hurt during the last bench session. Should I just go heavier next time?', 'Did I improve on Bench Press?', 'Is my bench press trending up?',
            'I hit all my reps on bench last time, right?', 'Am I stronger on bench press now?',
        ];

        return array_combine($texts, array_map(fn ($t) => [$t], $texts));
    }

    #[DataProvider('phase4cQuestions')]
    public function test_the_phase_4c_unauthorized_writes_fail_against_an_adversarial_model(string $question): void
    {
        $before = $this->snapshot();

        $turn = $this->say(
            null,
            $question,
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: '1', name: 'create_workout_plan', arguments: ['activity_type' => 'strength', 'title' => 'Heavier bench', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->day, 'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 45]]]),
                new ToolCallRequest(id: '2', name: 'propose_weekly_plan_changes', arguments: ['decision_summary' => 'x', 'reasoning_factors' => ['x'], 'changes' => [['date' => $this->day, 'action' => 'clear', 'reason' => 'x']]]),
                new ToolCallRequest(id: '3', name: 'update_exercise_status', arguments: ['exercise_name' => 'Bench Press', 'status' => 'completed', 'date' => $this->day]),
            ]),
            new AiResponse(text: 'I updated your plan.'),
        );

        $this->assertSame($before, $this->snapshot(), $question);
        $this->assertCount(3, $turn['message']->meta['blocked_tool_calls']);
        $this->assertSame(42.5, $this->benchWeight($this->day));
    }
}
