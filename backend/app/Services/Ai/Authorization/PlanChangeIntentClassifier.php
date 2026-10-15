<?php

namespace App\Services\Ai\Authorization;

/**
 * Decides, from the user's own words, what a chat turn may change. Deliberately
 * lexical, narrow and deterministic: it fails closed. A message it does not
 * recognise as a request grants nothing, so the worst a misreading costs is the
 * user saying "update my plan" in plain words.
 *
 * What it grants:
 *  - an explicit request to change the plan ("update my plan", "change my bench
 *    to 45 kg") grants every plan capability;
 *  - a request for a session ("what should I do today?", "give me a workout")
 *    grants writing that day's plan;
 *  - a report of something already done ("I benched 3x10 at 40", "mark push-ups
 *    done", "I played soccer yesterday") grants marking progress and adapting the
 *    week, the two flows that already followed an activity report;
 * A question about the user's performance ("did I improve?", "what should I focus
 * on next?") grants nothing, and neither does a negated request ("don't change my
 * plan", "I didn't update my plan"), text the user quoted or pasted, or an
 * instruction attributed to someone else ("the note says: change my plan"). A wish
 * that is not an instruction ("I want to go to 60 kg") grants nothing: the application
 * either turns it into ONE precise proposal for the user to confirm (see
 * PlanChangeProposalParser) or asks for an explicit request. A bare "yes" is never
 * classified as permission; it can only redeem a proposal the application itself
 * presented (see PendingProposalStore).
 *
 * LIMITS. This is keyword matching, not understanding, and it is NOT reliable in any
 * general sense:
 *  - English only. Another language is not recognised at all, so it grants nothing and
 *    gets no prompt: the plan is simply not changed until the user writes an explicit
 *    request this reads. Localization needs its own phrase lists, tests and evaluation.
 *  - Misspellings, slang, unusual word order and indirect requests are not recognised.
 *  - A recognised phrase can still be a false positive; the protections that do not depend
 *    on it are the load ceiling at dispatch, the signed single-use proposals, and the
 *    stored plan being unchanged by any question.
 * It is built to fail closed: when in doubt, it grants nothing.
 */
final class PlanChangeIntentClassifier
{
    /** Words that can name the thing being changed. */
    private const PLAN_NOUN = '(?:plan|workout|routine|session|program|programme|training|week|day|exercise|target|targets|weights?|loads?|reps|sets|lifts?)';

    /** A determiner so that "update me on ..." and "updates on ..." are not read as "update my ...". */
    private const DETERMINER = '(?:my|the|this|that|these|those|our|today\'?s|tomorrow\'?s|next|upcoming|current)';

    /** A message longer than this is treated as pasted material, not as an instruction to the coach. */
    private const MAX_INSTRUCTION_CHARS = 600;

    public function classify(string $message): ToolAuthorization
    {
        $text = $this->normalize($message);

        if ($text === '') {
            return ToolAuthorization::readOnly('empty');
        }

        // A bare "yes" means nothing by itself. It is only ever honoured by redeeming a
        // proposal the application itself presented (PendingProposalStore); reaching here
        // means there was none, so it changes nothing.
        if (self::isBareAffirmation($message)) {
            return ToolAuthorization::readOnly('unconfirmed_affirmation', ToolAuthorization::FOLLOW_UP_NOTHING_PENDING);
        }

        // Text the user pasted or quoted, or that someone else said, is not the user telling
        // the coach what to do. Quoted passages are removed before reading; a sentence that
        // attributes an instruction to someone or something else, and any message too long to
        // be a direct instruction, grants nothing.
        $pasted = mb_strlen($message) > self::MAX_INSTRUCTION_CHARS;
        $negated = $this->negated($text);

        $explicit = false;
        $request = false;
        $report = false;
        $wish = false;
        $event = false;
        $anyStatement = false;
        $anyQuestion = false;

        foreach ($this->sentences($text) as $sentence) {
            $question = $this->isQuestion($sentence);
            $anyQuestion = $anyQuestion || $question;

            // Someone else's words, or a denial that anything was done: neither is an instruction.
            if ($this->attributedToSomeoneElse($sentence) || $this->negated($sentence)) {
                continue;
            }

            $explicit = $explicit || (! $pasted && ($question ? $this->politeRequest($sentence) : true) && $this->explicitChange($sentence));
            $request = $request || (! $pasted && $this->sessionRequest($sentence));

            if (! $question) {
                $anyStatement = true;
                $report = $report || (! $pasted && $this->activityReport($sentence));
                $wish = $wish || $this->changeWish($sentence);
                $event = $event || $this->adaptationEvent($sentence);
            }
        }

        $granted = [];
        $basis = [];

        if ($explicit && ! $negated) {
            array_push($granted, ToolCapability::PlanCreate, ToolCapability::PlanAdapt, ToolCapability::PlanProgress);
            $basis[] = 'explicit_change';
        }

        if ($request && ! $negated) {
            $granted[] = ToolCapability::PlanCreate;
            $basis[] = 'session_request';
        }

        if ($report) {
            array_push($granted, ToolCapability::PlanProgress, ...($negated ? [] : [ToolCapability::PlanAdapt]));
            $basis[] = 'activity_report';
        }

        if ($granted !== []) {
            return new ToolAuthorization($granted, implode('+', $basis));
        }

        if ($negated) {
            return ToolAuthorization::readOnly('negated');
        }

        // Nothing was asked for, but the user seems to want something to change. Do not guess:
        // leave the plan alone. A wish the application can pin down exactly becomes a proposal
        // to confirm; anything vaguer gets a request to say it explicitly. A message that also
        // asks something is answered, not interrupted.
        if ($anyStatement && ! $anyQuestion && ! $pasted) {
            if ($wish) {
                return ToolAuthorization::readOnly('ambiguous_change', ToolAuthorization::FOLLOW_UP_PROPOSE);
            }

            if ($event) {
                return ToolAuthorization::readOnly('plan_news', ToolAuthorization::FOLLOW_UP_EXPLICIT_REQUEST);
            }
        }

        return ToolAuthorization::readOnly($this->isQuestion($text) ? 'question' : 'no_plan_request');
    }

    /** "yes", "ok do it", "go ahead", "sure": no content of its own. */
    public static function isBareAffirmation(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[.!,]+/u', ' ', $text)));

        return (bool) preg_match(
            "/^(?:yes|yeah|yep|yup|sure|ok|okay|alright|please do|go ahead|do it|yes please|sounds good|confirm|confirmed|apply it|update it|that works|let's do it|lets do it)(?:\s+(?:please|do it|go ahead|thanks|thank you))?$/u",
            $text,
        );
    }

    /** "the note says: change my plan", "my trainer wrote ...", "according to the article ...". */
    private function attributedToSomeoneElse(string $sentence): bool
    {
        return (bool) preg_match(
            '/\b(?:says?|said|saying|wrote|writes?|written|told|tells?|telling|asked|asks|suggests|suggested|recommends|recommended|advises|advised|note|notes|message|email|e-mail|trainer|doctor|physio\w*|friend|article|post|quote[sd]?|pasted|copied|forwarded|reads?|according|states?|stated|instructions?)\b/u',
            $sentence,
        );
    }

    /** @return array<int, string> */
    private function sentences(string $text): array
    {
        preg_match_all('/.+?(?:[.!?;]+(?=\s|$)|$)/us', $text, $matches);

        return array_values(array_filter(array_map('trim', $matches[0]), fn (string $s) => $s !== ''));
    }

    /** "can you update my plan?", "could you please change today's workout?": a question in form, a request in fact. */
    private function politeRequest(string $sentence): bool
    {
        return (bool) preg_match('/^(?:(?:hey|ok|okay|so|please|and)[, ]+)*(?:can|could|would|will) you\b/u', $sentence);
    }

    private function normalize(string $message): string
    {
        // Quoted passages ("..." or “...”), fenced code and quoted lines (> ...) are not the user's own instruction.
        $text = (string) preg_replace('/```.*?```/su', ' ', mb_strtolower($message));
        $text = str_replace(['’', '‘', '`'], "'", $text);
        $text = (string) preg_replace('/^\s*>.*$/mu', ' ', $text);
        $text = (string) preg_replace('/["“”«»].*?["“”«»]/su', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** "don't change my plan", "no need to update it", "without changing anything". */
    private function negated(string $text): bool
    {
        return (bool) preg_match(
            "/\b(?:do not|don't|dont|doesn't|does not|did not|didn't|didnt|have not|haven't|havent|has not|hasn't|had not|hadn't|was not|wasn't|were not|weren't|never|without|no need to|not to|stop|please don't|leave|nothing)\b[^.?!;]{0,30}\b(?:chang\w*|updat\w*|edit\w*|modif\w*|adjust\w*|replac\w*|alter\w*|touch\w*|rewrit\w*|creat\w*|mak\w*|set\w*|add\w*)\b/u",
            $text,
        ) || (bool) preg_match('/\b(?:just (?:asking|checking|curious)|only (?:asking|checking)|not asking you to)\b/u', $text);
    }

    private function isQuestion(string $text): bool
    {
        return str_contains($text, '?')
            || (bool) preg_match("/^(?:should|is|are|am|do|does|did|was|were|can i|could i|will i|would|why|how|what|which|when|where|who|any)\b/u", $text);
    }

    /**
     * "update my plan", "change the weight on bench", "can you adjust my workout",
     * "replace today's session", "increase the weight in my plan".
     */
    private function explicitChange(string $text): bool
    {
        $noun = self::PLAN_NOUN;
        $det = self::DETERMINER;
        $verb = '(?:updat\w*|chang\w*|edit\w*|modif\w*|adjust\w*|replac\w*|swap\w*|revis\w*|rewrit\w*|redo|regenerat\w*|reset|creat\w*|build|writ\w*|generat\w*|apply|program|schedul\w*|set|put|mak\w*)';

        // verb + determiner + up to two words + a plan noun: "update my plan", "change my bench press weight".
        if (preg_match("/\\b{$verb}\\s+{$det}\\s+(?:[\\w'-]+\\s+){0,2}{$noun}\\b/u", $text)) {
            return true;
        }

        // "plan my week", "program my workouts".
        if (preg_match("/\\b(?:plan|program)\\s+{$det}\\s+(?:[\\w'-]+\\s+){0,1}(?:week|day|workouts?|sessions?|training)\\b/u", $text)) {
            return true;
        }

        // "increase the weight in my plan", "lower the reps on next session".
        return (bool) preg_match("/\\b(?:increas\\w*|rais\\w*|lower\\w*|reduc\\w*|decreas\\w*|bump\\w*)\\s+{$det}\\s+(?:[\\w'-]+\\s+){0,2}(?:weights?|loads?|reps|sets|target|targets)\\b[^.?!]{0,40}\\b(?:in|on|for|to)\\s+{$det}?\\s*(?:[\\w'-]+\\s+){0,2}(?:plan|workout|session|routine)\\b/u", $text);
    }

    /**
     * Asking for a session to be made: "what should I do today?", "give me a
     * workout", "suggest a quick session". "What should I focus on next?" is
     * advice about the record, not a request for a session.
     */
    private function sessionRequest(string $text): bool
    {
        if (preg_match('/\\bwhat (?:should|can|could|do) (?:i|we) (?:do|train|work out|workout|lift)\\b[^?.!]{0,30}\\b(?:today|tonight|tomorrow|now|this (?:morning|afternoon|evening))\\b/u', $text)) {
            return true;
        }

        return (bool) preg_match("/\\b(?:give|get|suggest|recommend|prepare|prescribe)\\b(?:\\s+me)?\\s+(?:an?|some|my|today'?s|tomorrow'?s)?\\s*(?:[\\w'-]+\\s+){0,3}(?:workout|session|routine|training)\\b/u", $text)
            || (bool) preg_match("/\\b(?:i need|i want|i'd like|i would like|i'm looking for|looking for)\\s+(?:an?|some)\\s+(?:[\\w'-]+\\s+){0,3}(?:workout|session|routine)\\b/u", $text);
    }

    /**
     * Something already done: "I benched 3x10 at 40", "I played soccer yesterday",
     * "mark push-ups done", "I finished my squats". Past tense, first person.
     */
    private function activityReport(string $text): bool
    {
        // "how I did", "whether I improved", "what I lifted" ask about the past; they do not report it.
        $text = (string) preg_replace('/\b(?:how|what|when|whether|if|why|where|which|know)\s+(?:i|we)\b/u', ' ', $text);

        if (preg_match('/\\b(?:mark|tick|check|log|record|count)\\b[^.?!]{0,40}\\b(?:done|complete|completed|finished|skipped)\\b/u', $text)) {
            return true;
        }

        return (bool) preg_match(
            "/\\b(?:i|we)(?:'ve| have)?\\s+(?:just |also |only |already |then )?(?:did|played|ran|walked|lifted|trained|worked out|finished|completed|skipped|missed|hit|went|swam|cycled|biked|jogged|squatted|benched|pressed|rowed|deadlifted|logged|got through|managed)\\b/u",
            $text,
        ) && ! preg_match('/^(?:did|have|had) (?:i|we)\b/u', $text);
    }

    /** A wish rather than an instruction: "I want to go to 60 kg", "let's go heavier", "make it harder". */
    private function changeWish(string $text): bool
    {
        $wish = "/\\b(?:i want|i'd like|i would like|i wanna|let's|lets|we should|i'm going to|i plan to|how about|i think i should|time to|can we|could we)\\b/u";
        $change = '/\\b(?:go|move|jump|step|bump|increas\\w*|rais\\w*|lower\\w*|reduc\\w*|drop|add|up|heavier|lighter|harder|easier|more|less|change|swap|switch|progress)\\b/u';
        $imperative = '/^(?:make|go|move|jump|bump|increase|raise|lower|reduce|drop|add|switch|swap|change)\\b/u';
        $subject = '/\\b(?:kg|lbs?|pounds?|weights?|loads?|heavier|lighter|harder|easier|reps|sets|plan|workout|session|target|next time|next session|tomorrow|week)\\b/u';

        return (preg_match($subject, $text) === 1)
            && (preg_match($imperative, $text) === 1 || (preg_match($wish, $text) === 1 && preg_match($change, $text) === 1));
    }

    /** News that could change the week but is not a request: "I have soccer tomorrow", "my legs are very sore", "my knee hurts". */
    private function adaptationEvent(string $text): bool
    {
        return (bool) preg_match(
            "/\\b(?:i have|i've got|i got|we have|there's|i'm playing|i am playing|i'm traveling|i'm travelling|i'm away|i'm sick|i am sick|i'm ill)\\b[^.?!]{0,40}\\b(?:today|tonight|tomorrow|this (?:week|weekend)|monday|tuesday|wednesday|thursday|friday|saturday|sunday|match|game|trip|meeting|deadline|sick|ill)\\b/u",
            $text,
        ) || (bool) preg_match("/\\bmy (?:[\\w'-]+ ){0,2}(?:is|are|feels?|feel) (?:\\w+ )?(?:sore|hurt\\w*|painful|tired|exhausted|injured|aching)\\b/u", $text)
            || (bool) preg_match('/\\b(?:hurts?|hurt|aches?|aching|injur\\w+|strain\\w*|sprain\\w*)\\b/u', $text);
    }
}
