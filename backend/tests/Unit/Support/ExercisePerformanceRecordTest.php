<?php

namespace Tests\Unit\Support;

use App\Services\Fitness\ExercisePerformanceRecord;
use PHPUnit\Framework\TestCase;

class ExercisePerformanceRecordTest extends TestCase
{
    private function set(?int $reps, ?float $weight = null, ?int $duration = null, bool $completed = true): array
    {
        return ['reps' => $reps, 'weight_kg' => $weight, 'duration_seconds' => $duration, 'completed' => $completed];
    }

    public function test_it_keeps_the_per_set_reps_instead_of_flattening_them(): void
    {
        $this->assertSame('8,8,7 @ 45 kg', ExercisePerformanceRecord::describeSets([$this->set(8, 45), $this->set(8, 45), $this->set(7, 45)]));
    }

    public function test_it_shows_each_weight_when_the_weight_changes(): void
    {
        $this->assertSame('8@45, 8@47.5, 6@50 kg', ExercisePerformanceRecord::describeSets([$this->set(8, 45), $this->set(8, 47.5), $this->set(6, 50)]));
    }

    public function test_bodyweight_and_timed_sets(): void
    {
        $this->assertSame('15,15,12 reps', ExercisePerformanceRecord::describeSets([$this->set(15), $this->set(15), $this->set(12)]));
        $this->assertSame('45s,45s,40s', ExercisePerformanceRecord::describeSets([$this->set(null, null, 45), $this->set(null, null, 45), $this->set(null, null, 40)]));
    }

    public function test_incomplete_sets_are_counted_not_listed(): void
    {
        $this->assertSame('10,10 @ 40 kg (+1 not completed)', ExercisePerformanceRecord::describeSets([$this->set(10, 40), $this->set(10, 40), $this->set(4, 40, null, false)]));
        $this->assertSame('no sets completed', ExercisePerformanceRecord::describeSets([$this->set(4, 40, null, false)]));
        $this->assertSame('', ExercisePerformanceRecord::describeSets([]));
    }

    public function test_planned_targets_are_described_compactly(): void
    {
        $plan = fn (?int $s, ?int $r, ?float $w, ?int $d) => ExercisePerformanceRecord::describePlanned(['sets' => $s, 'reps' => $r, 'weight_kg' => $w, 'duration_seconds' => $d]);

        $this->assertSame('3×10 @ 40 kg', $plan(3, 10, 40.0, null));
        $this->assertSame('3×12 @ 22.5 kg', $plan(3, 12, 22.5, null));
        $this->assertSame('3×10', $plan(3, 10, null, null));
        $this->assertSame('3×60s', $plan(3, null, null, 60));
        $this->assertSame('1200s', $plan(null, null, null, 1200));
        $this->assertNull($plan(null, null, null, null));
    }
}
