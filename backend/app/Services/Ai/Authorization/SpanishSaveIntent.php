<?php

namespace App\Services\Ai\Authorization;

/**
 * Hotfix: the Spanish way of asking to put a workout the coach just wrote on the plan, read by the same deterministic
 * classifier as the English one (PlanChangeIntentClassifier) and answered by the same save path (SavedWorkoutReference,
 * SaveWorkoutDay, SaveWorkoutScope, signed replacement confirmation). It grants nothing by itself: like its English
 * twin it only says "this sentence asks to save the workout that is already in the conversation"; the application
 * still has to find exactly one complete workout and one day, or it asks.
 *
 * Narrow on purpose. It reads a request to SAVE a workout that is pointed at ("este entrenamiento", "esa rutina",
 * "el entrenamiento que acabas de recomendar"); it does not read broad "change my plan" requests, wishes, loads or
 * activity reports in Spanish (those still grant nothing). Matching is on accent-folded lower case, so "sí"/"si",
 * "miércoles"/"miercoles" are one word; the common misspellings of "entrenamiento" are read only here, in a sentence
 * that is already a request to save something to the plan.
 */
final class SpanishSaveIntent
{
    /** Lower case without accents (and without ¿ ¡): for matching, never for storing. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower(str_replace(['’', '‘', '`'], "'", $text)), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', '¿' => '', '¡' => '',
        ]);
    }

    /** "entrenamiento" and the ways it is mistyped, "entretenimiento" (entertainment) included; "plan", "rutina", "sesión". */
    private const WORKOUT_NOUN = '(?:entrenamientos?|entrenamento|entramiento|entrenamietno|entrenameinto|entretenimientos?|entrenamientto|sesion(?:es)?|rutinas?|planes|plan|programas?|ejercicios|caminatas?|carreras?)';

    private const VERB = '(?:agreg(?:a|ar|alo|ala|alos|alas)|anad(?:e|ir|elo|ela|elos|elas)|guard(?:a|ar|alo|ala|alos|alas)|pon(?:er|lo|la|los|las)?|met(?:e|er|elo|ela)|envi(?:a|ar|alo|ala)|program(?:a|ar|alo|ala)|agend(?:a|ar|alo|ala)|aplic(?:a|ar|alo|ala)|sub(?:e|ir|elo|ela))';

    /** "este entrenamiento", "esa rutina", "el entrenamiento de arriba", "el plan que acabas de recomendar", "esto", "lo". */
    private const ANAPHOR = '(?:(?:este|esta|ese|esa|estos|estas|esos|esas|el|la|los|las|tu|mi)\s+(?:[\w\'-]+\s+){0,1}'.self::WORKOUT_NOUN
        .'(?:\s+(?:de arriba|anterior|recomendad[oa]|sugerid[oa]|propuest[oa]|que (?:me |te )?(?:acabas de|acabo de|diste|escribiste|sugeriste|recomendaste|describiste|enviaste|propusiste|armaste|preparaste)(?:\s+\w+)?))?'
        .'|esto|eso|lo|la|los|las'
        .'|(?:opcion|plan|entrenamiento|rutina|sesion|version|alternativa)\s*(?:#|no\.?|numero)?\s*(?:\d{1,2}|[a-d])'
        .'|(?:el|la)\s+(?:primer[oa]?|segund[oa]|tercer[oa]?|ultim[oa]|mejor|otr[oa])\s+(?:opcion|entrenamiento|rutina|sesion|plan|version))';

    /** Where it goes: the dashboard, the panel, the plan, the calendar, the week, a day. */
    private const DESTINATION = '(?:a|al|en|para|como|sobre|dentro de)\s+(?:(?:mi|mis|el|la|tu|tus|nuestro|este|esta|los|las)\s+)?(?:[\w\'-]+\s+){0,2}(?:dashboard|panel|tablero|plan|planificador|calendario|agenda|horario|semana|semanal|hoy|esta noche|manana|pasado manana|\d{4}-\d{2}-\d{2}|entrenamientos?|lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b';

    private const DET = '(?:mi|el|la|tu|nuestro|nuestra)';

    /** "agrega este entrenamiento a mi dashboard", "guarda esta rutina en mi plan", "actualiza mi plan de hoy con esta rutina". Sentence is folded. */
    public static function saveWorkoutRequest(string $sentence): bool
    {
        $verb = self::VERB;
        $anaphor = self::ANAPHOR;
        $dest = self::DESTINATION;

        if (preg_match("/\\b{$verb}\\s+{$anaphor}\\b[^.?!]{0,40}?\\b{$dest}/u", $sentence)) {
            return true;
        }

        // "guárdalo en mi plan": the verb carries the "it".
        if (preg_match('/\b(?:guard|agreg|anad|met|envi|pon)(?:alo|ala|alos|alas|elo|ela|elos|elas|lo|la|los|las)\b[^.?!]{0,40}?\b'.$dest.'/u', $sentence)) {
            return true;
        }

        // "actualiza mi plan de hoy con esta rutina", "reemplaza el entrenamiento de hoy con este": the plan updated WITH the routine that is pointed at.
        if (preg_match('/\\b(?:actualiz(?:a|ar)|cambi(?:a|ar)|reemplaz(?:a|ar)|sustitu(?:ye|ir)|sobrescrib(?:e|ir)|rellen(?:a|ar))\\s+'.self::DET."\\s+(?:[\\w'-]+\\s+){0,3}(?:plan|entrenamiento|rutina|dia|sesion|calendario|agenda)\\b[^.?!]{0,30}?\\b(?:con|por|usando|utilizando)\\s+(?:este|esta|esto|ese|esa|eso|el (?:entrenamiento|plan|rutina|sesion) (?:de arriba|anterior|que (?:me |te )?(?:acabas de|diste|escribiste|sugeriste|recomendaste|describiste|propusiste)))\\b/u", $sentence)) {
            return true;
        }

        // "guarda el entrenamiento que acabas de recomendar": saving a workout needs no destination.
        return (bool) preg_match('/\bguard(?:a|ar)\s+(?:este|esta|ese|esa|el|la|estos|estas)\s+(?:[\w\'-]+\s+)?'.self::WORKOUT_NOUN.'\b/u', $sentence);
    }

    /** "reemplaza el entrenamiento de hoy con este": the user says outright that what is there should be replaced. Text is folded. */
    public static function statesReplacement(string $text): bool
    {
        if ($text === '' || self::negated($text) || preg_match('/\b(?:no|sin|nunca|jamas|conserva\w*|manten\w*|deja\w*)\b/u', $text)) {
            return false;
        }

        if (str_contains($text, '?') && ! self::politeRequest($text)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:reemplaz\w*|sustitu\w*|sobrescrib\w*|en lugar de|en vez de)\b[^.?!;]{0,40}\b(?:entrenamiento|plan|planificado|sesion|rutina|horario|dashboard|panel|calendario|ahi|existente|actual|hoy|manana|dia|lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b/u',
            $text,
        );
    }

    /** "no cambies mi plan", "no guardes eso", "sin modificar nada": an instruction NOT to. (Not "no lo guardaste": that is a complaint about the past.) */
    public static function negated(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:no|nunca|jamas|sin|ni)\s+(?:me\s+|lo\s+|la\s+|los\s+|las\s+|te\s+)?(?:cambies|actualices|guardes|agregues|anadas|edites|modifiques|reemplaces|pongas|metas|alteres|toques|crees|cambiar|actualizar|guardar|agregar|anadir|editar|modificar|reemplazar|alterar|tocar|crear)\b/u',
            $text,
        ) || (bool) preg_match('/\b(?:no hace falta|no necesito|no quiero que|solo (?:estoy )?(?:preguntando|revisando)|no te (?:estoy )?pido)\b/u', $text);
    }

    public static function isQuestion(string $sentence): bool
    {
        return str_contains($sentence, '?') || (bool) preg_match('/^(?:por que|porque|como|que|cual|cuando|donde|quien|puedes|podrias|debo|deberia|puedo|es|son|hay)\b/u', $sentence);
    }

    /** "¿puedes agregar este entrenamiento a mi dashboard?": a question in form, a request in fact. */
    public static function politeRequest(string $sentence): bool
    {
        return (bool) preg_match('/^(?:(?:hola|ok|okay|entonces|por favor|y|oye)[, ]+)*(?:puedes|podrias|me puedes|me podrias|quieres|serias capaz de)\b/u', $sentence);
    }

    /** Somebody else's words: "mi entrenador dijo: guarda esto", "el mensaje dice…". */
    public static function attributedToSomeoneElse(string $sentence): bool
    {
        return (bool) preg_match('/\b(?:dice|dijo|dicen|escribio|escribe|me dijo|me dice|segun|entrenador|entrenadora|doctor|doctora|medico|medica|fisio\w*|amigo|amiga|articulo|mensaje|correo|email|nota|citado|pegado|copiado|reenviado|recomienda|recomendo|sugiere|sugirio)\b/u', $sentence);
    }

    /** "¿por qué no lo agregaste al dashboard?", "why didn't you add it?": asks why a workout is not on the plan. Message as written. */
    public static function asksWhyNotSaved(string $message): bool
    {
        $es = self::fold($message);

        if (preg_match('/\b(?:por ?que|porque|como es que|y por que)\s+no\s+(?:lo|la|los|las|me lo|me la)?\s*(?:agregaste|guardaste|anadiste|pusiste|metiste|programaste|actualizaste|enviaste|has agregado|has guardado|has anadido|has puesto|agregaron|guardaron)\b/u', $es)) {
            return true;
        }

        $en = mb_strtolower($message);

        return (bool) preg_match("/\\bwhy (?:didn'?t|did not|haven'?t|have not|hasn'?t|wasn'?t|was not|isn'?t|is not)\\b[^.?!]{0,30}\\b(?:add\\w*|sav\\w*|put|push\\w*|schedul\\w*|updat\\w*)\\b/u", $en);
    }

    /** Whether a message is written in Spanish, well enough to answer the application's own words in Spanish. Presentation only: never a permission. */
    public static function writtenIn(string $message): bool
    {
        if (preg_match('/[áéíóúñ¿¡]/iu', $message)) {
            return true;
        }

        return (bool) preg_match('/\b(?:este|esta|estos|estas|mi|mis|para|una|del|al|hoy|manana|agrega\w*|guarda\w*|anade|anadir|actualiza\w*|reemplaza\w*|rutina|entren\w*|porque|dale|claro|de acuerdo|hazlo|adelante|gracias|por favor|por que|si)\b/u', self::fold($message));
    }

    /**
     * A clear yes in Spanish, to the application's own question. The accent separates "sí" (yes) from "si" (if) and is
     * often left out, so an unaccented bare "si" is NOT a yes ("si, reemplázalo" with a verb after the comma is). Message as written.
     */
    public static function isBareYes(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[.!¡¿]+/u', ' ', $text)), " \t,");
        $verbs = '(?:reemplazalo|reemplázalo|sustituyelo|sustitúyelo|hazlo|confirmalo|confírmalo|guardalo|guárdalo|agregalo|agrégalo|aplicalo|aplícalo|adelante|confirmo|claro|dale|de acuerdo|ok|vale|perfecto)';

        return (bool) preg_match(
            '/^(?:sí|s[ií]\s*,\s*'.$verbs.'|sí\s+'.$verbs.'|'.$verbs.'|claro que sí|por supuesto|de acuerdo|me parece bien|suena bien|confirmado)(?:\s*,?\s*(?:por favor|gracias))?$/u',
            $text,
        ) && ! preg_match('/^si(?:\s+por favor)?$/u', $text);
    }

    /** A bare "si" with no accent (or "si." / "SI"): it may be "sí" (yes) or "si" (if), so it is neither a yes nor a no. Message as written. */
    public static function isAmbiguousYes(string $message): bool
    {
        return (bool) preg_match('/^\s*[¿¡]*si[.!,\s]*$/iu', $message);
    }

    /** "inténtalo de nuevo", "vuelve a intentarlo", "agrégalo otra vez": the SHAPE of a retry. Text as written. */
    public static function retryPhrase(string $text): bool
    {
        $es = trim(self::fold($text), " \t.!?,;:");
        $es = trim((string) preg_replace('/\s+(?:por favor|porfa|gracias)$/u', '', $es), " \t.!?,;:");

        if ($es === '' || mb_strlen($es) > 100 || preg_match('/\b(?:no|nunca|jamas|por que|porque)\b/u', $es)) {
            return false;
        }

        $again = '(?:de nuevo|otra vez|una vez mas)';
        $verb = '(?:guard\w*|agreg\w*|anad\w*|pon\w*|envi\w*|program\w*)';
        $object = '(?:lo|la|los|las|eso|esto|el entrenamiento|este entrenamiento|esa rutina|esta rutina)';
        $lead = '(?:(?:si|ok|okay|claro|bueno|entonces|oye|y|hola)[, ]+)*(?:(?:puedes|podrias|me puedes)\s+(?:por favor\s+)?|por favor\s+)?';

        foreach ([
            "/^{$lead}(?:reintent\\w*|intent\\w*|vuelve a intentar\\w*|volver a intentar\\w*)(?:\\s+{$object})?(?:\\s+{$again})?$/u",
            "/^{$lead}(?:vuelve a|volver a)\\s+{$verb}(?:\\s+{$object})?$/u",
            "/^{$lead}{$verb}\\s*(?:{$object})?\\s+{$again}$/u",
        ] as $pattern) {
            if (preg_match($pattern, $es)) {
                return preg_match('/\b(?:reintent\w*|de nuevo|otra vez|una vez mas|vuelve a|volver a)\b/u', $es) === 1;
            }
        }

        return false;
    }
}
