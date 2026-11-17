<?php

namespace App\Services\Ai\Notices;

/**
 * The phrases the application tells the user to TYPE, exactly as the request parsers understand them today.
 *
 * The parsers (what counts as "add this workout to my dashboard", as a bare "yes", as a day such as "Friday") only
 * know English until Phase 7 / M4. So a Spanish message must still tell the user the English phrase, and say so: a
 * Spanish instruction the parser cannot read would send the user into a refusal.
 *
 * This is the ONE place those phrases live. Every message that names one takes it from here by placeholder
 * (":save_workout"), never by a literal in a sentence, and every Spanish message that names one also carries
 * ":english_note" (empty in English). When M4 teaches the parsers Spanish: give the phrases a Spanish form per locale
 * here and empty `english_note` in lang/es/coach.php. Nothing else in the notices has to change.
 *
 * A phrase is a presentation string only. Nothing reads one back: what the user types is parsed by the classifiers,
 * which are not touched by the wording of any message.
 */
final class EnglishCommands
{
    /** @var array<string, string> placeholder => the exact phrase the English parsers accept */
    private const PHRASES = [
        'save_workout' => 'add this workout to my dashboard',
        'save_workout_tomorrow' => 'add this workout to my dashboard for tomorrow',
        'save_plan' => 'add this plan to my dashboard',
        'save_plan_sunday' => 'add this plan to my dashboard for Sunday',
        'example' => 'add this workout to my dashboard: squats 3x10, push-ups 3x12',
        'ask_workout' => 'give me a 30-minute workout',
        'option_command' => 'add option 2 to my dashboard',
        'option' => 'option 2',
        'friday' => 'Friday',
        'tomorrow' => 'tomorrow',
        'sunday' => 'Sunday',
        'sunday_rest' => 'Sunday is a rest day',
        'saturday_sunday' => 'Saturday and Sunday',
        'dated_day' => 'Saturday, Oct 31',
        'yes' => 'yes',
        'try_again' => 'try again',
        'update_example' => 'update my bench press to 45 kg on 2026-10-17',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::PHRASES;
    }

    public static function phrase(string $name): string
    {
        return self::PHRASES[$name];
    }
}
