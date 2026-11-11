<?php

namespace App\Services\Ai\Notices;

/**
 * Recognises what the MODEL claims about the user's plan, in English or Spanish, so the application can check the claim
 * against what actually happened in the turn (the tool results) and correct it.
 *
 *  - "Saved": the model says a workout, a plan or a change was saved, added, updated or applied.
 *  - "No access": the model says it cannot reach the plan or a tool, when the turn merely withheld the plan tools
 *    because the user had not asked to change the plan.
 *
 * Both languages are always tried, whatever language the user chose: a reply can mix them, and the claim is the
 * model's, not the user's. A match is only a reason to look at the turn's tool outcomes and to add a correction; it never
 * authorizes, performs or replaces a write. Where a sentence is a question, a negation, a hypothesis, a plan for
 * later, something said long ago, or somebody's quoted words, it is not a claim.
 *
 * The result names the language the claim was made in (so the correction can be written in it), or null.
 */
final class PlanClaims
{
    public const ENGLISH = 'en';

    public const SPANISH = 'es';

    // ---- English (the original patterns, unchanged, plus passive and "successfully" forms) ----------------------------

    private const EN_HEDGE = '/\b(?:n\'t|not|unable|cannot|couldn\'t|would|could|can|will|want|if)\b/iu';

    private const EN_WRITTEN = '/\b(?:i(?:\'ve| have)?|it(?:\'s| has)?|has been|have been|is now|are now|now)\s*(?:just |now |successfully )?(?:added|saved|put|pushed|scheduled|updated|replaced|created)\b[^.\n]{0,60}\b(?:dashboard|plan|schedule|calendar)\b/iu';

    private const EN_WRITTEN_PASSIVE = [
        // "Your plan has been updated", "the workout was saved", "your dashboard is now updated"
        '/\b(?:plan|workout|schedule|dashboard|calendar|week)\b[^.\n]{0,40}\b(?:has|have|was|were|is|are)\s+(?:now |just |successfully |already )*(?:been\s+)?(?:added|saved|updated|replaced|created|changed|modified|applied|scheduled|pushed)\b/iu',
        // "The change was applied", "your updates were made"
        '/\b(?:changes?|updates?|modifications?|adjustments?)\b[^.\n]{0,30}\b(?:was|were|has been|have been|is|are)\s+(?:now |just |successfully |already )*(?:been\s+)?(?:applied|made|saved|completed|done)\b/iu',
        // "successfully saved"
        '/\bsuccessfully\s+(?:added|saved|updated|replaced|created|applied|scheduled|changed|modified)\b/iu',
    ];

    private const EN_PAST = '/\b(?:yesterday|earlier|previously|before|ago|last (?:week|month|time|session)|originally|already had)\b/iu';

    private const EN_SAFETY = '/\b(?:safe|safely|safety|injur\w+|medical|doctor|recommend\w*|should(?:n\'t)?)\b/iu';

    private const EN_NO_ACCESS = '/\b(?:no access|(?:do not|don\'t) have (?:the )?access|lack(?:ing)? access|not able to|unable to|(?:is not|isn\'t) (?:currently )?available|unavailable|(?:can\'t|cannot|can not) (?:currently |directly )?(?:update|add|push|save|modify|edit|change))\b/iu';

    private const EN_PLAN_WORD = '/\b(?:plan|dashboard|schedule|calendar|tool)\b/iu';

    private const EN_ACTION_WORD = '/\b(?:updat\w*|add\w*|sav\w*|push\w*|chang\w*|edit\w*|modif\w*|writ\w*|creat\w*|access\w*|schedul\w*|tool)\b/iu';

    // ---- Spanish --------------------------------------------------------------------------------------------------

    /** Past participles and (accented) preterites of the verbs that mean "put it on the plan". */
    private const ES_PARTICIPLE = '(?:a[ñn]adid[oa]s?|agregad[oa]s?|guardad[oa]s?|actualizad[oa]s?|programad[oa]s?|cread[oa]s?|reemplazad[oa]s?|modificad[oa]s?|aplicad[oa]s?|puest[oa]s?|cargad[oa]s?)';

    private const ES_PRETERITE = '(?:a[ñn]ad[í]|agregu[é]|guard[é]|actualic[é]|program[é]|cre[é]|reemplac[é]|modifiqu[é]|apliqu[é]|puse|cargu[é])';

    private const ES_INFINITIVE = '(?:a[ñn]adir|agregar|guardar|actualizar|programar|crear|reemplazar|modificar|aplicar|poner|cargar)';

    private const ES_TARGET = '(?:dashboard|panel|plan|calendario|agenda|horario|rutina|semana|tablero|entrenamiento)';

    private const ES_HEDGE = '/\b(?:no|nunca|tampoco|ni|sin|podr[ií]a\w*|puedo|puedes|puede|pueden|podemos|quieres|quiero|quisieras|querr[ií]as|si|cuando|deber[ií]a\w*|voy a|vas a|va a|vamos a|ir[eé] a|ser[aá]|ser[ií]a|quedar[aá]|quedar[ií]a|habr[aá]|habr[ií]a|para que|en cuanto|una vez)\b/iu';

    private const ES_PAST = '/\b(?:ayer|anteriormente|antes|hace|la semana pasada|el mes pasado|la vez pasada|originalmente|previamente)\b/iu';

    private const ES_SAFETY = '/\b(?:seguro|segura|seguros|seguridad|lesi[oó]n\w*|lesionar\w*|m[eé]dic\w*|doctor\w*|recomend\w*|deber[ií]a\w*|debes|conviene)\b/iu';

    private const ES_NO_ACCESS = '/\b(?:no tengo (?:el |la |ning[uú]n )?(?:acceso|permiso|capacidad|forma|manera)|sin acceso|no puedo acceder|no (?:puedo|logro|consigo) (?:de momento |directamente |actualmente )?(?:actualizar|a[ñn]adir|agregar|guardar|modificar|editar|cambiar|escribir|crear|programar)|no estoy (?:en capacidad|autorizad[oa]|habilitad[oa])|no me es posible|no soy capaz de|no est[aá] (?:disponible|habilitad[oa])|(?:herramienta|funci[oó]n)\s+no\s+(?:est[aá]\s+)?disponible|no disponible)\b/iu';

    private const ES_PLAN_WORD = '/\b(?:plan|dashboard|panel|calendario|agenda|horario|herramienta|herramientas|rutina|semana|entrenamiento|tablero)\b/iu';

    private const ES_ACTION_WORD = '/\b(?:actualiz\w*|a[ñn]ad\w*|agreg\w*|guard\w*|modific\w*|edit\w*|escrib\w*|cre\w*|program\w*|acces\w*|cambi\w*|herramienta\w*)\b/iu';

    /** @return array<int, string> */
    private static function spanishWritten(): array
    {
        $p = self::ES_PARTICIPLE;
        $t = self::ES_TARGET;
        $v = self::ES_INFINITIVE;

        return [
            // "He añadido el entrenamiento a tu plan", "ya lo he guardado en tu dashboard", "acabo de actualizar tu plan"
            "/\\b(?:he|hemos|acabo de)\\s+(?:ya |ahora |justo )?(?:{$p}|{$v})\\b[^.\\n]{0,60}\\b{$t}\\b/iu",
            // "Lo añadí a tu plan", "ya guardé tu entrenamiento"
            '/\b'.self::ES_PRETERITE."\\b[^.\\n]{0,60}\\b{$t}\\b/iu",
            // "Tu plan ha sido actualizado", "el entrenamiento quedó guardado", "tu semana ya está agregada"
            "/\\b{$t}\\b[^.\\n]{0,40}\\b(?:ha|han|fue|fueron|qued[oó]|quedaron|est[aá]|est[aá]n)\\s+(?:ya |ahora |correctamente |exitosamente )*(?:sido\\s+)?{$p}\\b/iu",
            // "quedó en tu plan"
            "/\\bqued[oó]\\s+(?:ya\\s+)?en\\s+(?:tu|el)\\s+{$t}\\b/iu",
            // "Los cambios se aplicaron", "los cambios fueron aplicados", "el cambio ha sido guardado"
            '/\b(?:cambios?|actualizaci[oó]n(?:es)?|modificaci[oó]n(?:es)?|ajustes?)\b[^.\n]{0,30}\b(?:se\s+(?:han\s+|ha\s+)?(?:aplic(?:[oó]|aron|ado|ados)|realiz(?:[oó]|aron|ado|ados)|guard(?:[oó]|aron|ado|ados)|hizo|hicieron|hecho)|(?:ha|han)\s+sido\s+(?:aplicad|realizad|guardad)\w+|fue(?:ron)?\s+(?:aplicad|realizad|guardad)\w+)/iu',
            // "guardado con éxito", "con éxito actualizado", "añadido exitosamente"
            "/\\b{$p}\\s+(?:con [eé]xito|exitosamente|correctamente)\\b/iu",
            "/\\b(?:con [eé]xito|exitosamente)\\s+{$p}\\b/iu",
        ];
    }

    /**
     * Whether the text claims that something was saved, added, updated or applied on the user's plan: the language the
     * claim is made in, or null.
     */
    public static function claimsPlanWritten(string $text): ?string
    {
        foreach (self::sentences($text) as $sentence) {
            if (self::isQuestion($sentence)) {
                continue;
            }

            // English: the original pattern as it was, then the newer passive and "successfully" forms, which also
            // leave out anything said about the past.
            if (! preg_match(self::EN_HEDGE, $sentence) && preg_match(self::EN_WRITTEN, $sentence)) {
                return self::ENGLISH;
            }

            if (! preg_match(self::EN_HEDGE, $sentence) && ! preg_match(self::EN_PAST, $sentence) && self::anyMatch(self::EN_WRITTEN_PASSIVE, $sentence)) {
                return self::ENGLISH;
            }

            if (! preg_match(self::ES_HEDGE, $sentence) && ! preg_match(self::ES_PAST, $sentence) && self::anyMatch(self::spanishWritten(), $sentence)) {
                return self::SPANISH;
            }
        }

        return null;
    }

    /** Whether the text says the model cannot reach the plan or a tool: the language it is said in, or null. */
    public static function claimsNoAccess(string $text): ?string
    {
        foreach (self::sentences($text) as $sentence) {
            if (! preg_match(self::EN_SAFETY, $sentence)
                && preg_match(self::EN_NO_ACCESS, $sentence)
                && preg_match(self::EN_PLAN_WORD, $sentence)
                && preg_match(self::EN_ACTION_WORD, $sentence)) {
                return self::ENGLISH;
            }

            if (! preg_match(self::ES_SAFETY, $sentence)
                && preg_match(self::ES_NO_ACCESS, $sentence)
                && preg_match(self::ES_PLAN_WORD, $sentence)
                && preg_match(self::ES_ACTION_WORD, $sentence)) {
                return self::SPANISH;
            }
        }

        return null;
    }

    /**
     * The sentences of a text with anything inside quotation marks taken out (a quoted claim is somebody else's words or
     * an example, not the model's own statement) and quoted lines (> ...) skipped.
     *
     * @return array<int, string>
     */
    private static function sentences(string $text): array
    {
        $lines = array_filter(
            preg_split('/\R/u', $text) ?: [],
            fn (string $line) => ! preg_match('/^\s*>/u', $line),
        );

        $text = implode("\n", $lines);
        $text = (string) preg_replace(['/"[^"\n]*"/u', '/“[^”\n]*”/u', '/«[^»\n]*»/u'], ' ', $text);

        return preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];
    }

    private static function isQuestion(string $sentence): bool
    {
        $trimmed = trim($sentence);

        return str_ends_with($trimmed, '?') || str_contains($trimmed, '¿');
    }

    /** @param  array<int, string>  $patterns */
    private static function anyMatch(array $patterns, string $sentence): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        return false;
    }
}
