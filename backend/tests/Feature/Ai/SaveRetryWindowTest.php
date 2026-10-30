<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Authorization\SaveRetry;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * A request to save a workout can be retried, or answered, only while it is fresh: within the 15 minutes a confirmation
 * lasts (PlanReplacementProposal::TTL_MINUTES) and on the calendar day, in the user's own timezone, on which it was made.
 * Otherwise nothing is replayed, nothing is written, and the user is told what to say to start again.
 */
class SaveRetryWindowTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private const SIMPLE = "Here's a quick upper-body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10";

    private const CHOICE = "Here's a workout:\n1. **Dumbbell Floor Press:** 3 sets of 8-12 reps\n2. **Bent-Over Row:** 3 sets of 8-12 reps\n3. **Romanian Deadlift or Goblet Squat:** 3 sets of 8-12 reps\n\nWould you like me to add this to your dashboard?";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
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

    /** Move the clock to a local time of the user's (America/Bogota). */
    private function at(string $local): void
    {
        $this->travelTo(Carbon::parse($local, 'America/Bogota'));
    }

    private function saveCall(array $exercises, ?string $date = null): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: array_filter([
            'activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'As asked.', 'reasoning_factors' => ['You asked to add it'],
            'planned_date' => $date, 'exercises' => $exercises,
        ]))]);
    }

    private function simple(): array
    {
        return [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]];
    }

    /** A save the model fails at three times: the application says nothing was saved, and the request stays open. */
    private function failingRequest(int $conversation, string $request = 'Add this workout to my dashboard'): array
    {
        $wrong = $this->saveCall([['exercise_name' => 'Burpees', 'planned_sets' => 9, 'planned_reps' => 99]]);
        $turn = $this->say($request, $conversation, null, $wrong, $wrong, $wrong, new AiResponse(text: 'Done, saved!'));
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('the workout was not saved', $turn['answer']);

        return $turn;
    }

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

    private function planOn(string $date): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $date)->first();
    }

    private function toolsOffered(int $callIndex): array
    {
        return array_column($this->fake->calls[$callIndex]['tools'], 'name');
    }

    private function shown(string $reply = self::SIMPLE): int
    {
        return $this->say('Give me a quick upper body workout', null, null, new AiResponse(text: $reply))['conversation'];
    }

    // ---- normal retries ---------------------------------------------------------------------------------------------

    public function test_a_retry_inside_the_window_is_the_same_request_on_the_same_day(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        $this->at('2026-10-28 10:14:59');
        $retry = $this->say('could you try to add them again?', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Added them to today.'));

        $this->assertSame('Added them to today.', $retry['answer']);
        $this->assertSame(['Push-ups', 'Dumbbell rows'], $this->planOn('2026-10-28')->exercises->pluck('exercise_name')->all());
        $this->assertSame(1, $this->plans());
    }

    public function test_a_retry_late_in_the_evening_still_means_the_day_it_was_asked_on(): void
    {
        $this->at('2026-10-28 23:50');
        $conversation = $this->shown();
        $this->failingRequest($conversation, 'Add this workout to my dashboard for tomorrow');

        $this->at('2026-10-28 23:58');
        $this->say('try again', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Done.'));

        $this->assertNotNull($this->planOn('2026-10-29'), '"tomorrow" is still the 29th.');
        $this->assertNull($this->planOn('2026-10-28'));
    }

    public function test_an_answer_inside_the_window_continues_the_request(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown(self::CHOICE);
        $question = $this->say('Add this workout to my dashboard', $conversation);
        $this->assertSame('choice', $question['message']->meta['save_pending']['kind']);

        $this->at('2026-10-28 10:14');
        $this->say('Goblet Squat', $conversation, null, $this->saveCall([
            ['exercise_name' => 'Dumbbell Floor Press', 'planned_sets' => 3, 'planned_reps' => 8],
            ['exercise_name' => 'Bent-Over Row', 'planned_sets' => 3, 'planned_reps' => 8],
            ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 8],
        ]), new AiResponse(text: 'Done.'));

        $this->assertSame(['Dumbbell Floor Press', 'Bent-Over Row', 'Goblet Squat'], $this->planOn('2026-10-28')->exercises->pluck('exercise_name')->all());
    }

    // ---- expiry ---------------------------------------------------------------------------------------------------------

    public function test_a_retry_after_the_window_fails_closed_and_says_how_to_start_again(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        $this->at('2026-10-28 10:15:01');
        $retry = $this->say('could you try to add them again?', $conversation);

        $this->assertSame(0, $retry['calls'], 'No model call, no tool.');
        $this->assertSame(0, $this->plans());
        $this->assertStringStartsWith("I haven't changed your plan", $retry['answer']);
        $this->assertStringContainsString('more than 15 minutes old', $retry['answer']);
        $this->assertStringContainsString('add this workout to my dashboard', $retry['answer'], 'Actionable: it says what to type.');
        $this->assertStringNotContainsString('access', $retry['answer'], 'Not a missing tool.');
        $this->assertTrue($retry['message']->meta['save_clarification']);
    }

    public function test_an_expired_request_does_not_stop_a_fresh_explicit_one(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        $this->at('2026-10-28 11:00');
        $this->say('try again', $conversation);
        $this->assertSame(0, $this->plans());

        // The user's own, new request, in the words the application suggested: the workout above is still the one meant.
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Added.'));

        $this->assertSame(['Push-ups', 'Dumbbell rows'], $this->planOn('2026-10-28')->exercises->pluck('exercise_name')->all());
    }

    public function test_an_answer_after_the_window_is_not_a_continuation(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown(self::CHOICE);
        $this->say('Add this workout to my dashboard', $conversation);

        $this->at('2026-10-28 10:20');
        $late = $this->say('Goblet Squat', $conversation, null, $this->saveCall([['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 8]]), new AiResponse(text: 'Done.'));

        $this->assertSame(0, $this->plans(), 'A stale question is not answered into a write.');
        $this->assertStringContainsString('more than 15 minutes old', $late['answer']);
        $this->assertSame(0, $late['calls']);
    }

    public function test_a_day_answer_after_the_window_is_not_a_continuation(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $asked = $this->say('Add this workout to my dashboard for yesterday', $conversation);
        $this->assertSame('day', $asked['message']->meta['save_pending']['kind']);

        $this->at('2026-10-28 10:30');
        $late = $this->say('Friday', $conversation, null, $this->saveCall($this->simple(), '2026-10-30'));

        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('more than 15 minutes old', $late['answer']);
    }

    // ---- the date is not reinterpreted -----------------------------------------------------------------------------

    public function test_a_retry_after_midnight_is_not_given_a_new_today(): void
    {
        $this->at('2026-10-28 23:55');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        // Ten minutes later, but it is now the 29th for this user.
        $this->at('2026-10-29 00:05');
        $retry = $this->say('try again', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Done.'));

        $this->assertSame(0, $retry['calls']);
        $this->assertSame(0, $this->plans(), 'Neither the 28th nor the 29th was written.');
        $this->assertStringContainsString('a new day has started', $retry['answer']);
        $this->assertStringContainsString('2026-10-28', $retry['answer'], 'It says which day the request was made on.');
        $this->assertStringContainsString('add this workout to my dashboard', $retry['answer']);
    }

    public function test_the_users_own_midnight_decides_not_utc(): void
    {
        // 21:00 in Bogota is already past midnight in UTC; the user's day is still the 28th at 21:10.
        $this->at('2026-10-28 21:00');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        $this->at('2026-10-28 21:10');
        $this->say('try again', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Done.'));

        $this->assertNotNull($this->planOn('2026-10-28'), 'Same local day, so it is a retry (UTC would say the day had changed).');
    }

    // ---- what a retry can never be ----------------------------------------------------------------------------------

    public function test_a_successful_save_ends_the_retry_opportunity(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $this->say('Add this workout to my dashboard', $conversation, null, $this->saveCall($this->simple()), new AiResponse(text: 'Done.'));
        $this->assertSame(1, $this->plans());

        $this->at('2026-10-28 10:02');
        $again = $this->say('try again', $conversation, null, new AiResponse(text: 'It is already on your plan.'));

        $this->assertNotContains('create_workout_plan', $this->toolsOffered(count($this->fake->calls) - 1), 'Nothing is left to retry.');
        $this->assertSame(1, $this->plans());
        $this->assertSame('It is already on your plan.', $again['answer']);
    }

    public function test_another_conversation_cannot_replay_the_request(): void
    {
        $this->at('2026-10-28 10:00');
        $first = $this->shown();
        $this->failingRequest($first);

        $this->at('2026-10-28 10:01');
        $other = $this->shown();
        $this->say('try again', $other, null, new AiResponse(text: 'What would you like me to try again?'));

        $this->assertNotContains('create_workout_plan', $this->toolsOffered(count($this->fake->calls) - 1));
        $this->assertSame(0, $this->plans());
    }

    public function test_another_user_cannot_replay_it_even_inside_the_window(): void
    {
        $this->at('2026-10-28 10:00');
        $conversation = $this->shown();
        $this->failingRequest($conversation);

        $bob = $this->newUser();
        $this->at('2026-10-28 10:01');
        $intruder = $this->ask($bob, 'try again', $conversation);

        $this->assertNotNull($intruder['error'], 'Not their conversation.');
        $this->assertSame(0, $this->plans());
        $this->assertSame(0, $this->plans($bob));

        // Bob's own conversation, with the request copied in as someone else's assistant text, grants him nothing.
        $theirs = $this->actingAs($bob, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        $this->say('try again', $theirs, $bob, new AiResponse(text: 'Try what again?'));
        $this->assertSame(0, $this->plans($bob));
    }

    public function test_a_request_of_unknown_age_is_not_fresh(): void
    {
        $request = new Message(['role' => 'user', 'content' => 'Add this workout to my dashboard']);
        $retry = new Message(['role' => 'user', 'content' => 'try again']);

        $stale = app(SaveRetry::class)->continuation('try again', new Collection([$request, $retry]), $this->user);

        $this->assertSame(SaveRetry::EXPIRED, $stale['kind'], 'No timestamp, no replay.');
        $this->assertSame(SaveRetry::TOO_OLD, $stale['reason']);

        $request->created_at = Carbon::now();
        $fresh = app(SaveRetry::class)->continuation('try again', new Collection([$request, $retry]), $this->user);

        $this->assertSame(SaveRetry::RETRY, $fresh['kind']);
    }

    public function test_the_hours_old_production_conversation_asks_for_a_fresh_request_instead_of_replaying_the_old_one(): void
    {
        $this->at('2026-10-27 18:00');
        $conversation = $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');

        foreach ([
            ['user', 'Add this workout to my dashboard'],
            ['assistant', "I'm sorry, but I don't have access to the plan updating tool right now, so I can't add these to your dashboard."],
            ['user', 'Give me an upper body workout'],
            ['assistant', self::CHOICE],
        ] as [$role, $content]) {
            Message::create(['conversation_id' => $conversation, 'role' => $role, 'content' => $content, 'meta' => null]);
        }

        $this->at('2026-10-28 09:00');
        $retry = $this->say('could you try to add them again?', $conversation);

        $this->assertSame(0, $retry['calls']);
        $this->assertSame(0, $this->plans());
        $this->assertStringContainsString('more than 15 minutes old', $retry['answer']);

        // A fresh request, in the user's own words, is a new request: the workout shown above is resolved again.
        $fresh = $this->say('Add this workout to my dashboard', $conversation);

        $this->assertSame('choice', $fresh['message']->meta['save_pending']['kind'], 'It asks which exercise, as it would for a new request.');
        $this->assertSame(0, $this->plans());
    }
}
