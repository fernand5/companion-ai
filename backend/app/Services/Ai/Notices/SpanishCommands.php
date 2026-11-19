<?php

namespace App\Services\Ai\Notices;

/**
 * The same phrases as EnglishCommands, in Spanish: what a Spanish message tells the user to TYPE. Each one is read by
 * the Spanish side of the request parsers (see SpanishIntent, Confirmation), which a test proves for every phrase, so
 * a message never tells the user to type something the application cannot read.
 *
 * A phrase is a presentation string only. Nothing reads one back: what the user types is parsed by the classifiers,
 * which are not touched by the wording of any message. English phrases keep working for a Spanish user (and the other
 * way round): the parsers read both languages for everyone.
 */
final class SpanishCommands
{
    /** @var array<string, string> placeholder => a phrase the parsers accept */
    private const PHRASES = [
        'save_workout' => 'agrega este entrenamiento a mi plan',
        'save_workout_tomorrow' => 'agrega este entrenamiento a mi plan para mañana',
        'save_plan' => 'agrega este plan a mi calendario',
        'save_plan_sunday' => 'agrega este plan a mi calendario para el domingo',
        'example' => 'agrega este entrenamiento a mi plan: sentadillas 3x10, flexiones 3x12',
        'ask_workout' => 'dame un entrenamiento de 30 minutos',
        'option_command' => 'agrega la opción 2 a mi plan',
        'option' => 'opción 2',
        'friday' => 'viernes',
        'tomorrow' => 'mañana',
        'sunday' => 'domingo',
        'sunday_rest' => 'el domingo es día de descanso',
        'saturday_sunday' => 'sábado y domingo',
        'dated_day' => 'sábado 31 de octubre',
        'yes' => 'sí',
        'try_again' => 'inténtalo de nuevo',
        'update_example' => 'actualiza mi plan: press de banca a 45 kg el 2026-10-17',
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
