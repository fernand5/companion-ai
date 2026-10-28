<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Through the real chat endpoint: when the workout or the day cannot be established, nothing is written, the model is
 * not asked to improvise, and the user is asked; once they answer, exactly what they chose is saved, and the model
 * cannot enlarge it.
 */
class SaveWorkoutReferenceFlowTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    private const TWO_OPTIONS = "Two ways to train today.\n\n**Option 1**\n- Squats 3x10\n- Lunges 3x12\n\n**Option 2**\n- Push-ups 3x12\n- Plank 3x30 seconds\n\nWhich do you prefer?";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
    }

    private function saveCall(array $exercises, ?string $date = null, string $title = 'Saved'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => $title, 'decision_summary' => 'As asked.', 'reasoning_factors' => ['You asked'],
            'planned_date' => $date, 'exercises' => $exercises,
        ]))]);
    }

    /** The coach's reply is $text; returns the conversation. */
    private function coachSays(string $text, string $asked = 'Give me a workout'): int
    {
        Cache::flush();
        $this->fake->queue(new AiResponse(text: $text));

        return $this->ask($this->user, $asked)['conversation'];
    }

    /** @return array{answer: string, calls: int, message: Message} */
    private function say(string $message, int $conversation, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'],
            'calls' => count($this->fake->calls) - $before,
            'message' => Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function plans(): int
    {
        return WorkoutPlan::where('user_id', $this->user->id)->count();
    }

    private function assertAsked(array $turn, string $containing): void
    {
        $this->assertSame(0, $turn['calls'], 'The model is not asked to improvise around a reference nobody can resolve.');
        $this->assertSame(0, $this->plans(), 'Nothing was written.');
        $this->assertStringStartsWith("I haven't changed your plan", $turn['answer']);
        $this->assertStringContainsString($containing, $turn['answer']);
        $this->assertTrue($turn['message']->meta['save_clarification'], 'Marked, so the answer can still find the workout above it.');
    }

    // ---- two routines -----------------------------------------------------------------------------------------------

    public function test_two_routines_are_not_chosen_between(): void
    {
        $conversation = $this->coachSays(self::TWO_OPTIONS);

        $this->assertAsked($this->say('Add this workout to my dashboard', $conversation), 'more than one workout could be meant');
    }

    public function test_naming_one_saves_exactly_that_one_and_the_model_cannot_add_the_other(): void
    {
        $conversation = $this->coachSays(self::TWO_OPTIONS);
        $this->say('Add this workout to my dashboard', $conversation);

        // The model tries to save both options; only option 2 is what was chosen.
        $greedy = $this->saveCall([
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10],
        ], null, 'Both');
        $right = $this->saveCall([
            ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
        ], null, 'Option 2');

        $turn = $this->say('Add option 2 to my dashboard', $conversation, $greedy, $right, new AiResponse(text: 'Added option 2.'));

        $this->assertSame(['Option 2'], WorkoutPlan::where('user_id', $this->user->id)->pluck('title')->all());
        $this->assertSame(['Push-ups', 'Plank'], WorkoutPlan::where('user_id', $this->user->id)->first()->exercises->pluck('exercise_name')->all());
        $this->assertSame('outside_requested_workout', $turn['message']->meta['blocked_tool_calls'][0]['reason']);
        $this->assertStringContainsString('Push-ups 3x12 | Plank 3x30 seconds', json_encode($this->fake->calls[count($this->fake->calls) - 3]['messages'][0]['content']), 'The model is told which exercises, and only those.');
    }

    // ---- an outdated routine, then a revised one -------------------------------------------------------------------------

    public function test_the_revised_routine_is_the_one_saved(): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $this->say('Make it lighter on the shoulders', $conversation, new AiResponse(text: "Sure:\n1. Incline push-ups 3x10\n2. Dumbbell rows 3x10"));

        // Saving the OLD version is outside what was asked.
        $old = $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]], null, 'Old');
        $new = $this->saveCall([['exercise_name' => 'Incline push-ups', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]], null, 'Revised');

        $this->say('Add this workout to my dashboard', $conversation, $old, $new, new AiResponse(text: 'Added.'));

        $this->assertSame(['Revised'], WorkoutPlan::where('user_id', $this->user->id)->pluck('title')->all());
    }

    // ---- someone else's routine -------------------------------------------------------------------------------------------

    public function test_a_routine_quoted_from_someone_else_is_not_saved_on_a_guess(): void
    {
        $conversation = $this->coachSays("Your friend said you should do:\n- Squats 3x10\n- Lunges 3x12");

        $this->assertAsked($this->say('Add this workout to my dashboard', $conversation), 'quoted from someone else');
    }

    public function test_the_same_routine_in_the_users_own_words_is_saved(): void
    {
        $conversation = $this->coachSays("Your friend said you should do:\n- Squats 3x10\n- Lunges 3x12");
        $this->say('Add this workout to my dashboard', $conversation);

        $this->say('Add this workout to my dashboard: squats 3x10 and lunges 3x12', $conversation, $this->saveCall([['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Lunges', 'planned_sets' => 3, 'planned_reps' => 12]]), new AiResponse(text: 'Added.'));

        $this->assertSame(1, $this->plans());
    }

    // ---- an incomplete list -----------------------------------------------------------------------------------------------------

    public function test_an_unfinished_routine_is_not_saved(): void
    {
        $conversation = $this->coachSays("Here are 5 exercises:\n- Squats 3x10\n- Lunges 3x12\n- Plank 3x30 seconds");

        $this->assertAsked($this->say('Add this workout to my dashboard', $conversation), 'looks unfinished');
    }

    public function test_the_model_cannot_complete_a_list_by_itself(): void
    {
        $conversation = $this->coachSays('Squats 3x10, lunges 3x12, etc.');

        $turn = $this->say('Add this workout to my dashboard', $conversation, $this->saveCall([['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Lunges', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30]]));

        $this->assertSame(0, $turn['calls']);
        $this->assertSame(0, $this->plans());
    }

    // ---- after unrelated messages -------------------------------------------------------------------------------------------------

    public function test_save_this_workout_after_unrelated_messages_asks_instead_of_going_back(): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $this->say('How much protein should I eat?', $conversation, new AiResponse(text: 'Around 1.6 g per kilogram is a common range.'));

        $this->assertAsked($this->say('Save this workout', $conversation), "don't see a workout in my last reply");
    }

    public function test_the_user_can_then_name_the_workout_in_one_message(): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $this->say('How much protein should I eat?', $conversation, new AiResponse(text: 'Around 1.6 g per kilogram.'));
        $this->say('Save this workout', $conversation);

        $this->say('Save this workout: push-ups 3x12 and dumbbell rows 3x10', $conversation, $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]]), new AiResponse(text: 'Saved.'));

        $this->assertSame(1, $this->plans());
    }

    // ---- the day -----------------------------------------------------------------------------------------------------------------

    public function test_a_day_the_user_names_is_the_day_and_the_model_cannot_move_it(): void
    {
        $friday = now('America/Bogota')->next('Friday')->toDateString();
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $exercises = [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]];

        $this->say('Add this workout to my plan for Friday', $conversation, $this->saveCall($exercises, $this->today, 'Wrong day'), $this->saveCall($exercises, null, 'Friday'), new AiResponse(text: 'Added for Friday.'));

        $this->assertSame(['Friday'], WorkoutPlan::where('user_id', $this->user->id)->pluck('title')->all());
        $this->assertSame($friday, WorkoutPlan::where('user_id', $this->user->id)->first()->planned_date->toDateString());
    }

    public function test_an_omitted_day_is_filled_in_by_the_application_not_defaulted_by_the_model(): void
    {
        $tomorrow = now('America/Bogota')->addDay()->toDateString();
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");

        $this->say('Add this workout to my plan for tomorrow', $conversation, $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]]), new AiResponse(text: 'Added.'));

        $this->assertSame($tomorrow, WorkoutPlan::where('user_id', $this->user->id)->first()->planned_date->toDateString(), 'Left out of the call, it would have gone to today.');
    }

    #[DataProvider('unclearDays')]
    public function test_a_day_that_is_unclear_is_asked_about(string $request): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");

        $this->assertAsked($this->say($request, $conversation), 'which day');
    }

    /** @return array<string, array{string}> */
    public static function unclearDays(): array
    {
        return [
            'next friday' => ['Add this workout for next Friday'],
            'a past day' => ['Add this workout to yesterday'],
            'two days' => ['Add this workout to Thursday and Friday'],
            'a date that does not exist' => ['Add this workout on 2026-02-30'],
        ];
    }

    public function test_a_workout_that_is_itself_for_another_day_asks_which_day(): void
    {
        $conversation = $this->coachSays("Friday session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10");

        $this->assertAsked($this->say('Add this workout to my dashboard', $conversation), 'which day');

        // Answering is enough: the workout above the question is still the one meant.
        $this->say('Add this workout to my dashboard for Friday', $conversation, $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]]), new AiResponse(text: 'Added.'));
        $this->assertSame(1, $this->plans());
    }

    // ---- what the model writes must stay inside the reference -------------------------------------------------------------------

    public function test_inflated_sets_and_reps_are_refused(): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");

        $this->say('Add this workout to my dashboard', $conversation, $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 6, 'planned_reps' => 25], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]]), new AiResponse(text: 'Added.'));

        $this->assertSame(0, $this->plans());
    }

    public function test_a_weight_in_the_coachs_own_reply_without_any_baseline_is_not_saved(): void
    {
        $conversation = $this->coachSays("1. Bench press 3x8 at 80 kg\n2. Push-ups 3x12");

        $turn = $this->say('Add this workout to my dashboard', $conversation, $this->saveCall([['exercise_name' => 'Bench press', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 80], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]), new AiResponse(text: 'Added.'));

        $this->assertNull(WorkoutPlan::where('user_id', $this->user->id)->first()->exercises->first()->planned_weight_kg);
        $this->assertStringContainsString('I left the starting weight for Bench press unset', $turn['answer']);
    }

    public function test_the_weight_the_user_types_in_the_save_request_is_not_taken_either(): void
    {
        $conversation = $this->coachSays("1. Bench press 3x8\n2. Push-ups 3x12");

        $this->say('Add this workout to my dashboard with bench at 70 kg', $conversation, $this->saveCall([['exercise_name' => 'Bench press', 'planned_sets' => 3, 'planned_reps' => 8, 'planned_weight_kg' => 70], ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]), new AiResponse(text: 'Added.'));

        $this->assertNull(WorkoutPlan::where('user_id', $this->user->id)->first()->exercises->first()->planned_weight_kg, 'A weight needs the explicit "set my bench to 70 kg" request and its own limits.');
    }

    // ---- regressions on what is no longer broad -----------------------------------------------------------------------------------

    public function test_put_this_workout_on_my_dashboard_is_scoped_like_add(): void
    {
        $conversation = $this->coachSays("1. Push-ups 3x12\n2. Dumbbell rows 3x10");
        $padded = $this->saveCall([['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10], ['exercise_name' => 'Burpees', 'planned_sets' => 5, 'planned_reps' => 20]]);

        $this->say('Put this workout on my dashboard', $conversation, $padded, new AiResponse(text: 'Done.'));

        $this->assertSame(0, $this->plans(), 'This phrasing used to be read as a general permission to change the plan.');
    }

    public function test_a_general_change_request_is_still_general(): void
    {
        $conversation = $this->coachSays('Hello there, how can I help?');

        $this->say('Update my plan for today', $conversation, $this->saveCall([['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]], null, 'General'), new AiResponse(text: 'Updated.'));

        $this->assertSame(['General'], WorkoutPlan::where('user_id', $this->user->id)->pluck('title')->all());
    }
}
