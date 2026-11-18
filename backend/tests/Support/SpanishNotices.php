<?php

namespace Tests\Support;

/**
 * The Spanish wording of the application notices that the English suites assert on, so a suite re-run for a user who
 * chose Spanish (see UsesSpanishLocale) checks the SAME outcome against the Spanish sentence: same plans written, same
 * notices given, same questions asked, only the words differ. A needle that is not listed is checked as written, so a
 * language-neutral assertion (a date, an exercise name, a number) still means exactly what it said.
 */
final class SpanishNotices
{
    /** @var array<string, string> */
    private const NEEDLES = [
        // the weekly-plan API's refusal, said to a Spanish user (the load guard's wording, localized at the API boundary)
        'would raise its load' => 'superaría',
        'approves a calculated suggestion' => 'apruebas una sugerencia calculada',
        // questions and refusals around a save
        "I haven't changed your plan: the workout was not saved" => 'No he cambiado tu plan: el entrenamiento no se guardó',
        "I haven't changed your plan: nothing was saved this time" => 'No he cambiado tu plan: esta vez no se guardó nada',
        "I haven't changed your plan: I don't see a workout in my last reply or in your message to add" => 'No he cambiado tu plan: no veo un entrenamiento en mi última respuesta ni en tu mensaje para añadir',
        "I don't see a workout in my last reply or in your message to add" => 'no veo un entrenamiento en mi última respuesta ni en tu mensaje para añadir',
        "I don't see a workout" => 'no veo un entrenamiento',
        'the workout was not saved' => 'el entrenamiento no se guardó',
        'say "add this workout to my dashboard"' => 'escribe "add this workout to my dashboard"',
        'only add a workout to today or a later day' => 'solo puedo añadir un entrenamiento a hoy o a un día posterior',
        'Which day should I use?' => '¿Qué día debo usar?',
        'more than 15 minutes old' => 'tiene más de 15 minutos',
        '"Romanian Deadlift" or "Goblet Squat" (Saturday: Legs)' => '"Romanian Deadlift" o "Goblet Squat" (Saturday: Legs)',
        '"Romanian Deadlift" or "Goblet Squat"' => '"Romanian Deadlift" o "Goblet Squat"',
        // "I haven't changed your plan" also opens "I haven't changed your plan yet" ("Todavía no he cambiado tu plan")
        "I haven't changed your plan" => 'o he cambiado tu plan',
        'that is not a limit of the app' => 'eso no es un límite de la aplicación',
        'nothing waiting for your confirmation' => 'nada esperando tu confirmación',
        // a held replacement and its answer
        'Reply "yes"' => 'Responde "yes"',
        'Do you want me to replace it' => '¿Quieres que lo reemplace',
        'already has a rest day' => 'ya tiene un día de descanso',
        "I haven't replaced it" => 'No lo reemplacé',
        'Done. I replaced “Old plan” (Squats 3×10)' => 'Listo. Reemplacé “Old plan” (Squats 3×10)',
        'already has recorded workout performance' => 'ya tiene un entrenamiento realizado y registrado',
        'recorded workout performance' => 'entrenamiento realizado y registrado',
        'changed after I asked' => 'cambió después de que te pregunté',
        'no longer there' => 'ya no está',
        'no longer valid' => 'ya no es válida',
        'none of the days were saved' => 'no se guardó ningún día',
        // a plan of several days
        'Done. I added this to your plan, all together, and checked that it is saved:' => 'Listo. Añadí esto a tu plan, todo junto, y comprobé que quedó guardado:',
        "I didn't save any day of it" => 'no guardé ningún día',
        'Nothing was saved for any day.' => 'No se guardó nada de ningún día.',
        "I don't want to guess or invent a workout" => 'no quiero adivinar ni inventar un entrenamiento',
        'cut off or unfinished' => 'cortado o incompleto',
        'already passed' => 'ya pasaron',
        "can't tell which dates" => 'no puedo saber para qué fechas',
        'already started' => 'ya empezó',
        'I left out Sunday: Active Recovery' => 'Dejé fuera Sunday: Active Recovery',
        'Sunday, Nov 1: rest day' => 'domingo, 1 de noviembre: día de descanso',
        'I saved the low end of every range' => 'guardé el extremo inferior de cada rango',
        // notes
        'I left the starting weight for Goblet Squat, Cable Row unset' => 'Dejé sin definir el peso inicial de Goblet Squat, Cable Row',
        'I left the starting weight for Goblet Squat unset' => 'Dejé sin definir el peso inicial de Goblet Squat',
        'left the starting weight for Goblet Squats unset' => 'Dejé sin definir el peso inicial de Goblet Squats',
        'starting weight for Goblet Squat' => 'peso inicial de Goblet Squat',
        'starting weight for Squat' => 'peso inicial de Squat',
        'maximum, not a recommendation' => 'un máximo, no una recomendación',
        "couldn't make the change exactly as you confirmed it" => 'No pude hacer el cambio exactamente como lo confirmaste',
        'may have expired' => 'puede que haya vencido',
        'Choose a weight you can lift comfortably' => 'Elige un peso que puedas levantar con comodidad',
        'explicit request' => 'solicitud explícita',
        'send an explicit request' => 'envía una solicitud explícita',
        'Reply "yes" within 15 minutes' => 'Responde "yes" en los próximos 15 minutos',
        'Reply with just the day' => 'Responde solo con el día',
        'Reply with just the name' => 'Responde solo con el nombre',
        'Saturday, Oct 31' => 'sábado, 31 de octubre',
        'Sunday, Nov 1' => 'domingo, 1 de noviembre',
        'does not mean the load suits you' => 'no significa que la carga sea adecuada para ti',
        'nothing was saved for any day' => 'no se guardó nada de ningún día',
        'I checked that it is saved' => 'Comprobé que quedó guardado',
        'Goblet Squats 10 reps (written 10-12)' => 'Goblet Squats 10 repeticiones (escrito 10-12)',
    ];

    /** @var array<string, string> what a notice BEGINS with (a start is not a substring: "No he" is not "o he") */
    private const PREFIXES = [
        'Done.' => 'Listo.',
        "I haven't changed your plan yet." => 'Todavía no he cambiado tu plan.',
        "I haven't changed your plan yet" => 'Todavía no he cambiado tu plan',
        "I haven't changed your plan" => 'No he cambiado tu plan',
    ];

    /** @var array<string, string> needles that carry a date (the suites run on whatever day it is): pattern => replacement */
    private const DATED = [
        '/^(\d{4}-\d{2}-\d{2}) already has$/' => 'El $1 ya tiene',
        '/^As you asked, I replaced (.+) on (\d{4}-\d{2}-\d{2})$/su' => 'Como pediste, reemplacé $1 el $2',
        '/^a plan appeared on (\d{4}-\d{2}-\d{2})$/' => 'apareció un plan el $1',
        '/^set (.+) to ([\d.]+) kg on (\d{4}-\d{2}-\d{2})$/su' => 'establezca $1 en $2 kg el $3',
    ];

    public static function needle(string $english): string
    {
        if (isset(self::NEEDLES[$english])) {
            return self::NEEDLES[$english];
        }

        foreach (self::DATED as $pattern => $replacement) {
            if (preg_match($pattern, $english) === 1) {
                return (string) preg_replace($pattern, $replacement, $english);
            }
        }

        return $english;
    }

    public static function prefix(string $english): string
    {
        return self::PREFIXES[$english] ?? self::needle($english);
    }
}
