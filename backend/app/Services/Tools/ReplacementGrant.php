<?php

namespace App\Services\Tools;

/**
 * How "the user authorized replacing this day's plan" travels from ToolTurn (the one place a chat tool call is
 * authorized) to the tool that writes. ToolTurn removes whatever the model put under this key and then sets it itself
 * from its own decision, so a model cannot grant itself a replacement; a tool only reads it. Nothing else sets it.
 * The write itself is then checked again by WorkoutPlanService, which refuses to replace without it.
 */
final class ReplacementGrant
{
    public const KEY = '_replacement_authorized';

    /** Whatever the model sent under the key is discarded. */
    public static function strip(array $arguments): array
    {
        unset($arguments[self::KEY]);

        return $arguments;
    }

    /**
     * @param  true|array<int, string>  $dates  true = any day (a deliberate user-invoked endpoint), or the exact days
     */
    public static function grant(array $arguments, true|array $dates): array
    {
        $arguments[self::KEY] = $dates;

        return $arguments;
    }

    public static function permits(array $arguments, string $date): bool
    {
        $granted = $arguments[self::KEY] ?? false;

        return $granted === true || (is_array($granted) && in_array($date, $granted, true));
    }

    /** @return true|array<int, string> */
    public static function dates(array $arguments): true|array
    {
        $granted = $arguments[self::KEY] ?? [];

        return $granted === true ? true : (is_array($granted) ? array_values(array_filter($granted, 'is_string')) : []);
    }
}
