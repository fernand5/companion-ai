<?php

namespace Tests\Unit\Ai\Authorization;

use App\Services\Ai\Authorization\SaveWorkoutScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a workout line prescribes, as [low, high] for sets, reps and seconds (null when the line does not say). */
class SaveWorkoutPrescriptionTest extends TestCase
{
    public static function lines(): array
    {
        return [
            '3 x 8-12 reps' => ['Goblet Squat: 3 × 8–12 reps', [3, 3], [8, 12], null],
            'sets and reps ranges' => ['Push-ups: 2–3 sets × 10–12 reps', [2, 3], [10, 12], null],
            '3 x 45 seconds' => ['Plank: 3 × 45 seconds', [3, 3], null, [45, 45]],
            '3 x 12 reps' => ['Dumbbell Row: 3 × 12 reps', [3, 3], [12, 12], null],
            'sets of reps' => ['Shoulder Press: 3 sets of 8-12 reps', [3, 3], [8, 12], null],
            'sets of seconds' => ['Plank Hold: 3 sets of 30-45 seconds', [3, 3], null, [30, 45]],
            'sets of reps with no unit' => ['3 sets of 10 squats', [3, 3], [10, 10], null],
            'compact' => ['Push-ups 3x12', [3, 3], [12, 12], null],
            'compact range' => ['Push-ups 3x8-12', [3, 3], [8, 12], null],
            'short seconds' => ['Plank 3 x 30-45s', [3, 3], null, [30, 45]],
            'minutes become seconds' => ['Treadmill walk: 2 x 5 minutes', [2, 2], null, [300, 300]],
            'a duration alone' => ['Wall sit 45 seconds', null, null, [45, 45]],
            'a hyphenated duration' => ['45-second plank', null, null, [45, 45]],
            'reps alone' => ['Burpees: 10 reps', null, [10, 10], null],
            'a range with "to"' => ['Lunges: 3 sets of 8 to 12 reps', [3, 3], [8, 12], null],
            'a reversed range' => ['Lunges: 3 sets of 12-8 reps', [3, 3], [8, 12], null],
            'nothing prescribed' => ['Light stretching', null, null, null],
        ];
    }

    #[DataProvider('lines')]
    public function test_the_prescription_of_a_line(string $line, ?array $sets, ?array $reps, ?array $seconds): void
    {
        $this->assertSame(['sets' => $sets, 'reps' => $reps, 'seconds' => $seconds], SaveWorkoutScope::prescription($line));
    }

    public function test_the_low_end_is_what_normalise_stores_and_only_for_ranges(): void
    {
        $scope = new SaveWorkoutScope(1, '2026-10-28', 'message', ['Goblet Squat: 2–3 sets × 8–12 reps', 'Push-ups: 3 × 12 reps'], false);

        $this->assertSame(
            ['exercises' => [
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 2, 'planned_reps' => 8],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ]],
            $scope->normalise(['exercises' => [
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
            ]]),
        );
    }

    public function test_the_note_names_only_what_was_stored(): void
    {
        $scope = new SaveWorkoutScope(1, '2026-10-28', 'message', ['Goblet Squat: 3 × 8–12 reps', 'Push-ups: 3 × 12 reps'], false);

        $this->assertNull($scope->rangeNote(['exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12]]]));
        $this->assertNull($scope->rangeNote([]));
        $this->assertSame(
            'Your plan keeps one number for each of these, so I saved the low end of every range: Goblet Squat 8 reps (written 8-12). You can change them in the plan.',
            $scope->rangeNote(['exercises' => [['exercise_name' => 'Goblet Squat', 'planned_reps' => 8]]]),
        );
    }
}
