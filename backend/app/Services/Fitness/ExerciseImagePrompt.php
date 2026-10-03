<?php

namespace App\Services\Fitness;

/**
 * The image prompt is a fixed template; the exercise name is the only variable
 * part. Names come from AI output or user input, so they are reduced to a safe
 * character set and length before being inserted — a name can never add
 * instructions of its own.
 */
class ExerciseImagePrompt
{
    public const MAX_NAME_LENGTH = 80;

    public static function for(string $exerciseName): string
    {
        $name = self::sanitize($exerciseName);

        return 'Create a realistic fitness demonstration image of a single person performing the exercise "'.$name.'". '
            .'Show the complete body with correct, safe exercise form, using the equipment normally used for this exercise, '
            .'from a side or front three-quarter angle, in a clean, simple modern gym or studio with an uncluttered background. '
            .'Neutral fitness clothing. Depict only this one exercise and only one person. '
            .'No text, captions, numbers, logos, watermarks or branding. Natural, anatomically correct proportions.';
    }

    public static function sanitize(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N} \-\'\/()&+.,]/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
