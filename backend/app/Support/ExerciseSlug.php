<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The identity of an exercise for image purposes. Exercises only exist as
 * free-text names (there is no catalog), so two rows share an image exactly
 * when their names normalize to the same slug. Plurals and synonyms are
 * deliberately NOT folded together — that would risk merging different
 * exercises; the cost of the alternative is at worst one extra image.
 *
 * The same identity drives performance history, and the same policy applies:
 * matching is EXACT. "Dumbbell Row"/"Dumbbell Rows" and "Lunges"/"Dumbbell
 * Lunges" are separate exercises with separate histories. No pluralization,
 * prefix or fuzzy folding, and no alias table (there is no catalog to hang one
 * on): a false merge would make the coach compare two different movements,
 * while a missed merge only means less history. Explicit aliases are future
 * work and would need a catalog.
 */
class ExerciseSlug
{
    public const MAX_LENGTH = 100;

    public static function from(?string $name): ?string
    {
        $slug = Str::limit(Str::slug(trim((string) $name)), self::MAX_LENGTH, '');
        $slug = rtrim($slug, '-');

        return $slug === '' ? null : $slug;
    }
}
