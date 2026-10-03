<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The identity of an exercise for image purposes. Exercises only exist as
 * free-text names (there is no catalog), so two rows share an image exactly
 * when their names normalize to the same slug. Plurals and synonyms are
 * deliberately NOT folded together — that would risk merging different
 * exercises; the cost of the alternative is at worst one extra image.
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
