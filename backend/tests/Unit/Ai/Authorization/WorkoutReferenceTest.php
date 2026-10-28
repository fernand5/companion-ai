<?php

namespace Tests\Unit\Ai\Authorization;

use App\Models\Message;
use App\Services\Ai\Authorization\SavedWorkoutReference;
use App\Services\Ai\Authorization\SaveWorkoutDay;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use App\Services\Ai\Authorization\WorkoutReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Add this workout to my dashboard" must point at exactly one workout and one day. Where it cannot be established
 * the answer is "ask", never a pick: one clear routine, two routines, a routine and its revision, someone else's
 * routine, an unfinished list, and a request that comes after unrelated chat.
 */
class WorkoutReferenceTest extends TestCase
{
    private const ROUTINE = "Here's a 30-minute upper body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds";

    private const REQUEST = 'Add this workout to my dashboard';

    /** @param array<int, array{0: string, 1: string}|array{0: string, 1: string, 2: array}> $rows */
    private function thread(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row, int $i) => (new Message)->forceFill(['id' => $i + 1, 'role' => $row[0], 'content' => $row[1], 'meta' => $row[2] ?? null]));
    }

    private function resolve(array $rows, ?string $message = null, bool $inMessageOnly = false): WorkoutReference
    {
        $message ??= self::REQUEST;

        return (new SavedWorkoutReference)->resolve($message, $this->thread([...$rows, ['user', $message]]), $inMessageOnly);
    }

    // ---- one clear routine ------------------------------------------------------------------------------------

    public function test_one_clear_routine_in_the_reply_just_before_is_the_one_meant(): void
    {
        $reference = $this->resolve([['user', 'Give me an upper body workout'], ['assistant', self::ROUTINE]]);

        $this->assertTrue($reference->isFound());
        $this->assertSame(WorkoutReference::FROM_PREVIOUS_REPLY, $reference->origin);
        $this->assertSame(['Push-ups 3x12', 'Dumbbell rows 3x10', 'Plank 3x30 seconds'], $reference->items);
    }

    public function test_a_routine_the_user_writes_out_is_the_one_meant(): void
    {
        $reference = $this->resolve([], 'Add this workout to my dashboard: squats 3x10, push-ups 3x12');

        $this->assertTrue($reference->isFound());
        $this->assertSame(WorkoutReference::FROM_MESSAGE, $reference->origin);
        $this->assertSame(['squats 3x10', 'push-ups 3x12'], $reference->items);
    }

    public function test_a_workout_described_only_by_its_length_is_not_a_routine(): void
    {
        // "30 minute workout" has an amount but no exercise: it falls back to the reply before it.
        $reference = $this->resolve([['assistant', self::ROUTINE]], 'Add this 30 minute workout to my dashboard');

        $this->assertTrue($reference->isFound());
        $this->assertSame(WorkoutReference::FROM_PREVIOUS_REPLY, $reference->origin);
        $this->assertSame(WorkoutReference::NONE, $this->resolve([['assistant', 'Hello! How are you feeling?']], 'Add this 30 minute workout to my dashboard')->status);
    }

    public function test_a_request_that_names_its_own_workout_looks_at_nothing_earlier(): void
    {
        $reference = $this->resolve([['assistant', self::ROUTINE]], 'Add a 30 minute treadmill run to my plan for Friday', inMessageOnly: true);

        $this->assertTrue($reference->isFound());
        $this->assertSame(WorkoutReference::FROM_MESSAGE, $reference->origin);

        $this->assertSame(WorkoutReference::NONE, $this->resolve([['assistant', self::ROUTINE]], 'Add a workout to my plan for Friday', inMessageOnly: true)->status);
    }

    // ---- two routines -----------------------------------------------------------------------------------------

    private const TWO_OPTIONS = "Two ways to train today.\n\n**Option 1**\n- Squats 3x10\n- Lunges 3x12\n\n**Option 2**\n- Push-ups 3x12\n- Plank 3x30 seconds\n\nWhich do you prefer?";

    public function test_two_routines_in_one_reply_are_not_chosen_between(): void
    {
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', self::TWO_OPTIONS]])->status);
    }

    #[DataProvider('pickedOptions')]
    public function test_the_user_naming_one_of_them_picks_it(string $message, array $items): void
    {
        $reference = $this->resolve([['assistant', self::TWO_OPTIONS]], $message);

        $this->assertTrue($reference->isFound(), $message);
        $this->assertSame($items, $reference->items, $message);
    }

    /** @return array<string, array{string, array<int, string>}> */
    public static function pickedOptions(): array
    {
        $first = ['Squats 3x10', 'Lunges 3x12'];
        $second = ['Push-ups 3x12', 'Plank 3x30 seconds'];

        return [
            'option 2' => ['Add option 2 to my dashboard', $second],
            'Option 1, capitalised' => ['Add Option 1 to my dashboard', $first],
            'the second one' => ['Add the second one to my dashboard', $second],
            'the first workout' => ['Save the first workout', $first],
        ];
    }

    public function test_naming_something_that_matches_neither_or_both_does_not_pick(): void
    {
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', self::TWO_OPTIONS]], 'Add option 3 to my dashboard')->status);
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', self::TWO_OPTIONS]], 'Add both options to my dashboard')->status, 'Both named: still not one.');
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', self::TWO_OPTIONS]], 'Add the best one to my dashboard')->status);
    }

    public function test_alternatives_and_lettered_workouts_count_as_separate_routines(): void
    {
        $alternatives = "Try squats 3x10 and lunges 3x12.\n\nAlternatively, run 20 minutes on the treadmill.";
        $lettered = "Workout A\n- Squats 3x10\n\nWorkout B\n- Bench press 3x8";

        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', $alternatives]])->status);
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', $lettered]])->status);
        $this->assertSame(['Bench press 3x8'], $this->resolve([['assistant', $lettered]], 'Add workout B to my dashboard')->items);
    }

    public function test_a_workout_the_user_wrote_while_the_reply_before_holds_another_is_not_guessed(): void
    {
        // Is "this" the coach's session, or the squats the user mentioned in passing? Ask.
        $this->assertSame(WorkoutReference::AMBIGUOUS, $this->resolve([['assistant', self::ROUTINE]], 'Add this to my dashboard, I like squats 3x10')->status);

        // Written out as the subject of the request, it is theirs.
        $this->assertTrue($this->resolve([['assistant', self::ROUTINE]], 'Add this workout to my dashboard: squats 3x10')->isFound());
    }

    // ---- outdated routine, then a revised one -------------------------------------------------------------------

    public function test_the_revised_routine_replaces_the_outdated_one(): void
    {
        $reference = $this->resolve([
            ['user', 'Give me an upper body workout'],
            ['assistant', self::ROUTINE],
            ['user', 'Make it lighter on the shoulders'],
            ['assistant', "Sure, swapping the push-ups:\n1. Incline push-ups 3x10\n2. Dumbbell rows 3x10\n3. Plank 3x20 seconds"],
        ]);

        $this->assertTrue($reference->isFound());
        $this->assertSame(['Incline push-ups 3x10', 'Dumbbell rows 3x10', 'Plank 3x20 seconds'], $reference->items, 'Only the latest version, none of the first.');
    }

    public function test_an_outdated_routine_is_not_saved_when_the_last_reply_is_not_a_routine(): void
    {
        $reference = $this->resolve([
            ['assistant', self::ROUTINE],
            ['user', 'Make it lighter on the shoulders'],
            ['assistant', 'Do you want me to swap the push-ups for something easier on the shoulders?'],
        ]);

        $this->assertSame(WorkoutReference::NONE, $reference->status);
    }

    // ---- someone else's routine ---------------------------------------------------------------------------------

    #[DataProvider('somebodyElsesWords')]
    public function test_a_routine_somebody_else_wrote_is_not_adopted_on_the_users_behalf(string $reply): void
    {
        $this->assertSame(WorkoutReference::QUOTED, $this->resolve([['assistant', $reply]])->status);
    }

    /** @return array<string, array{string}> */
    public static function somebodyElsesWords(): array
    {
        return [
            'a quoted block' => ["Your friend's plan, as you pasted it:\n> Squats 3x10\n> Lunges 3x12"],
            'a quoted span' => ['They wrote "Squats 3x10, lunges 3x12, plank 3x30 seconds" in the group chat.'],
            'attributed to a friend' => ["Your friend said you should do:\n- Squats 3x10\n- Lunges 3x12"],
            'according to an article' => ["According to the article:\n- Squats 3x10\n- Lunges 3x12"],
            'from a website' => ["From a website you mentioned:\n- Squats 3x10\n- Lunges 3x12"],
        ];
    }

    public function test_a_routine_the_user_attributes_to_someone_else_in_their_own_message_is_not_adopted_either(): void
    {
        $this->assertSame(WorkoutReference::QUOTED, $this->resolve([], 'My trainer wrote me: squats 3x10, lunges 3x12. Add this workout to my dashboard')->status);
        $this->assertSame(WorkoutReference::QUOTED, $this->resolve([], 'Add this workout to my dashboard: "squats 3x10, lunges 3x12"')->status);
    }

    // ---- an incomplete list ---------------------------------------------------------------------------------------

    #[DataProvider('unfinishedRoutines')]
    public function test_an_unfinished_routine_is_not_saved(string $reply): void
    {
        $this->assertSame(WorkoutReference::INCOMPLETE, $this->resolve([['assistant', $reply]])->status);
    }

    /** @return array<string, array{string}> */
    public static function unfinishedRoutines(): array
    {
        return [
            'trailing off' => ["Try:\n- Squats 3x10\n- Lunges 3x12\n- ..."],
            'etc' => ['Do squats 3x10, lunges 3x12, etc.'],
            'announces more than it lists' => ["Here are 5 exercises:\n- Squats 3x10\n- Lunges 3x12\n- Plank 3x30 seconds"],
            'a numbered step with no detail' => ["1. Squats 3x10\n2. Lunges\n3. Plank 3x30 seconds"],
            'cut off after a colon' => ["- Squats 3x10\n- Lunges 3x12\nThen finish with:"],
            'an ellipsis character' => ['Squats 3x10, lunges 3x12 and then…'],
            'refers back to something else' => ["Squats 3x10, then the same as Monday's lunges 3x12"],
        ];
    }

    public function test_a_complete_routine_with_a_warm_up_and_cool_down_is_complete(): void
    {
        $reference = $this->resolve([['assistant', "1. Warm-up: 5 min easy jog\n2. Squats 3x10\n3. Lunges 3x12\n4. Cool down: light stretching"]]);

        $this->assertTrue($reference->isFound());
    }

    // ---- after unrelated messages ----------------------------------------------------------------------------------

    public function test_save_this_workout_after_unrelated_messages_asks_instead_of_going_back(): void
    {
        $reference = $this->resolve([
            ['user', 'Give me an upper body workout'],
            ['assistant', self::ROUTINE],
            ['user', 'How much protein should I eat?'],
            ['assistant', 'Around 1.6 g per kilogram of body weight is a common range for people training regularly.'],
        ], 'Save this workout');

        $this->assertSame(WorkoutReference::NONE, $reference->status);
    }

    public function test_a_clarifying_question_from_the_application_does_not_hide_the_routine(): void
    {
        $reference = $this->resolve([
            ['assistant', self::ROUTINE],
            ['user', 'Add this workout to my dashboard'],
            ['assistant', 'I haven\'t changed your plan: I\'m not sure which day you mean.', ['save_clarification' => true]],
        ], 'Add this workout to my dashboard for Friday');

        $this->assertTrue($reference->isFound());
        $this->assertCount(3, $reference->items);
    }

    public function test_an_ordinary_reply_in_between_does_hide_it(): void
    {
        $reference = $this->resolve([
            ['assistant', self::ROUTINE],
            ['user', 'Add this workout to my dashboard'],
            ['assistant', 'Sure, anything else?'],
        ]);

        $this->assertSame(WorkoutReference::NONE, $reference->status);
    }

    public function test_a_request_with_no_reply_before_it_has_nothing_to_point_at(): void
    {
        // The routine is above an unrelated message of the user's, not directly above the request the application asked about.
        $reference = $this->resolve([['assistant', self::ROUTINE], ['user', 'How much protein should I eat?'], ['user', 'Add this workout to my dashboard'], ['assistant', 'Which day?', ['save_clarification' => true]]]);

        $this->assertSame(WorkoutReference::NONE, $reference->status);
    }

    public function test_the_users_own_earlier_report_is_not_a_workout_on_offer(): void
    {
        $this->assertSame(WorkoutReference::NONE, $this->resolve([['user', 'I did squats 3x10 yesterday'], ['assistant', 'Nice work!']])->status);
    }

    // ---- what counts as an exercise line -----------------------------------------------------------------------------

    /** @return array<string, array{string, int}> */
    public static function lines(): array
    {
        return [
            'sets x reps' => ['Push-ups 3x12', 1],
            'sets of reps' => ['3 sets of 10 squats', 1],
            'a duration' => ['Plank 3 x 30 seconds', 1],
            'a cardio session' => ['Run 20 minutes on the treadmill', 1],
            'a warm-up with a movement' => ['Warm-up: 5 min easy jog', 1],
            'two on one line' => ['Push-ups 3x12 and plank 3x30 seconds', 2],
            'a comma list with the amounts apart' => ['Squats, 3 sets of 10, lunges 3x12', 2],
            'an unfamiliar movement named before its amount' => ['Goblet thruster 3x8', 1],
            'markdown' => ['**Dumbbell rows** 3x10', 1],
            'total time is not an exercise' => ['Total time: 30 minutes', 0],
            'rest is not an exercise' => ['Rest 60 seconds between sets', 0],
            'a duration of the whole session' => ['Duration: 45 minutes', 0],
            'advice' => ['Aim for 3 sets', 0],
            'no amounts' => ['Do some squats and lunges', 0],
        ];
    }

    #[DataProvider('lines')]
    public function test_exercise_lines_are_recognised(string $text, int $count): void
    {
        $this->assertCount($count, SavedWorkoutReference::items($text), $text);
    }

    // ---- the day -------------------------------------------------------------------------------------------------------

    private function day(string $message, string $workout = ''): array
    {
        // A Wednesday.
        return SaveWorkoutDay::resolve($message, $workout, CarbonImmutable::parse('2026-10-28'));
    }

    /** @return array<string, array{string, ?string, ?string}> */
    public static function days(): array
    {
        return [
            'nothing named is today' => ['Add this workout to my dashboard', '2026-10-28', null],
            'today' => ["Add this to today's plan", '2026-10-28', null],
            'tomorrow' => ['Add this workout to my plan for tomorrow', '2026-10-29', null],
            'a weekday later this week' => ['Add this workout to my plan for Friday', '2026-10-30', null],
            'the same weekday is today' => ['Add this workout to Wednesday', '2026-10-28', null],
            'an earlier weekday is next week' => ['Add this workout to Monday', '2026-11-02', null],
            'a date' => ['Add this workout to my plan on 2026-11-05', '2026-11-05', null],
            'yesterday' => ['Add this workout to yesterday', null, SaveWorkoutDay::PAST],
            'a past date' => ['Add this workout on 2026-10-01', null, SaveWorkoutDay::PAST],
            'next friday is ambiguous' => ['Add this workout for next Friday', null, SaveWorkoutDay::WHICH_DAY],
            'next week is ambiguous' => ['Add this workout to next week', null, SaveWorkoutDay::WHICH_DAY],
            'two different days' => ['Add this workout to Thursday and Friday', null, SaveWorkoutDay::WHICH_DAY],
            'an impossible date' => ['Add this workout on 2026-02-31', null, SaveWorkoutDay::WHICH_DAY],
            'far in the future' => ['Add this workout on 2027-06-01', null, SaveWorkoutDay::WHICH_DAY],
        ];
    }

    #[DataProvider('days')]
    public function test_the_day_comes_from_the_users_words(string $message, ?string $date, ?string $problem): void
    {
        $this->assertSame(['date' => $date, 'problem' => $problem], $this->day($message), $message);
    }

    public function test_a_workout_that_is_itself_for_another_day_asks_which_day_when_the_user_named_none(): void
    {
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, $this->day(self::REQUEST, 'Tomorrow: squats 3x10')['problem']);
        $this->assertSame(SaveWorkoutDay::WHICH_DAY, $this->day(self::REQUEST, 'Friday session: squats 3x10')['problem']);
        $this->assertSame('2026-10-28', $this->day(self::REQUEST, 'Today: squats 3x10')['date']);

        // The user's own word decides.
        $this->assertSame('2026-10-29', $this->day('Add this workout for tomorrow', 'Friday session: squats 3x10')['date']);
    }

    // ---- what a call may contain -------------------------------------------------------------------------------------

    private function scope(array $items = ['Push-ups 3x12', 'Dumbbell rows 3x10', 'Plank 3x30 seconds']): SaveWorkoutScope
    {
        return new SaveWorkoutScope(1, '2026-10-28', implode("\n", $items), $items, false);
    }

    private function exact(): array
    {
        return [
            'planned_date' => '2026-10-28',
            'exercises' => [
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ];
    }

    public function test_exactly_the_requested_workout_on_the_requested_day_is_within_scope(): void
    {
        $this->assertNull($this->scope()->mismatch($this->exact()));

        $withoutDate = $this->exact();
        unset($withoutDate['planned_date']);
        $this->assertNull($this->scope()->mismatch($withoutDate), 'An omitted day is filled in by the application, not guessed.');

        $this->assertNull($this->scope()->mismatch(['planned_date' => '2026-10-28T00:00:00'] + $this->exact()), 'The same day in another notation.');
    }

    public function test_names_are_matched_loosely_but_only_to_the_workouts_own_exercises(): void
    {
        $call = $this->exact();
        $call['exercises'][0]['exercise_name'] = 'Push up';
        $call['exercises'][1]['exercise_name'] = 'Dumbbell Row (each arm)';
        $call['exercises'][2]['exercise_name'] = 'Plank hold';

        $this->assertNull($this->scope()->mismatch($call));
    }

    public function test_the_model_cannot_move_the_workout_to_another_day(): void
    {
        foreach (['2026-10-29', '2026-10-27', '2027-01-01', 'tomorrow', ''] as $date) {
            $call = ['planned_date' => $date] + $this->exact();

            if ($date === '') {
                $this->assertNull($this->scope()->mismatch($call), 'Empty means unspecified.');

                continue;
            }

            $this->assertStringContainsString('planned_date must be 2026-10-28', (string) $this->scope()->mismatch($call), $date);
        }
    }

    public function test_the_model_cannot_add_drop_or_change_exercises(): void
    {
        $added = $this->exact();
        $added['exercises'][] = ['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10];
        $this->assertStringContainsString('"Burpees" is not one of the exercises', (string) $this->scope()->mismatch($added));

        $dropped = $this->exact();
        array_pop($dropped['exercises']);
        $this->assertStringContainsString('Include every exercise', (string) $this->scope()->mismatch($dropped));

        $renamed = $this->exact();
        $renamed['exercises'][0]['exercise_name'] = 'Bench press';
        $this->assertStringContainsString('is not one of the exercises', (string) $this->scope()->mismatch($renamed));

        $nothing = $this->exact();
        $nothing['exercises'] = [];
        $this->assertNotNull($this->scope()->mismatch($nothing), 'A workout of three exercises cannot be saved as an empty plan.');
    }

    public function test_one_line_of_the_workout_cannot_be_used_twice(): void
    {
        $call = $this->exact();
        $call['exercises'][1] = ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12];

        $this->assertNotNull($this->scope()->mismatch($call));
    }

    public function test_sets_reps_and_durations_must_be_the_ones_written(): void
    {
        foreach ([['planned_sets', 5], ['planned_reps', 20]] as [$field, $value]) {
            $call = $this->exact();
            $call['exercises'][0][$field] = $value;
            $this->assertNotNull($this->scope()->mismatch($call), $field);
        }

        $call = $this->exact();
        $call['exercises'][2]['planned_duration_seconds'] = 90;
        $this->assertNotNull($this->scope()->mismatch($call), 'A longer plank than written.');

        // Minutes written, seconds given: the same duration.
        $run = new SaveWorkoutScope(1, '2026-10-28', 'Run 20 minutes', ['Run 20 minutes'], false);
        $this->assertNull($run->mismatch(['exercises' => [['exercise_name' => 'Run', 'planned_duration_seconds' => 1200]]]));
        $this->assertNotNull($run->mismatch(['exercises' => [['exercise_name' => 'Run', 'planned_duration_seconds' => 2400]]]));
    }
}
