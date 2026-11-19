<?php

namespace App\Services\Ai;

use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use App\Services\Ai\Authorization\SpanishSaveIntent;

/**
 * Hotfix: the application's own words about saving ONE workout, in Spanish, for a user who asked in Spanish. They are
 * the Spanish counterparts of the English sentences in AiCoachService and say exactly the same thing: what was and
 * was not changed, and what to write next (always a phrase the application understands). Presentation only: which of
 * them is shown never changes what is written, and the language is chosen from the message being answered.
 */
final class SpanishCoachMessages
{
    private const EXAMPLE = 'agrega este entrenamiento a mi dashboard: sentadillas 3x10, flexiones 3x12';

    public static function noWorkoutToSave(): string
    {
        return 'No he cambiado tu plan: no veo un entrenamiento en mi último mensaje ni en el tuyo para agregar. Pídeme uno (por ejemplo "dame un entrenamiento de 30 minutos") o dime cuál agregar, como "'.self::EXAMPLE.'". También puedes responder solo con los ejercicios y sus series y repeticiones.';
    }

    public static function whichWorkout(): string
    {
        return 'No he cambiado tu plan: podrían ser varios entrenamientos y no quiero adivinar cuál. Dime cuál (por ejemplo "agrega la opción 2 a mi dashboard", o solo "opción 2"), o envía los ejercicios en un solo mensaje, como "'.self::EXAMPLE.'".';
    }

    public static function incompleteWorkout(): string
    {
        return 'No he cambiado tu plan: ese entrenamiento parece incompleto (está cortado, termina en puntos suspensivos o lista menos ejercicios de los que anuncia). Envíame la lista completa, como "'.self::EXAMPLE.'", o pídeme que lo escriba completo primero.';
    }

    public static function quotedWorkout(): string
    {
        return 'No he cambiado tu plan: ese entrenamiento está citado de palabras de otra persona y quiero estar seguro de que es el que quieres. Escribe los ejercicios en tu propio mensaje, como "'.self::EXAMPLE.'".';
    }

    public static function whichDay(bool $past): string
    {
        return $past
            ? 'No he cambiado tu plan: no estoy seguro de qué día quieres, porque solo puedo agregar un entrenamiento a hoy o a un día posterior (hasta dos meses). ¿Qué día debo usar? Responde solo con el día, por ejemplo "viernes" o "mañana".'
            : 'No he cambiado tu plan: no estoy seguro de qué día quieres. Puedo agregar un entrenamiento a hoy o a un día de los próximos dos meses. ¿Qué día debo usar? Responde solo con el día, por ejemplo "viernes" o "mañana".';
    }

    public static function retryExpired(string $reason, ?string $madeOn): string
    {
        $how = 'Para agregar el entrenamiento, escribe "agrega este entrenamiento a mi dashboard" (y el día, si quieres uno distinto de hoy).';

        return $reason === 'new_day'
            ? 'No he cambiado tu plan: esa petición de agregar un entrenamiento se hizo el '.($madeOn ?? 'un día anterior').' y ya empezó un nuevo día, así que no la retomo ni adivino qué día quieres ahora. '.$how
            : 'No he cambiado tu plan: esa petición de agregar un entrenamiento tiene más de '.PlanReplacementProposal::TTL_MINUTES.' minutos y solo retomo una petición mientras es reciente, así que no se guardó nada. '.$how;
    }

    /** @param array<int, array{item: string, options: array<int, string>}> $choices */
    public static function exerciseChoice(array $choices): string
    {
        $lines = array_map(fn (array $c) => '"'.implode('" o "', $c['options']).'"', $choices);
        $first = $choices[0]['options'][0] ?? 'el primero';

        return 'No he cambiado tu plan, y nada impide guardarlo: una cosa del entrenamiento es una elección. '
            .(count($lines) === 1 ? 'Dice '.$lines[0].'.' : 'Dice '.implode('; ', $lines).'.')
            .' ¿Cuál debo agregar? Responde solo con el nombre'.(count($lines) === 1 ? '' : 's').', por ejemplo "'.$first.'".';
    }

    public static function notSaved(string $date, bool $performed): string
    {
        return $performed
            ? "No he cambiado tu plan: el {$date} ya tiene un entrenamiento registrado, así que su plan no se puede reemplazar. Elige otro día, por ejemplo \"agrega este entrenamiento a mi dashboard para mañana\"."
            : 'No he cambiado tu plan: el entrenamiento no se guardó, diga lo que diga el mensaje de arriba. Pídemelo de nuevo con "agrega este entrenamiento a mi dashboard", o envía los ejercicios en un solo mensaje.';
    }

    public static function replaced(string $was, string $date): string
    {
        return "Como pediste, reemplacé {$was} del {$date} con este entrenamiento.";
    }

    public static function replacementQuestion(PlanReplacementProposal $proposal): string
    {
        $minutes = PlanReplacementProposal::TTL_MINUTES;
        $changes = $proposal->changes;

        if (count($changes) === 1) {
            $change = $changes[0];

            return $change['action'] === PlanReplacementProposal::CLEAR
                ? "No he cambiado tu plan. El {$change['date']} ya tiene {$change['existing_summary']}. ¿Quieres que lo convierta en día de descanso? Responde \"sí\" en los próximos {$minutes} minutos para hacer exactamente eso; cualquier otra respuesta lo deja como está."
                : "No he cambiado tu plan. El {$change['date']} ya tiene {$change['existing_summary']}. ¿Quieres que lo reemplace por ".PlanReplacementProposal::summarise($change['replacement'])."? Responde \"sí\" en los próximos {$minutes} minutos para reemplazarlo exactamente por eso; cualquier otra respuesta lo deja como está.";
        }

        return "No he cambiado tu plan todavía. Guardar esto haría estos cambios:\n"
            .implode("\n", array_map(fn (array $c) => '- '.PlanReplacementProposal::describeChange($c), $changes))
            ."\n¿Quieres que haga todos estos cambios? Responde \"sí\" en los próximos {$minutes} minutos para hacer exactamente estos cambios y nada más; cualquier otra respuesta deja tu plan como está.";
    }

    /** The answer to a bare "si" (no accent): the pending question stays open, unchanged, until its own expiry. */
    public static function confirmationUnclear(): string
    {
        return 'No he cambiado tu plan. No estoy seguro de si tu "si" (sin tilde) es un sí o un "si" condicional. Responde "sí" (con tilde) para hacer el reemplazo que te mostré, o "no" para dejarlo como está. La pregunta sigue abierta hasta la misma hora de antes; no se ha ampliado el plazo.';
    }

    public static function confirmationInvalid(): string
    {
        return 'No he cambiado tu plan: esa confirmación ya no es válida (venció, ya se usó o no es de esta conversación). Si todavía quieres el entrenamiento en tu plan, pídemelo de nuevo.';
    }

    public static function replacementRefused(string $reason): string
    {
        return "No lo reemplacé: {$reason} No se cambió nada. Si todavía quieres el entrenamiento en tu plan, pídemelo de nuevo.";
    }

    /** @param array<int, array<string, mixed>> $changes */
    public static function replacementApplied(array $changes): string
    {
        return 'Listo. '.implode(' ', array_map(
            fn (array $c) => match (true) {
                $c['action'] === PlanReplacementProposal::CREATE => 'Agregué '.PlanReplacementProposal::summarise($c['replacement'])." al {$c['date']}.",
                $c['action'] === PlanReplacementProposal::CLEAR && $c['existing_plan_id'] === 0 => "Marqué el {$c['date']} como día de descanso.",
                $c['action'] === PlanReplacementProposal::CLEAR => "Convertí el {$c['date']} ({$c['existing_summary']}) en día de descanso.",
                default => "Reemplacé {$c['existing_summary']} del {$c['date']} con ".PlanReplacementProposal::summarise($c['replacement']).'.',
            },
            $changes,
        )).' Comprobé que quedó guardado.';
    }

    public static function proposalNoLongerValid(): string
    {
        return 'Tu plan cambió desde que te pregunté, así que no hice ese cambio. Para agregar un entrenamiento, escribe "agrega este entrenamiento a mi dashboard".';
    }

    public static function nothingPending(bool $workoutDiscussed): string
    {
        return 'No hay nada esperando tu confirmación (puede que haya vencido). '
            .($workoutDiscussed ? 'Para poner en tu plan el entrenamiento del que hablamos, escribe "agrega este entrenamiento a mi dashboard". ' : '')
            .'Para cambiar tu plan, envía una petición explícita, por ejemplo "agrega este entrenamiento a mi dashboard".';
    }

    /** What was stored as one number when the workout gave a range, in Spanish; null when nothing was a range. */
    public static function rangeNote(SaveWorkoutScope $scope, array $arguments): ?string
    {
        $parts = $scope->rangeParts($arguments, spanish: true);

        return $parts === [] ? null : 'Tu plan guarda un solo número en cada uno, así que guardé el extremo inferior de cada rango: '.implode('; ', $parts).'. Puedes cambiarlos en el plan.';
    }

    /** The application's answer to "why didn't you save it?" when nothing has been saved in the conversation. */
    public static function whyNotSaved(): string
    {
        return 'No se ha guardado ningún entrenamiento en tu plan en esta conversación: la aplicación no registró ninguna escritura, y no voy a decirte que se guardó. Para guardar el entrenamiento que te acabo de mostrar, escribe "agrega este entrenamiento a mi dashboard" (y el día, si quieres uno distinto de hoy). Si hay algo que lo impida —un entrenamiento que no pueda leer completo, un día que ya tiene uno, o un día poco claro—, te diré exactamente qué es.';
    }

    public static function correctionNoAccess(bool $wrote): string
    {
        return "\n\nPara que quede claro, eso no es un límite de la aplicación: puedo agregar y cambiar entrenamientos en tu plan cuando me lo pidas. "
            .(! $wrote ? 'Esta vez no he cambiado nada. ' : '')
            .'Para agregar un entrenamiento que acabo de mostrarte, escribe "agrega este entrenamiento a mi dashboard" (o "inténtalo de nuevo" si ya lo pediste).';
    }

    public static function correctionClaimedWritten(): string
    {
        return "\n\nNo he cambiado tu plan: esta vez no se guardó nada, diga lo que diga el mensaje de arriba. Para agregar un entrenamiento que acabo de mostrarte, escribe \"agrega este entrenamiento a mi dashboard\".";
    }

    /** A model's sentence in Spanish that says it has no access / cannot write to the plan. */
    public static function claimsNoAccess(string $text): bool
    {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
            $s = SpanishSaveIntent::fold($sentence);

            if (preg_match('/\b(?:seguro|seguridad|lesion|medico|recomiend\w*|deberias?)\b/u', $s)) {
                continue;
            }

            if (preg_match('/\b(?:no tengo acceso|no tengo (?:la )?(?:capacidad|posibilidad)|no puedo (?:actualizar|agregar|anadir|guardar|modificar|editar|cambiar|enviar)|no me es posible|no estoy en capacidad|no esta disponible|no estan disponibles|no soy capaz|no hay forma de)\b/u', $s)
                && preg_match('/\b(?:plan|dashboard|panel|calendario|agenda|herramienta|aplicacion)\b/u', $s)) {
                return true;
            }
        }

        return false;
    }

    /** A model's sentence in Spanish that says the plan was written ("ya lo agregué a tu dashboard"). */
    public static function claimsPlanWritten(string $text): bool
    {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
            $s = SpanishSaveIntent::fold($sentence);

            if (str_ends_with(trim($sentence), '?') || preg_match('/\b(?:no|nunca|pero|podria|podrias|puedo|puedes|quieres|quiero|cuando|debes)\b/u', $s) || preg_match('/\bsi (?:quieres|deseas|prefieres|te parece|gustas|necesitas)\b/u', $s)) {
                continue;
            }

            if (preg_match('/\b(?:ya (?:lo |la |los |las )?(?:he )?(?:agregu?e|agregado|anadi|anadido|guarde|guardado|programe|programado|actualice|actualizado|reemplace|reemplazado|cree|creado|puse|puesto|envie|enviado)|(?:he|hemos) (?:agregado|anadido|guardado|programado|actualizado|reemplazado|creado|puesto)|(?:lo|la) (?:agregue|anadi|guarde|programe|actualice|puse)|se (?:ha )?(?:agregado|anadido|guardado|programado|actualizado)|listo)\b[^.\n]{0,60}\b(?:dashboard|plan|panel|calendario|agenda)\b/u', $s)) {
                return true;
            }
        }

        return false;
    }
}
