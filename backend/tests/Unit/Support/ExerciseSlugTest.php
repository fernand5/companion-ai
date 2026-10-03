<?php

namespace Tests\Unit\Support;

use App\Support\ExerciseSlug;
use PHPUnit\Framework\TestCase;

class ExerciseSlugTest extends TestCase
{
    public function test_case_spacing_and_punctuation_variants_share_one_slug(): void
    {
        $this->assertSame('goblet-squat', ExerciseSlug::from('Goblet Squat'));
        $this->assertSame('goblet-squat', ExerciseSlug::from('  goblet   SQUAT '));
        $this->assertSame('bent-over-row', ExerciseSlug::from('Bent-Over Row'));
    }

    public function test_plurals_are_not_folded_together(): void
    {
        $this->assertNotSame(ExerciseSlug::from('Goblet Squat'), ExerciseSlug::from('Goblet Squats'));
    }

    public function test_accented_names_are_transliterated(): void
    {
        $this->assertSame('sentadilla-bulgara', ExerciseSlug::from('Sentadilla búlgara'));
    }

    public function test_a_name_with_no_usable_characters_has_no_slug(): void
    {
        $this->assertNull(ExerciseSlug::from('???'));
        $this->assertNull(ExerciseSlug::from('   '));
        $this->assertNull(ExerciseSlug::from(null));
    }

    public function test_slug_is_capped_and_never_ends_in_a_hyphen(): void
    {
        $slug = ExerciseSlug::from(str_repeat('word ', 60));

        $this->assertLessThanOrEqual(ExerciseSlug::MAX_LENGTH, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }
}
