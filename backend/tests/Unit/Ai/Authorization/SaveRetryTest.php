<?php

namespace Tests\Unit\Ai\Authorization;

use App\Models\Message;
use App\Services\Ai\Authorization\SavedWorkoutReference;
use App\Services\Ai\Authorization\SaveRetry;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Could you try to add them again?" is a request only because of the request before it; and only a message that
 * really answers the application's question continues one. Everything else grants nothing.
 */
class SaveRetryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function retries(): array
    {
        return array_map(fn (string $m) => [$m], [
            'the production message' => 'could you try to add them again?',
            'plain' => 'try again',
            'with punctuation' => 'Try again.',
            'polite' => 'Please try again',
            'trailing please' => 'try again please',
            'retry' => 'retry',
            'add them again' => 'add them again',
            'with a place' => 'Can you add them to my dashboard again?',
            'try that' => 'try that again',
            'after a yes' => 'yes, try again',
            'do it' => 'do it again',
            'another try' => 'give it another try',
            'gerund' => 'try adding them again',
            'once more' => 'save it once more',
            'this workout' => 'try to add this workout again',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function notRetries(): array
    {
        return array_map(fn (string $m) => [$m], [
            'a bare word' => 'try',
            'a bare again' => 'again',
            'a question about the past' => 'why did you not try again?',
            'a denial' => "don't try again",
            'a denial of adding' => "no, don't add them again",
            'past tense' => 'I tried again yesterday',
            'with new content' => 'try again tomorrow with a heavier weight',
            'with a load' => "let's try again with 20 kg",
            'another list' => 'add them to my shopping list again',
            'agreement' => 'yes',
            'a reference' => 'that one',
            'too long' => 'could you try to add them again '.str_repeat('and again ', 20),
            'empty' => '',
        ]);
    }

    #[DataProvider('retries')]
    public function test_a_plain_request_to_try_it_again_is_recognised(string $message): void
    {
        $this->assertTrue(SaveRetry::isRetryPhrase($message), $message);
    }

    #[DataProvider('notRetries')]
    public function test_nothing_else_is_taken_for_one(string $message): void
    {
        $this->assertFalse(SaveRetry::isRetryPhrase($message), $message);
    }

    // ---- alternatives -------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, ?array<int, string>}> */
    public static function alternatives(): array
    {
        return [
            'the production exercise' => ['Romanian Deadlift or Goblet Squat: 3 sets of 8-12 reps', ['Romanian Deadlift', 'Goblet Squat']],
            'lower case, a duration' => ['Run or walk 20 minutes', ['Run', 'walk']],
            'the amount first' => ['3 sets of 10 squats or lunges', ['squats', 'lunges']],
            'a slash' => ['Row / Pull-down 3x10', ['Row', 'Pull-down']],
            'three' => ['Squats, lunges or step-ups 3x10', ['Squats', 'lunges', 'step-ups']],
            'markdown' => ['**Bench press or incline press** 3x8', ['Bench press', 'incline press']],
            'one exercise' => ['Push-ups 3x12', null],
            'a plain list line' => ['Dumbbell Floor Press: 3 sets of 8-12 reps', null],
            'an alternative amount, not exercise' => ['Squats 3x10 or 4x8', null],
            'or until' => ['Plank 3x30 seconds or until form breaks', null],
            'or more' => ['Push-ups 3 sets, 10 or more reps', null],
        ];
    }

    #[DataProvider('alternatives')]
    public function test_an_exercise_that_offers_two_is_recognised(string $item, ?array $expected): void
    {
        $this->assertSame($expected, SavedWorkoutReference::alternatives($item), $item);
    }

    /** @return array<string, array{string, ?string}> */
    public static function selections(): array
    {
        return [
            'the name' => ['Goblet Squat', 'Goblet Squat'],
            'in a sentence' => ['use the goblet squat please', 'Goblet Squat'],
            'the other, plural' => ['romanian deadlifts', 'Romanian Deadlift'],
            'both' => ['Romanian deadlift and goblet squat', null],
            'a word of one' => ['deadlift', null],
            'a word of the other' => ['squat', null],
            'neither' => ['whichever is easier', null],
            'both words' => ['both', null],
        ];
    }

    #[DataProvider('selections')]
    public function test_the_users_pick_is_the_one_they_named_and_never_a_guess(string $message, ?string $expected): void
    {
        $this->assertSame($expected, SavedWorkoutReference::selects(['Romanian Deadlift', 'Goblet Squat'], $message), $message);
    }

    public function test_a_longer_name_that_contains_a_shorter_one_is_not_two_answers(): void
    {
        $this->assertSame('Goblet Squat', SavedWorkoutReference::selects(['Squat', 'Goblet Squat'], 'goblet squat'));
        $this->assertSame('Squat', SavedWorkoutReference::selects(['Squat', 'Goblet Squat'], 'squat'), 'Exactly the shorter name, which is not in the longer');
    }

    // ---- ranges ---------------------------------------------------------------------------------------------------------

    private function scope(string $line): SaveWorkoutScope
    {
        return new SaveWorkoutScope(1, '2026-10-28', $line, [$line], false);
    }

    public function test_a_value_inside_a_range_the_user_was_given_is_one_of_the_values_offered(): void
    {
        $scope = $this->scope('Dumbbell Floor Press: 3 sets of 8-12 reps');

        foreach ([8, 10, 12] as $reps) {
            $this->assertNull($scope->mismatch(['exercises' => [['exercise_name' => 'Dumbbell Floor Press', 'planned_sets' => 3, 'planned_reps' => $reps]]]), "{$reps}");
        }

        foreach ([7, 13, 20] as $reps) {
            $this->assertNotNull($scope->mismatch(['exercises' => [['exercise_name' => 'Dumbbell Floor Press', 'planned_sets' => 3, 'planned_reps' => $reps]]]), "{$reps}");
        }

        $this->assertNotNull($scope->mismatch(['exercises' => [['exercise_name' => 'Dumbbell Floor Press', 'planned_sets' => 5, 'planned_reps' => 10]]]), 'The sets are 3, not a range.');
    }

    public function test_a_duration_range_allows_values_in_it_in_seconds_and_in_minutes(): void
    {
        $plank = $this->scope('Plank Hold: 3 sets of 30-45 seconds');
        $this->assertNull($plank->mismatch(['exercises' => [['exercise_name' => 'Plank Hold', 'planned_duration_seconds' => 40]]]));
        $this->assertNotNull($plank->mismatch(['exercises' => [['exercise_name' => 'Plank Hold', 'planned_duration_seconds' => 60]]]));

        $run = $this->scope('Treadmill run 20-30 minutes');
        $this->assertNull($run->mismatch(['exercises' => [['exercise_name' => 'Treadmill run', 'planned_duration_seconds' => 1500]]]), '25 minutes');
        $this->assertNotNull($run->mismatch(['exercises' => [['exercise_name' => 'Treadmill run', 'planned_duration_seconds' => 2400]]]), '40 minutes');
    }

    // ---- reading the conversation after a failure ---------------------------------------------------------------------------

    private const ROUTINE = "Here's an upper-body workout:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10";

    private function thread(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row, int $i) => (new Message)->forceFill(['id' => $i + 1, 'role' => $row[0], 'content' => $row[1], 'meta' => $row[2] ?? null]));
    }

    public function test_a_retry_reads_past_the_replies_that_only_said_it_could_not(): void
    {
        $history = $this->thread([
            ['assistant', self::ROUTINE],
            ['user', 'Add this workout to my dashboard'],
            ['assistant', "I don't have access to the plan updating tool right now."],
            ['user', 'could you try to add them again?'],
            ['assistant', "I'm sorry, I still can't update your plan."],
            ['user', 'try again'],
        ]);

        $reference = (new SavedWorkoutReference)->resolve('try again', $history, retry: true);

        $this->assertTrue($reference->isFound());
        $this->assertSame(['Push-ups 3x12', 'Dumbbell rows 3x10'], $reference->items);
    }

    public function test_without_a_retry_those_replies_do_stand_in_the_way(): void
    {
        $history = $this->thread([
            ['assistant', self::ROUTINE],
            ['user', 'Add this workout to my dashboard'],
            ['assistant', "I can't."],
            ['user', 'Add this workout to my dashboard'],
        ]);

        $this->assertSame('none', (new SavedWorkoutReference)->resolve('Add this workout to my dashboard', $history)->status);
    }

    public function test_a_retry_does_not_reach_back_across_an_unrelated_exchange(): void
    {
        $history = $this->thread([
            ['assistant', self::ROUTINE],
            ['user', 'How much protein should I eat?'],
            ['assistant', 'Around 1.6 g per kilogram.'],
            ['user', 'try again'],
        ]);

        $this->assertSame('none', (new SavedWorkoutReference)->resolve('try again', $history, retry: true)->status);
    }
}
