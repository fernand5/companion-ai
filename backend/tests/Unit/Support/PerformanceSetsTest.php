<?php

namespace Tests\Unit\Support;

use App\Services\Fitness\PerformanceSets;
use PHPUnit\Framework\TestCase;

class PerformanceSetsTest extends TestCase
{
    public function test_a_sets_reps_weight_tuple_expands_into_identical_sets(): void
    {
        $sets = PerformanceSets::expand(3, 10, 40.0, null);

        $this->assertCount(3, $sets);
        $this->assertSame(['reps' => 10, 'weight_kg' => 40.0, 'duration_seconds' => null, 'completed' => true], $sets[0]);
    }

    public function test_an_unknown_set_count_is_never_guessed_for_historical_data(): void
    {
        $this->assertSame([], PerformanceSets::expand(null, 10, 40.0, null));
        $this->assertSame([], PerformanceSets::expand(0, 10, 40.0, null));
    }

    public function test_new_user_input_without_a_set_count_is_read_as_one_set(): void
    {
        $this->assertCount(1, PerformanceSets::expand(null, 10, 40.0, null, assumeOneSet: true));
    }

    public function test_nothing_to_record_expands_to_no_sets(): void
    {
        $this->assertSame([], PerformanceSets::expand(3, null, null, null));
        $this->assertSame([], PerformanceSets::expand(null, null, null, null, assumeOneSet: true));
    }

    public function test_set_count_is_capped(): void
    {
        $this->assertCount(PerformanceSets::MAX_SETS, PerformanceSets::expand(500, 5, null, null));
    }

    public function test_duration_only_sets_are_supported(): void
    {
        $sets = PerformanceSets::expand(3, null, null, 60);

        $this->assertCount(3, $sets);
        $this->assertSame(60, $sets[0]['duration_seconds']);
        $this->assertNull($sets[0]['reps']);
    }

    public function test_normalize_defaults_completed_to_true_and_casts_types(): void
    {
        $sets = PerformanceSets::normalize([['reps' => '8', 'weight_kg' => '42.5'], ['reps' => 5, 'completed' => false]]);

        $this->assertSame(8, $sets[0]['reps']);
        $this->assertSame(42.5, $sets[0]['weight_kg']);
        $this->assertTrue($sets[0]['completed']);
        $this->assertFalse($sets[1]['completed']);
    }

    public function test_summary_is_the_completed_set_count_with_the_top_set_values(): void
    {
        $summary = PerformanceSets::summarize([
            ['reps' => 10, 'weight_kg' => 40, 'duration_seconds' => null, 'completed' => true],
            ['reps' => 8, 'weight_kg' => 45, 'duration_seconds' => null, 'completed' => true],
            ['reps' => 6, 'weight_kg' => 45, 'duration_seconds' => null, 'completed' => true],
            ['reps' => 3, 'weight_kg' => 60, 'duration_seconds' => null, 'completed' => false],
        ]);

        // The unfinished 60 kg set does not count; the heaviest completed set wins, ties by reps.
        $this->assertSame(['sets' => 3, 'reps' => 8, 'weight_kg' => 45.0, 'duration_seconds' => null], $summary);
    }

    public function test_summary_of_no_completed_sets_is_empty(): void
    {
        $this->assertSame(
            ['sets' => null, 'reps' => null, 'weight_kg' => null, 'duration_seconds' => null],
            PerformanceSets::summarize([]),
        );
    }

    public function test_summary_uses_the_longest_duration(): void
    {
        $summary = PerformanceSets::summarize([
            ['reps' => null, 'weight_kg' => null, 'duration_seconds' => 45, 'completed' => true],
            ['reps' => null, 'weight_kg' => null, 'duration_seconds' => 60, 'completed' => true],
        ]);

        $this->assertSame(2, $summary['sets']);
        $this->assertSame(60, $summary['duration_seconds']);
    }
}
