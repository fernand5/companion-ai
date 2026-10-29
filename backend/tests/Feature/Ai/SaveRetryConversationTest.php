<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Production: the user asked the coach to add a workout to the dashboard; it failed; the coach showed an upper-body
 * workout that included "Romanian Deadlift or Goblet Squat"; the user wrote "could you try to add them again?" and the
 * coach answered "I don't have access to the plan updating tool right now". Nothing was saved.
 *
 * Cause: that sentence is not a request the classifier knew ("them", "again", no destination), so the plan tool was
 * withheld for the turn and the model, told it was "not available", said it had no access. These replay the whole
 * conversation, turn by turn, through the real endpoint with a scripted model.
 */
class SaveRetryConversationTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    /** What the coach showed, in the shape it writes workouts. */
    private const WORKOUT = "Here's an upper-body workout for you:\n\n1. **Dumbbell Floor Press:** 3 sets of 8-12 reps\n2. **Bent-Over Row:** 3 sets of 8-12 reps\n3. **Shoulder Press:** 3 sets of 8-12 reps\n4. **Plank Hold:** 3 sets of 30-45 seconds\n5. **Romanian Deadlift or Goblet Squat:** 3 sets of 8-12 reps\n\nWould you like me to add this to your dashboard?";

    private const SIMPLE = "Here's a quick upper-body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10";

    private const FALSE_CLAIM = "I'm sorry, but I don't have access to the plan updating tool right now, so I can't add these to your dashboard.";

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

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function saveCall(array $exercises, ?string $date = null, string $title = 'Upper body'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'As asked.', 'reasoning_factors' => ['You asked to add it'],
            'planned_date' => $date, 'exercises' => $exercises,
        ]))]);
    }

    private function productionExercises(string $choice = 'Goblet Squat'): array
    {
        return [
            ['exercise_name' => 'Dumbbell Floor Press', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Bent-Over Row', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Shoulder Press', 'planned_sets' => 3, 'planned_reps' => 10],
            ['exercise_name' => 'Plank Hold', 'planned_sets' => 3, 'planned_duration_seconds' => 40],
            ['exercise_name' => $choice, 'planned_sets' => 3, 'planned_reps' => 10],
        ];
    }

    private function simpleExercises(): array
    {
        return [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]];
    }

    private function conversation(?User $as = null): int
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
    }

    /** Put a message into the stored conversation as an earlier turn would have left it. */
    private function history(int $conversation, string $role, string $content, ?array $meta = null): Message
    {
        return Message::create(['conversation_id' => $conversation, 'role' => $role, 'content' => $content, 'meta' => $meta]);
    }

    /** @return array{answer: string, calls: int, message: Message, conversation: int} */
    private function say(string $message, ?int $conversation, ?User $as = null, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($as ?? $this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'], 'calls' => count($this->fake->calls) - $before, 'conversation' => $result['conversation'],
            'message' => Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function plans(?User $user = null): int
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->count();
    }

    private function planOn(string $date, ?User $user = null): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', ($user ?? $this->user)->id)->whereDate('planned_date', $date)->first();
    }

    private function nextFriday(): string
    {
        $day = Carbon::now('America/Bogota')->startOfDay();

        while ($day->dayOfWeekIso !== 5) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    private function offered(int $callIndex): array
    {
        return array_column($this->fake->calls[$callIndex]['tools'], 'name');
    }

    // ---- the production conversation, end to end ----------------------------------------------------------------------------

    public function test_the_whole_production_conversation_ends_with_the_workout_saved(): void
    {
        // 1. The user asks to add a workout; there is none yet, so the application asks, in its own words.
        $first = $this->say('Add this workout to my dashboard', null);
        $this->assertSame(0, $first['calls']);
        $this->assertStringContainsString("I don't see a workout", $first['answer']);
        $conversation = $first['conversation'];

        // 2. The coach shows the workout.
        $shown = $this->say('Give me an upper body workout', $conversation, null, new AiResponse(text: self::WORKOUT));
        $this->assertStringContainsString('Romanian Deadlift or Goblet Squat', $shown['answer']);
        $this->assertSame(0, $this->plans());

        // 3. "could you try to add them again?": the plan tool is offered, and the exercise that is a choice is asked about
        //    in plain words, instead of the coach saying it has no access.
        $retry = $this->say('could you try to add them again?', $conversation);
        $this->assertSame(0, $retry['calls'], 'The application asks; the model is not left to improvise an excuse.');
        $this->assertSame(0, $this->plans());
        $this->assertStringStartsWith("I haven't changed your plan", $retry['answer']);
        $this->assertStringContainsString('"Romanian Deadlift" or "Goblet Squat"', $retry['answer']);
        $this->assertStringContainsString('Reply with just the name', $retry['answer']);
        $this->assertStringNotContainsString('access', $retry['answer']);
        $this->assertSame('choice', $retry['message']->meta['save_pending']['kind']);
        $this->assertSame([['Romanian Deadlift', 'Goblet Squat']], $retry['message']->meta['save_pending']['options']);

        // 4. The user names one; exactly that workout is saved, today, and nothing is claimed before it is.
        $saved = $this->say('Goblet Squat', $conversation, null, $this->saveCall($this->productionExercises()), new AiResponse(text: 'Done, it is on your dashboard for today.'));

        $this->assertContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2));
        $plan = $this->planOn($this->today);
        $this->assertNotNull($plan, 'The workout is persisted.');
        $this->assertSame(['Dumbbell Floor Press', 'Bent-Over Row', 'Shoulder Press', 'Plank Hold', 'Goblet Squat'], $plan->exercises->pluck('exercise_name')->all());
        $this->assertSame([8, 8, 8, null, 8], $plan->exercises->pluck('planned_reps')->all(), 'A range is stored as its low end, whatever the model chose inside it (SaveRangePolicyTest).');
        $this->assertSame(30, $plan->exercises[3]->planned_duration_seconds, 'The low end of 30-45 seconds.');
        $this->assertStringStartsWith('Done, it is on your dashboard for today.', $saved['answer']);
        $this->assertStringContainsString('I saved the low end of every range', $saved['answer'], 'The user is told what was stored.');
        $this->assertStringContainsString('Goblet Squat: 3 sets of 8-12 reps', $this->fake->calls[count($this->fake->calls) - 2]['messages'][0]['content'], 'The model is told the chosen exercise, not the choice.');
        $this->assertStringNotContainsString('Romanian Deadlift', $this->fake->calls[count($this->fake->calls) - 2]['messages'][0]['content']);
    }

    public function test_the_stored_production_conversation_can_be_resumed_after_the_false_failure_replies(): void
    {
        // The conversation exactly as the old code left it: the false "no access" replies are ordinary, unflagged messages.
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Add this workout to my dashboard');
        $this->history($conversation, 'assistant', 'I am unable to update or push a workout plan directly to your Dashboard.');
        $this->history($conversation, 'user', 'Give me an upper body workout');
        $this->history($conversation, 'assistant', self::WORKOUT);
        $this->history($conversation, 'user', 'could you try to add them again?');
        $this->history($conversation, 'assistant', self::FALSE_CLAIM);

        // Neither the false replies nor the retry that met them stand in the way of the workout they were about.
        $resume = $this->say('try again', $conversation);

        $this->assertSame(0, $resume['calls']);
        $this->assertStringContainsString('"Romanian Deadlift" or "Goblet Squat"', $resume['answer']);

        $this->say('Romanian Deadlift', $conversation, null, $this->saveCall($this->productionExercises('Romanian Deadlift')), new AiResponse(text: 'Added.'));

        $this->assertSame(['Dumbbell Floor Press', 'Bent-Over Row', 'Shoulder Press', 'Plank Hold', 'Romanian Deadlift'], $this->planOn($this->today)->exercises->pluck('exercise_name')->all());
    }

    public function test_the_exact_production_sentence_works_when_the_workout_has_no_choice(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Add this workout to my dashboard');
        $this->history($conversation, 'assistant', self::FALSE_CLAIM);
        $this->history($conversation, 'user', 'Give me a quick upper body workout');
        $this->history($conversation, 'assistant', self::SIMPLE);

        $turn = $this->say('could you try to add them again?', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Added them to today.'));

        $this->assertSame(['Push-ups', 'Dumbbell rows'], $this->planOn($this->today)->exercises->pluck('exercise_name')->all());
        $this->assertSame('Added them to today.', $turn['answer']);
        $this->assertContains('create_workout_plan', $this->offered(0));
    }

    // ---- ambiguous workout details are not an unavailable tool ---------------------------------------------------------------

    public function test_the_choice_must_be_named_and_a_guess_is_never_made(): void
    {
        foreach (['both', 'Romanian deadlift and goblet squat', 'whichever is easier', 'deadlift', 'ok'] as $answer) {
            Cache::flush();
            $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
            $this->say('Add this workout to my dashboard', $conversation);

            $turn = $this->say($answer, $conversation, null, new AiResponse(text: 'Okay.'));

            $this->assertSame(0, $this->plans(), $answer);
            $this->assertStringNotContainsString('access', $turn['answer'], $answer);
        }
    }

    public function test_an_answer_that_is_not_one_leaves_the_question_standing_and_grants_nothing(): void
    {
        $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation);

        $turn = $this->say('What is a goblet squat?', $conversation, null, $this->saveCall($this->productionExercises()), new AiResponse(text: 'It is a squat holding a weight at your chest.'));

        $this->assertSame(0, $this->plans(), 'A question is not an answer, and the model\'s write attempt was refused.');
        $this->assertNotContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2));
    }

    public function test_the_model_cannot_pick_the_other_exercise_for_the_user(): void
    {
        $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation);

        $turn = $this->say('Goblet Squat', $conversation, null,
            $this->saveCall($this->productionExercises('Romanian Deadlift')),
            $this->saveCall([...array_slice($this->productionExercises(), 0, 4), ['exercise_name' => 'Romanian Deadlift', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10]]),
            $this->saveCall($this->productionExercises('Goblet Squat')),
            new AiResponse(text: 'Added.'),
        );

        $this->assertSame(['outside_requested_workout', 'outside_requested_workout'], array_column($turn['message']->meta['blocked_tool_calls'], 'reason'));
        $this->assertSame('Goblet Squat', $this->planOn($this->today)->exercises->last()->exercise_name);
        $this->assertSame(5, $this->planOn($this->today)->exercises->count());
    }

    // ---- retry after an application-generated refusal -------------------------------------------------------------------------

    public function test_a_retry_after_the_applications_not_saved_notice_gets_a_second_chance(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];

        // The model fails to write it properly three times: the application says plainly that nothing was saved.
        $wrong = $this->saveCall([['exercise_name' => 'Burpees', 'planned_sets' => 9, 'planned_reps' => 99]]);
        $first = $this->say('Add this workout to my dashboard', $conversation, null, $wrong, $wrong, $wrong, new AiResponse(text: 'Done, saved!'));
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('the workout was not saved', $first['answer'], 'The claim of success is corrected by what was actually persisted.');
        $this->assertTrue($first['message']->meta['save_clarification']);

        // "try again" is the same request, still pointing at the workout above the notice.
        $second = $this->say('could you try that again?', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'It is on your dashboard now.'));

        $this->assertSame(['Push-ups', 'Dumbbell rows'], $this->planOn($this->today)->exercises->pluck('exercise_name')->all());
        $this->assertSame('It is on your dashboard now.', $second['answer']);
    }

    public function test_a_retry_after_a_refusal_for_a_performed_day_is_refused_again_and_nothing_is_replaced(): void
    {
        $this->logSets($this->user, 0, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        $before = $this->planOn($this->today)->revision();
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];

        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Replaced it.'));
        $retry = $this->say('try again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Replaced it.'));

        $this->assertSame($before, $this->planOn($this->today)->revision());
        $this->assertStringContainsString('already has recorded workout performance', $retry['answer']);
    }

    public function test_a_retry_onto_a_day_with_a_plan_still_asks_before_replacing_it(): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Old plan', 'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]]]);
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'I cannot.'));

        $retry = $this->say('add them again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Replaced.'));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertStringContainsString('Do you want me to replace it', $retry['answer']);

        $this->say('yes', $conversation);
        $this->assertSame('Upper body', $this->planOn($this->today)->title);
    }

    // ---- the day -------------------------------------------------------------------------------------------------------------------

    public function test_a_missing_day_is_asked_for_and_the_plain_answer_continues_the_request(): void
    {
        $conversation = $this->say('Give me a workout', null, null, new AiResponse(text: "Friday session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10"))['conversation'];

        $ask = $this->say('Add this workout to my dashboard', $conversation);
        $this->assertSame(0, $ask['calls']);
        $this->assertStringContainsString('Which day should I use?', $ask['answer']);
        $this->assertStringContainsString('Reply with just the day', $ask['answer']);
        $this->assertSame('day', $ask['message']->meta['save_pending']['kind']);

        $this->say('Friday', $conversation, null, $this->saveCall($this->simpleExercises(), $this->nextFriday()), new AiResponse(text: 'Added for Friday.'));

        $this->assertSame(['Push-ups', 'Dumbbell rows'], $this->planOn($this->nextFriday())->exercises->pluck('exercise_name')->all());
        $this->assertNull($this->planOn($this->today) === null || $this->today === $this->nextFriday() ? null : $this->planOn($this->today));
    }

    public function test_a_day_the_model_picks_instead_of_the_one_the_user_gave_is_refused(): void
    {
        $conversation = $this->say('Give me a workout', null, null, new AiResponse(text: "Friday session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10"))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation);
        $tomorrow = Carbon::now('America/Bogota')->addDays(3)->toDateString();

        $this->say('Friday', $conversation, null, $this->saveCall($this->simpleExercises(), $tomorrow === $this->nextFriday() ? Carbon::now('America/Bogota')->addDays(4)->toDateString() : $tomorrow, 'Wrong day'), $this->saveCall($this->simpleExercises(), $this->nextFriday()), new AiResponse(text: 'Added.'));

        $this->assertSame(1, $this->plans());
        $this->assertNotNull($this->planOn($this->nextFriday()));
    }

    public function test_a_day_in_the_past_is_asked_about_again_and_try_again_re_asks_the_open_question(): void
    {
        $conversation = $this->say('Give me a workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        $this->say('Add this workout to my dashboard yesterday', $conversation);

        $again = $this->say('try again', $conversation);

        $this->assertSame(0, $again['calls']);
        $this->assertStringContainsString('only add a workout to today or a later day', $again['answer']);
        $this->assertSame(0, $this->plans());

        $this->say('tomorrow', $conversation, null, $this->saveCall($this->simpleExercises(), Carbon::now('America/Bogota')->addDay()->toDateString()), new AiResponse(text: 'Added for tomorrow.'));
        $this->assertSame(1, $this->plans());
    }

    // ---- false "no access" answers -----------------------------------------------------------------------------------------------------

    public function test_a_false_no_access_answer_is_corrected_by_the_application(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];

        $turn = $this->say('I wonder if you can put those on my plan', $conversation, null, new AiResponse(text: self::FALSE_CLAIM));

        $this->assertStringContainsString("I don't have access to the plan updating tool", $turn['answer']);
        $this->assertStringContainsString('that is not a limit of the app', $turn['answer']);
        $this->assertStringContainsString('say "add this workout to my dashboard"', $turn['answer']);
        $this->assertSame(0, $this->plans());
    }

    public function test_the_model_is_told_never_to_claim_it_lacks_access_when_a_tool_is_withheld(): void
    {
        $this->say('How was my week?', null, null, new AiResponse(text: 'Pretty consistent.'));

        $prompt = $this->fake->calls[0]['messages'][0]['content'];
        $this->assertStringContainsString('never say you lack access to a tool', $prompt);
        $this->assertStringContainsString('add this workout to my dashboard', $prompt);
    }

    public function test_a_real_safety_refusal_is_not_mistaken_for_a_false_claim(): void
    {
        $turn = $this->say('Can you change my plan so I run a marathon next week?', null, null, new AiResponse(text: "I can't safely change your plan to a marathon next week; it would be too big a jump, and I'd recommend building up gradually."));

        $this->assertStringNotContainsString('not a limit of the app', $turn['answer']);
    }

    // ---- claims of success must be true --------------------------------------------------------------------------------------------------

    public function test_a_claim_of_success_without_anything_persisted_is_corrected(): void
    {
        $turn = $this->say('Give me a workout for today', null, null, new AiResponse(text: "Here you go: squats 3x10 and lunges 3x12. I've added it to your dashboard."));

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString("I haven't changed your plan: nothing was saved this time", $turn['answer']);
    }

    public function test_a_true_claim_and_an_honest_offer_are_left_alone(): void
    {
        $true = $this->say('Give me a workout for today', null, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: "I've added it to your dashboard."));
        $this->assertSame("I've added it to your dashboard.", $true['answer']);
        $this->assertSame(1, $this->plans());

        $offer = $this->say('How was my week?', null, null, new AiResponse(text: 'Want me to add a recovery day to your plan?'));
        $this->assertSame('Want me to add a recovery day to your plan?', $offer['answer']);
    }

    // ---- nothing here is a permission -------------------------------------------------------------------------------------------------------

    public function test_again_with_no_earlier_request_to_save_grants_nothing(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];

        foreach (['try again', 'could you try to add them again?', 'add them again', 'retry'] as $message) {
            $this->say($message, $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Done.'));

            $this->assertSame(0, $this->plans(), $message);
            $this->assertNotContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2), $message);
        }
    }

    public function test_a_retry_after_a_successful_save_does_not_save_it_again(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Added.'));
        $revision = $this->planOn($this->today)->revision();

        $again = $this->say('could you try to add them again?', $conversation, null, $this->saveCall($this->simpleExercises(), null, 'Duplicate'), new AiResponse(text: 'Added again.'));

        $this->assertSame($revision, $this->planOn($this->today)->revision());
        $this->assertSame(1, $this->plans());
        $this->assertNotContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2), 'Nothing is open to retry, so no write is even offered.');
        $this->assertArrayNotHasKey('pending_plan_replacement', $again['message']->meta ?? [], 'And no question about replacing what was just saved.');
    }

    public function test_only_an_earlier_request_to_save_can_be_retried_not_other_requests(): void
    {
        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Old plan', 'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]]]);
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        // A general "update my plan" and a wish are not requests to put this workout on the plan, and their retry is not one either.
        $this->say('Update my plan for today', $conversation, null, new AiResponse(text: 'Which change?'));
        $this->say('I want to try heavier weights', $conversation, null, new AiResponse(text: 'Okay.'));

        $this->say('try again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Done.'));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertNotContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2));
    }

    public function test_a_broader_request_that_happened_to_include_a_save_is_not_replayed_by_try_again(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        // "Update my plan" is a general change request with wider permissions; a retry must not replay that.
        $this->say('Update my plan. Add this workout to my dashboard.', $conversation, null, new AiResponse(text: 'Which change?'));

        $this->say('try again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Done.'));

        $this->assertNotContains('create_workout_plan', $this->offered(count($this->fake->calls) - 2));
        $this->assertSame(0, $this->plans());
    }

    public function test_a_request_that_is_too_old_in_the_conversation_is_not_retried(): void
    {
        $conversation = $this->conversation();
        $this->history($conversation, 'user', 'Add this workout to my dashboard');
        $this->history($conversation, 'assistant', "Sorry, I couldn't.");

        for ($i = 0; $i < 6; $i++) {
            $this->history($conversation, 'user', "Chat {$i}");
            $this->history($conversation, 'assistant', "Reply {$i}");
        }

        $this->history($conversation, 'user', 'Give me a quick upper body workout');
        $this->history($conversation, 'assistant', self::SIMPLE);

        $this->say('try again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Done.'));

        $this->assertSame(0, $this->plans(), 'The request is outside the recent conversation, so "again" has nothing to point at.');
    }

    public function test_the_pending_question_is_not_answered_by_a_yes_or_a_vague_reference(): void
    {
        $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation);

        foreach (['yes', 'that one', 'the first', 'sure, go ahead'] as $message) {
            $this->say($message, $conversation, null, $this->saveCall($this->productionExercises()), new AiResponse(text: 'Done.'));

            $this->assertSame(0, $this->plans(), $message);
        }
    }

    public function test_a_retry_cannot_widen_the_workout_or_the_day(): void
    {
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'I cannot.'));

        $padded = $this->saveCall([...$this->simpleExercises(), ['exercise_name' => 'Burpees', 'planned_sets' => 5, 'planned_reps' => 20]], null, 'Padded');
        $elsewhere = $this->saveCall($this->simpleExercises(), Carbon::now('America/Bogota')->addDays(2)->toDateString(), 'Elsewhere');

        $this->say('could you try to add them again?', $conversation, null, $padded, $elsewhere, new AiResponse(text: 'Done.'));

        $this->assertSame(0, $this->plans());
    }

    public function test_a_weight_with_no_baseline_is_still_left_unset_on_a_retry(): void
    {
        $conversation = $this->say('Give me a workout', null, null, new AiResponse(text: "1. Goblet Squat 3x10 at 24 kg\n2. Push-ups 3x12"))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'I cannot.'));

        $turn = $this->say('try again', $conversation, null, $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]), new AiResponse(text: 'Added.'));

        $this->assertNull($this->planOn($this->today)->exercises->first()->planned_weight_kg);
        $this->assertStringContainsString('I left the starting weight for Goblet Squat unset', $turn['answer']);
    }

    // ---- other users ---------------------------------------------------------------------------------------------------------------------------

    public function test_another_user_cannot_answer_or_resume_someone_elses_question(): void
    {
        $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation);

        $bob = $this->newUser();
        $intruder = $this->ask($bob, 'Goblet Squat', $conversation);

        $this->assertNotNull($intruder['error'], 'Not their conversation.');
        $this->assertSame(0, $this->plans());
        $this->assertSame(0, $this->plans($bob));
    }

    public function test_a_pending_question_copied_into_another_users_conversation_authorizes_nothing_there(): void
    {
        $conversation = $this->say('Give me an upper body workout', null, null, new AiResponse(text: self::WORKOUT))['conversation'];
        $question = $this->say('Add this workout to my dashboard', $conversation)['message'];

        $bob = $this->newUser();
        $theirs = $this->conversation($bob);
        $this->history($theirs, 'assistant', self::WORKOUT);
        $this->history($theirs, 'assistant', $question->content, $question->meta);

        $this->say('Goblet Squat', $theirs, $bob, $this->saveCall($this->productionExercises()), new AiResponse(text: 'Done.'));

        $this->assertSame(0, $this->plans($bob), 'There is no request of Bob\'s own for the answer to continue.');
        $this->assertSame(0, $this->plans());
    }

    public function test_the_save_goes_to_the_signed_in_users_plan_only(): void
    {
        $bob = $this->newUser();
        app(WorkoutPlanService::class)->createOrReplace($bob, ['planned_date' => $this->today, 'activity_type' => 'strength', 'title' => 'Bobs plan']);
        $conversation = $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: self::SIMPLE))['conversation'];
        $this->say('Add this workout to my dashboard', $conversation, null, new AiResponse(text: 'I cannot.'));

        $this->say('try again', $conversation, null, $this->saveCall($this->simpleExercises()), new AiResponse(text: 'Added.'));

        $this->assertSame('Bobs plan', $this->planOn($this->today, $bob)->title);
        $this->assertSame(1, $this->plans());
    }
}
