<?php

namespace App\Services\Ai\Authorization;

/**
 * The Spanish counterpart of the phrase readers in PlanChangeIntentClassifier. Same rules, same limits, same answer when
 * unsure: it reads a request only when it is clearly one, and anything else grants nothing. Each method takes ONE
 * sentence of already-folded text (Lexicon::fold) and answers the same question its English twin does, so a Spanish
 * message and its English equivalent are granted exactly the same thing.
 *
 * Lexical on purpose, like the English reader: keyword matching, not understanding. Not recognised means not granted.
 */
final class SpanishIntent
{
    private const DET = '(?:mi|mis|el|la|los|las|este|esta|estos|estas|ese|esa|esos|esas|tu|tus|nuestro|nuestra|un|una|del|proximo|proxima|actual)';

    private const PLAN_NOUN = Lexicon::PLAN_NOUN_ES;

    /**
     * What a request to save may point at: a workout noun, "plan", and the common ways "entrenamiento" is mistyped,
     * "entretenimiento" (entertainment) included. Read only inside a sentence that is already a request to put something
     * on the plan; the application still has to find exactly one workout in the conversation, or it asks (an entertainment
     * conversation holds none).
     */
    private const SAVE_NOUN = '(?:entrenamientos?|entrenamento|entramiento|entrenamietno|entrenameinto|entrenamientto|entretenimientos?|sesion(?:es)?|rutinas?|plan(?:es)?|carreras?|caminatas?|trotes?|circuitos?|ejercicios?|programas?)';

    private const WORKOUT_NOUN = Lexicon::WORKOUT_NOUN_ES;

    /** Where a workout goes: the dashboard, the plan, the calendar, the week, a day. */
    private const DESTINATION = '(?:a|al|en|para|como|sobre|del?|hacia|dentro de)\s+(?:(?:mi|mis|el|la|tu|tus|nuestro|nuestra|este|esta|los|las)\s+)?(?:[\w\'-]+\s+){0,2}(?:dashboard|panel|tablero|plan|planificador|calendario|agenda|horario|semana|semanal|hoy|esta noche|manana|pasado manana|ayer|\d{4}-\d{2}-\d{2}|entrenamientos?|lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b';

    private const SAVE_VERB = '(?:guard(?:a|ar|alo|ala|alos|alas|e|elo|ela)|agreg(?:a|ar|alo|ala|alos|alas|ue|uelo|uela)|anad(?:e|ir|elo|ela|elos|elas|a|ela)|pon(?:er|lo|la|los|las|)|met(?:e|er|elo|ela)|envi(?:a|ar|alo|ala)|programa(?:r|lo|la)?|agenda(?:r|lo|la)?|incluy(?:e|elo|ela)|fij(?:a|ar|alo|ala)|aplic(?:a|ar|alo|ala)|us(?:a|ar|alo|ala))';

    /** "este entrenamiento", "esa rutina", "esto", "lo", "la opción 2", "la primera", "ambas opciones". */
    private const ANAPHOR = '(?:(?:este|esta|estos|estas|ese|esa|esos|esas|el|la|los|las|tu|mi)\s+(?:[\w\'-]+\s+){0,1}'.self::SAVE_NOUN.'(?:\s+(?:de arriba|que (?:me )?(?:diste|escribiste|sugeriste|describiste|enviaste)))?|(?:esto|eso|lo|la|los|las|(?:este|esta|ese|esa|estos|estas|esos|esas)(?=\s+(?:a|al|en|para|como|sobre|dentro de)\b))'
        .'|(?:opcion|plan|entrenamiento|rutina|sesion|version|alternativa|eleccion)\s*(?:#|no\.?|numero)?\s*(?:\d{1,2}|[a-d])'
        .'|(?:(?:el|la)\s+)?(?:primer[oa]?|segund[oa]|tercer[oa]?|ultim[oa]|mejor|otr[oa]|mas facil|mas dificil|mas corto|mas largo|mas ligero|mas pesado)\s+(?:opcion|entrenamiento|rutina|sesion|plan|version|eleccion)'
        .'|(?:ambas|ambos|todas|todos|cualquiera)(?:\s+de)?\s+(?:(?:las|los|estas|estos)\s+)?(?:opciones|entrenamientos|rutinas|sesiones|versiones|planes))';

    /** Words for a verb that changes the plan, never "add/save": those are requests to put a workout on it. */
    private const CHANGE_VERB = '(?:actualiz\w*|cambi\w*|edit\w*|modific\w*|ajust\w*|reemplaz\w*|sustitu\w*|reescrib\w*|rehaz\w*|rehac\w*|regener\w*|reinici\w*|cre(?:a|ar|alo|ala)\b|constru\w*|escrib\w*|gener\w*|armar?\b|arma\b|haz\b|hacer\b|planific\w*)';

    /** "no cambies mi plan", "sin modificar nada", "ya no actualices": an instruction NOT to do it. */
    public static function negated(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:no|nunca|jamas|sin|ni|deja de|evita|no hace falta|no necesito|no quiero|no hay que|no es necesario|ya no)\b[^.?!;]{0,30}\b(?:cambi\w*|actualiz\w*|edit\w*|modific\w*|ajust\w*|reemplaz\w*|sustitu\w*|alter\w*|toc\w*|reescrib\w*|cre(?:es|ar|ar)\b|guard\w*|agreg\w*|anad\w*|pon(?:gas|er)\b|haz\b|hag\w*|mod\w*)/u',
            $text,
        ) || (bool) preg_match('/\b(?:solo (?:estoy )?(?:preguntando|revisando|curioseando)|no te (?:estoy )?pido)\b/u', $text);
    }

    public static function isQuestion(string $text): bool
    {
        return str_contains($text, '?')
            || (bool) preg_match('/^(?:debo|deberia|es|son|estoy|puedo|podria|voy a|seria|por que|como|que|cual|cuales|cuando|donde|quien|quienes|cuanto|cuantos|hay|tengo que)\b/u', $text);
    }

    /** "¿puedes actualizar mi plan?", "¿podrías cambiar el entrenamiento de hoy?": a question in form, a request in fact. */
    public static function politeRequest(string $text): bool
    {
        return (bool) preg_match('/^(?:(?:hola|ok|okay|entonces|por favor|y|oye)[, ]+)*(?:puedes|podrias|podria|podrias por favor|me puedes|me podrias|quieres|serias capaz de)\b/u', $text);
    }

    /** "el mensaje dice: cambia mi plan", "mi entrenador escribió…", "según el artículo…". */
    public static function attributedToSomeoneElse(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:dice|dijo|decia|dicen|escribio|escribe|escribia|me dijo|me dice|nota|notas|mensaje|correo|email|entrenador|entrenadora|doctor|doctora|medico|medica|fisio\w*|amigo|amiga|articulo|publicacion|cita|citado|citada|pegado|copiado|reenviado|segun|indica|indican|instrucciones|recomienda|recomendo|sugiere|sugirio|aconseja|aconsejo|menciona|afirma|lee)\b/u',
            $text,
        );
    }

    /** "actualiza mi plan", "cambia el entrenamiento del viernes", "reemplaza el entrenamiento de mañana". */
    public static function explicitChange(string $text): bool
    {
        $verb = self::CHANGE_VERB;
        $det = self::DET;
        $noun = self::PLAN_NOUN;

        if (preg_match("/\\b{$verb}(?:me|lo|la)?\\s+(?:todo\\s+)?{$det}\\s+(?:[\\w'-]+\\s+){0,2}{$noun}\\b/u", $text)) {
            return true;
        }

        // "planifica mi semana", "programa mis entrenamientos".
        if (preg_match("/\\b(?:planifica|programa|planea)\\s+{$det}\\s+(?:[\\w'-]+\\s+){0,1}(?:semana|dia|entrenamientos?|sesiones?)\\b/u", $text)) {
            return true;
        }

        // "aumenta el peso en mi plan", "baja las repeticiones del próximo entrenamiento".
        return (bool) preg_match("/\\b(?:aument\\w*|sub(?:e|ir)\\b|baj\\w*|reduc\\w*|disminuy\\w*)\\s+{$det}\\s+(?:[\\w'-]+\\s+){0,2}(?:pesos?|cargas?|repeticiones|series|objetivos?)\\b[^.?!]{0,40}\\b(?:en|de|para|a)\\s+{$det}?\\s*(?:[\\w'-]+\\s+){0,2}(?:plan|entrenamiento|sesion|rutina)\\b/u", $text);
    }

    /** "¿qué debo hacer hoy?", "dame un entrenamiento", "sugiere una rutina", "necesito una sesión". */
    public static function sessionRequest(string $text): bool
    {
        if (preg_match('/\b(?:que|cual) (?:debo|puedo|podria|deberia|podemos|toca) (?:hacer|entrenar|levantar|trabajar)\b[^?.!]{0,30}\b(?:hoy|esta noche|manana|ahora|esta (?:manana|tarde|noche))\b/u', $text)) {
            return true;
        }

        return (bool) preg_match('/\b(?:dame|dime|dame un|sugiere|sugiereme|recomienda|recomiendame|prepara|preparame|prescribe|prescribeme|genera|generame|disena|disename|arma|armame)\b(?:\s+(?:me|un|una|algun|alguna|mi|el|la|de))?\s*(?:[\w\'-]+\s+){0,3}(?:entrenamiento|sesion|rutina)\b/u', $text)
            || (bool) preg_match('/\b(?:necesito|quiero|me gustaria|busco|quisiera)\s+(?:un|una|algun|alguna)\s+(?:[\w\'-]+\s+){0,3}(?:entrenamiento|sesion|rutina)\b/u', $text);
    }

    /** "guarda este entrenamiento para mañana", "agrega esta rutina a mi plan", "añade este plan a mi calendario". */
    public static function saveWorkoutRequest(string $text): bool
    {
        $verb = self::SAVE_VERB;
        $anaphor = self::ANAPHOR;
        $dest = self::DESTINATION;
        $noun = self::WORKOUT_NOUN;

        if (preg_match("/\\b{$verb}\\s+{$anaphor}\\b[^.?!]{0,40}?\\b{$dest}/u", $text)) {
            return true;
        }

        // "guárdalo en mi plan": the verb carries the "it".
        if (preg_match('/\b(?:guard|agreg|anad|met|envi|pon)(?:alo|ala|alos|alas|elo|ela|elos|elas|lo|la|los|las)\b[^.?!]{0,40}?\b'.$dest.'/u', $text)) {
            return true;
        }

        // "actualiza mi plan de hoy con esta rutina": the plan updated WITH the routine that is pointed at is saving that routine,
        // the narrower request, never the broad "change my plan" one (which may ask for heavier loads).
        if (preg_match("/\\b(?:actualiz(?:a|ar)|rellen(?:a|ar))\\s+(?:mi|el|la|tu|nuestro|nuestra)\\s+(?:[\\w'-]+\\s+){0,3}(?:plan|entrenamiento|rutina|dia|sesion|calendario|agenda)\\b[^.?!]{0,30}?\\b(?:con|por|usando|utilizando)\\s+(?:este|esta|esto|ese|esa|eso|el (?:entrenamiento|plan|rutina|sesion) (?:de arriba|anterior|que (?:me |te )?(?:acabas de|diste|escribiste|sugeriste|recomendaste|describiste|propusiste)))\\b/u", $text)) {
            return true;
        }

        // "reemplaza el entrenamiento de hoy con este": the replacement said outright.
        if (preg_match("/\\b(?:reemplaz\\w*|sustitu\\w*|sobrescrib\\w*|cambi\\w*)\\s+(?:mi|el|la|tu|nuestro|nuestra)\\s+(?:[\\w'-]+\\s+){0,2}(?:entrenamiento|sesion|rutina|plan|dia)\\b[^.?!]{0,30}?\\b(?:con|por)\\s+(?:este|esta|esto|ese|esa|eso|estos|estas|el (?:entrenamiento|rutina|sesion) de arriba)\\b/u", $text)) {
            return true;
        }

        return (bool) preg_match("/\\bguard(?:a|ar)\\s+(?:este|esta|ese|esa|el|la|estos|estas)\\s+(?:[\\w'-]+\\s+)?{$noun}\\b/u", $text);
    }

    /** "agrega un entrenamiento de 30 minutos a mi plan para el viernes": the user names the workout themselves. */
    public static function addWorkoutRequest(string $text): bool
    {
        $dest = self::DESTINATION;

        return (bool) preg_match('/\b(?:agreg\w*|anad\w*|pon(?:er|lo|la)?|programa\w*|agenda\w*)\s+(?:un|una|algo de|mi|otro|otra)\s+(?:[\w\'-]+\s+){0,4}'.self::WORKOUT_NOUN.'\b[^.?!]{0,30}?\b'.$dest.'/u', $text);
    }

    /** Something already done: "hice 3x10 de press", "jugué fútbol ayer", "marca las flexiones como hechas". */
    public static function activityReport(string $text): bool
    {
        // "cómo me fue", "si mejoré", "qué levanté" ask about the past; they do not report it.
        $text = (string) preg_replace('/\b(?:como|que|cuando|si|por que|donde|cual|cuanto|saber)\s+(?:me|yo|lo|nos)?\s*(?:fue|hice|mejore|levante|corri|jugue|entrene)\b/u', ' ', $text);

        if (preg_match('/\b(?:marca|marcar|anota|anotar|registra|registrar|cuenta|tacha|tachar)\b[^.?!]{0,40}\b(?:hech[oa]s?|complet[oa]d[oa]s?|termin[oa]d[oa]s?|omitid[oa]s?|saltad[oa]s?)\b/u', $text)) {
            return true;
        }

        $verbs = 'hice|jugue|corri|camine|levante|entrene|termine|complete|saltee|falle|fui|nade|monte|troté|trote|cumpli|logre|hicimos|jugamos|corrimos|caminamos|entrenamos|terminamos|completamos|levantamos|me salte|me falto|sentadille|empuje|reme|remo';

        // "no hice", "no lo hice", "nunca terminé": a denial that something was done is not a report that it was.
        if (preg_match("/\\b(?:no|nunca|jamas|tampoco)\\s+(?:\\w+\\s+){0,2}(?:{$verbs})\\b/u", $text)) {
            return false;
        }

        return (bool) preg_match("/\\b(?:ya |ayer |hoy |solo |tambien |recien |acabo de )?(?:{$verbs})\\b/u", $text);
    }

    /**
     * A wish rather than an instruction ("quiero subir a 60 kg"). NOT read in Spanish, on purpose. A wish becomes a
     * proposal for ONE exercise's load, and the application finds that exercise by the words of the message against
     * the exercise names in the plan. Spanish names do not line up with them ("press de banca" shares the word "press"
     * with "Overhead Press" as well as "Bench Press"), and guessing an exercise is how a load lands on the wrong one.
     * Until exercise names are localized, a Spanish wish grants nothing and proposes nothing; an explicit request
     * ("cambia mi press de banca a 45 kg") is read like its English twin, and the load ceiling still applies to it.
     */
    public static function changeWish(string $text): bool
    {
        return false;
    }

    /** News that could change the week but is not a request: "tengo fútbol mañana", "me duele la rodilla", "estoy enfermo". */
    public static function adaptationEvent(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:tengo|tenemos|hay|juego|jugamos|voy a jugar|voy a viajar|viajo|estoy de viaje|estare de viaje|estoy enferm[oa]|estoy lesionad[oa])\b[^.?!]{0,40}\b(?:hoy|esta noche|manana|esta semana|este fin de semana|fin de semana|lunes|martes|miercoles|jueves|viernes|sabado|domingo|partido|juego|viaje|reunion|examen|enferm[oa]|lesion)\b/u',
            $text,
        ) || (bool) preg_match('/\b(?:me duele|me duelen|me molesta|me molestan|tengo dolor|dolor de|molestia|lesion|lesionad[oa]|tension muscular|torcedura|esguince|adolorid[oa]s?|estoy cansad[oa]|estoy agotad[oa]|estoy fatigad[oa])\b/u', $text);
    }

    /**
     * The user said, in this message, that what is already on the day should be replaced: "reemplaza el entrenamiento de
     * mañana con este", "agrégalo y sobrescribe lo que hay". Unclear, negated or merely asked-about is not a replacement.
     */
    public static function statesReplacement(string $text): bool
    {
        if ($text === '' || self::negated($text) || preg_match('/\b(?:no|sin|nunca|jamas|conserva\w*|manten\w*|deja\w*)\b/u', $text)) {
            return false;
        }

        if (str_contains($text, '?') && ! self::politeRequest($text)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:reemplaz\w*|sustitu\w*|sobrescrib\w*|en lugar de|en vez de|cambi\w* por)\b[^.?!;]{0,40}\b(?:entrenamiento|plan|planificado|sesion|rutina|horario|dashboard|calendario|ahi|existente|actual|hoy|manana|dia|lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b/u',
            $text,
        );
    }

    /** "inténtalo de nuevo", "vuelve a intentarlo", "agrégalos otra vez": the SHAPE of a retry, see SaveRetry. */
    public static function retryPhrase(string $text): bool
    {
        $text = trim($text, " \t.!?,;:");
        $text = trim((string) preg_replace('/\s+(?:por favor|porfa|gracias)$/u', '', $text), " \t.!?,;:");

        if ($text === '' || mb_strlen($text) > 100) {
            return false;
        }

        // A denial, or a question about what happened: "no lo intentes de nuevo", "por qué no lo intentaste otra vez".
        if (preg_match('/\b(?:no|nunca|jamas|deja de|por que|como es que|todavia no|aun no)\b/u', $text)) {
            return false;
        }

        $lead = '(?:(?:si|ok|okay|claro|bueno|entonces|oye|y|hola)[, ]+)*(?:(?:puedes|podrias|podria|me puedes|me podrias)\s+(?:por favor\s+)?|por favor\s+)?';
        $again = '(?:de nuevo|otra vez|una vez mas|otro intento)';
        $verb = '(?:guard\w*|agreg\w*|anad\w*|pon\w*|envi\w*|program\w*|aplic\w*)';
        $object = '(?:lo|la|los|las|eso|esto|ese|esa|esos|esas|el entrenamiento|este entrenamiento|ese entrenamiento|los ejercicios|esos ejercicios|estos ejercicios|lo mismo)';
        $place = '(?:(?:a|en|al)\s+(?:mi|el|la)\s+(?:dashboard|plan|planificador|horario|semana|calendario|entrenamientos?|agenda|panel))';

        foreach ([
            "/^{$lead}(?:reintenta\\w*|intenta\\w*|vuelve a intentar\\w*|volver a intentar\\w*|intentalo\\w*|prueba\\w*)(?:\\s+(?:de nuevo|otra vez|una vez mas))?(?:\\s+(?:{$object}))?(?:\\s+{$place})?(?:\\s+{$again})?$/u",
            "/^{$lead}(?:vuelve a|volver a)\\s+(?:{$verb}|intentar)\\w*(?:\\s+{$object})?(?:\\s+{$place})?$/u",
            "/^{$lead}{$verb}\\s*(?:{$object})?(?:\\s+{$place})?\\s+{$again}$/u",
            "/^{$lead}haz(?:lo)?\\s+(?:eso|esto|lo|de)\\s+{$again}$/u",
            "/^{$lead}dame\\s+otra\\s+oportunidad$/u",
        ] as $pattern) {
            if (preg_match($pattern, $text)) {
                // A bare "intenta" with nothing to repeat is not enough; "reintenta" and anything that says "again" is.
                return preg_match('/\b(?:reintent\w*|de nuevo|otra vez|una vez mas|otro intento|vuelve a|volver a|otra oportunidad)\b/u', $text) === 1;
            }
        }

        return false;
    }
}
