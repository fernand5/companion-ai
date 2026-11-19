<?php

namespace App\Services\Ai\Authorization;

/**
 * The words the deterministic interpreters share across English and Spanish. Language is a way of WRITING a request,
 * never a permission: every interpreter reads both languages for every user, whatever language their account is in,
 * and a message in either grants exactly what the same message in the other would.
 *
 * Spanish is matched on FOLDED text (lower case, accents and ¿¡ removed), so "miércoles", "miercoles" and "MIÉRCOLES"
 * are one word. Patterns for Spanish are therefore written without accents. Folding is used for MATCHING only: a name
 * the user or the model wrote (an exercise, a title) is never altered, and is stored exactly as it was written.
 */
final class Lexicon
{
    /** ISO weekday (1 = Monday) by name, in Spanish, folded. */
    public const WEEKDAYS_ES = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    /** Month number by name, in Spanish, folded. Abbreviations are not read: "mar" is both Tuesday and March. */
    public const MONTHS_ES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    /** Spanish plan nouns, folded: what a request may be about. */
    public const PLAN_NOUN_ES = '(?:plan(?:es)?|entrenamientos?|rutinas?|sesion(?:es)?|programas?|semana|dia|ejercicios?|objetivos?|pesos?|cargas?|repeticiones|series|levantamientos?)';

    /** Spanish nouns for one workout, folded. */
    public const WORKOUT_NOUN_ES = '(?:entrenamientos?|sesion(?:es)?|rutinas?|carreras?|caminatas?|trotes?|circuitos?|ejercicios?|programas?)';

    /** Lower case with accents, ñ and the opening ¿ ¡ marks removed: for matching, never for storing. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(str_replace(['’', '‘', '`'], "'", $text));

        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', '¿' => '', '¡' => '',
        ]);
    }

    /**
     * Like fold(), but every character maps to exactly one character, so a position found in the folded text is the
     * same position in the original. The opening ¿ ¡ marks stay.
     */
    public static function foldKeepingLength(string $text): string
    {
        return strtr(mb_strtolower(str_replace(['’', '‘', '`'], "'", $text)), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n',
        ]);
    }

    /** The Spanish weekday words as a regex alternation (folded). */
    public static function weekdaysEs(): string
    {
        return implode('|', array_keys(self::WEEKDAYS_ES));
    }

    /** The Spanish month words as a regex alternation (folded). */
    public static function monthsEs(): string
    {
        return implode('|', array_keys(self::MONTHS_ES));
    }

    /**
     * "mañana" is tomorrow, unless it is the morning ("esta mañana", "por la mañana", "de la mañana"). The accent is
     * not relied on (it is often left out), so the words before it decide. "pasado mañana" is two days ahead and is
     * read as that, never as tomorrow. $text is folded.
     */
    public const TOMORROW_ES = '(?<!pasado )(?<!la )(?<!esta )(?<!cada )\bmanana\b';
}
