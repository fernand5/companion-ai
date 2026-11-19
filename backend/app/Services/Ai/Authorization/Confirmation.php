<?php

namespace App\Services\Ai\Authorization;

/**
 * What a short answer to the application's own question means: yes, no, or not clearly either.
 *
 * It decides nothing by itself. A "yes" only ever redeems a proposal the application itself presented (see
 * PendingProposalStore); a refusal and an answer that is unclear redeem nothing. The one rule that matters is that
 * anything not CLEARLY a yes is not a yes: an answer is read as consent only when it can be read no other way.
 *
 * English and Spanish are both read for everyone. In Spanish the accent separates "sí" (yes) from "si" (if), and it
 * is often left out, so a bare "si" is UNCLEAR and the user is asked, while "si, reemplázalo", "claro", "dale" and
 * "de acuerdo" are clear whether or not they carry accents.
 */
final class Confirmation
{
    public const YES = 'yes';

    public const NO = 'no';

    public const UNCLEAR = 'unclear';

    public const NONE = 'none';

    /** English, as it has always been read. */
    private const YES_EN = "/^(?:yes|yeah|yep|yup|sure|ok|okay|alright|please do|go ahead|do it|yes please|sounds good|confirm|confirmed|apply it|update it|that works|let's do it|lets do it)(?:\s+(?:please|do it|go ahead|thanks|thank you))?$/u";

    /** Spanish words that mean yes without help from an accent (folded). */
    private const YES_ES = '(?:claro(?: que si)?|dale|de acuerdo|esta bien|ok|okay|vale|hazlo|adelante|confirmo|confirmado|confirmalo|aplicalo|actualizalo|reemplazalo|sustituyelo|por supuesto|perfecto|me parece bien|suena bien|procede|procedelo|continua|hagamoslo)';

    private const FILLER_ES = '/\b(?:por favor|porfa|gracias|muchas gracias)\b/u';

    /** Spanish hedges. (English hedges are not clarified: English answers are read exactly as they always were.) */
    private const UNCLEAR_ANY = '/^(?:tal vez|quiza|quizas|no se|depende|puede ser|a lo mejor|no estoy segur[oa]|ni idea)$/u';

    /** Words a refusal may be made of (folded), in both languages. A message with any other word is not a bare refusal. */
    private const NO_WORDS = '/^(?:no|nop|nope|nel|nunca|jamas|nada|cancela\w*|cancel|olvidalo|never ?mind|stop|para|mejor|ya|asi|esta|estaba|queda|se|bien|gracias|por favor|please|thanks|thank you|thank|you|quiero|hagas|hacerlo|reemplaces|reemplazar|cambies|cambiar|guardes|guardar|sobrescribas|modifiques|toques|conserva\w*|manten\w*|mantengas|deja\w*|quedate|keep|leave|it|as|is|the|current|existing|old|original|one|plan|workout|entrenamiento|rutina|sesion|actual|lo|la|los|las|el|del|de|que|tenia|tengo|momento|ahora|todavia|aun|por|don\'?t|do|not|now|replace|change|save|add|that|this|mind|changed|my|i|es|necesario|hace|falta)$/u';

    /**
     * @param  bool  $pending  exactly one valid signed proposal is waiting for this user's answer in this conversation (the
     *                         caller checked: see PendingProposalStore::isOpen). Only then is a bare "si" with no accent read as
     *                         "sí"; without one it is as it always was, not a yes. Nothing else changes with it: a hedge, a
     *                         condition ("si mañana entreno…"), a quotation or a mixed instruction is never a yes.
     */
    public static function classify(string $message, bool $pending = false): string
    {
        // A quoted answer is somebody's words, not the user's ("\"sí\"", «dale»).
        if (preg_match('/^\s*["“«„].*["”»]\s*$/us', $message) === 1) {
            return self::NONE;
        }

        $raw = mb_strtolower(trim($message));
        $raw = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[.!,;:…¡¿?"“”«»()]+/u', ' ', $raw)));
        $raw = str_replace(['’', '‘'], "'", $raw);

        if ($raw === '' || mb_strlen($raw) > 80) {
            return self::NONE;
        }

        if (preg_match(self::YES_EN, $raw) === 1) {
            return self::YES;
        }

        $folded = Lexicon::fold($raw);

        if (preg_match(self::UNCLEAR_ANY, $folded) === 1) {
            return self::UNCLEAR;
        }

        $yes = self::yes($raw, $folded);

        if ($yes !== null) {
            return $yes === self::UNCLEAR && $pending ? self::YES : $yes;
        }

        return self::refusal($folded) ? self::NO : self::NONE;
    }

    public static function isYes(string $message, bool $pending = false): bool
    {
        return self::classify($message, $pending) === self::YES;
    }

    public static function isNo(string $message): bool
    {
        return self::classify($message) === self::NO;
    }

    /** "si" with no accent and nothing else: probably "sí", possibly "if". Never consent. */
    public static function isUnclear(string $message): bool
    {
        return self::classify($message) === self::UNCLEAR;
    }

    /** @return string|null YES or UNCLEAR when the text is made of Spanish yes-words, null when it is not */
    private static function yes(string $raw, string $folded): ?string
    {
        $accented = preg_match('/(?<![\p{L}])sí(?![\p{L}])/u', $raw) === 1;
        $bare = preg_match('/(?<![\p{L}])si(?![\p{L}])/u', $raw) === 1;

        // The words that are left once "sí"/"si" and the politeness are taken out.
        $rest = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::FILLER_ES, ' ', (string) preg_replace('/(?<![a-z])si(?![a-z])/u', ' ', $folded))));

        if ($rest === '') {
            return $accented ? self::YES : ($bare ? self::UNCLEAR : null);
        }

        $word = self::YES_ES;

        return preg_match("/^{$word}(?: {$word})*$/u", $rest) === 1 ? self::YES : null;
    }

    /**
     * A bare refusal: it begins with a refusal ("no", "mejor no", "cancela", "keep the current one") and is made of
     * nothing but refusal words. "No, reemplaza el de mañana" has a request in it, so it is not a bare refusal.
     */
    private static function refusal(string $folded): bool
    {
        if (preg_match('/^(?:no|nop|nope|nel|nunca|jamas|nada|mejor no|ya no|cancela\w*|cancel|olvidalo|never ?mind|stop|conserva\w*|manten\w*|deja\w*|quedate|keep|leave|don\'?t|para nada|not now)\b/u', $folded) !== 1) {
            return false;
        }

        foreach (preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match(self::NO_WORDS, $word) !== 1) {
                return false;
            }
        }

        return true;
    }
}
