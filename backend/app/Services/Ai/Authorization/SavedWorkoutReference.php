<?php

namespace App\Services\Ai\Authorization;

use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * "Add this workout to my dashboard" only means something when exactly one workout can be meant. This finds it,
 * deterministically, or says why it cannot, so the coach is never asked to write a plan from a reference nobody
 * can resolve and never picks between two candidates on the user's behalf.
 *
 * What counts as the referenced workout:
 *  - a routine the user wrote out in their own message ("add this workout: squats 3x10, push-ups 3x12"), or
 *  - a routine in the reply IMMEDIATELY before the user's message. Not the last routine anywhere in the thread:
 *    if the user and the coach talked about something else in between, "this workout" no longer points at it.
 *    (The application's own clarification questions are skipped, so answering one still reaches the routine.)
 *
 * It declines to choose (and the user is asked) when the text holds several routines and the user's words do not
 * pick one ("Option A / Option B"), when the routine is visibly unfinished ("…", "etc.", five exercises announced
 * and three listed), when it is somebody else's words quoted back, or when the user spelled out a routine while the
 * previous reply held a different one.
 *
 * Lexical on purpose, like the classifier: an exercise line is a line with an amount (sets, reps, minutes) and an
 * exercise. It can be wrong about what counts as one; it only ever decides whether a write the user already asked
 * for may go ahead, and the answer it gives when unsure is to ask.
 */
final class SavedWorkoutReference
{
    private const AMOUNT = '(?:\d+\s*(?:x|×)\s*\d+|\d+\s*(?:-|–|to)\s*\d+\s*(?:reps?|sets?|min(?:ute)?s?|seconds?|secs?)|\d+\s*(?:sets?|reps?|rounds?|min(?:ute)?s?|seconds?|secs?|km|miles?|laps?)|(?:sets?|reps?) of \d+)';

    /** Specific movements and activities. Generic words (workout, session, routine) are deliberately not here. */
    private const EXERCISE = '(?:squats?|presses|press|rows?|curls?|deadlifts?|lunges?|planks?|push-?ups?|pull-?ups?|chin-?ups?|sit-?ups?|step-?ups?|crunch\w*|dips?|burpees?|jacks?|swings?|thrusts?|bridges?|climbers?|raises?|pulldowns?|carr(?:y|ies)|bench|run|running|jog|jogging|walk|walking|treadmill|cycl\w+|bike|biking|swim\w*|rowing|skipping|jump\w*|stretch\w*|mobility|yoga|pilates|cardio|intervals?|sprints?|hike|hiking)';

    /** Not an exercise name even when it sits in front of an amount. */
    private const NOT_AN_EXERCISE = ['workout', 'session', 'routine', 'exercise', 'exercises', 'total', 'duration', 'time', 'rest', 'warm-up', 'warm up', 'warmup', 'cool-down', 'cool down', 'cooldown', 'set', 'sets', 'rep', 'reps', 'round', 'rounds', 'week', 'day', 'note', 'notes', 'tip', 'tips', 'goal', 'focus', 'today', 'tomorrow', 'repeat', 'circuit', 'main set', 'intensity', 'target', 'about', 'around', 'aim for', 'aim', 'do', 'perform', 'complete', 'try', 'for', 'then', 'finish', 'up to', 'at least', 'in total', 'overall', 'add', 'save', 'put', 'push', 'send', 'pin', 'apply', 'use', 'schedule', 'please', 'can', 'could', 'would', 'i', 'we', 'my', 'this', 'that', 'it', 'these', 'those'];

    /** How the application's own notices begin when it adds them under a reply; they are not part of what the coach said. */
    private const APPLICATION_NOTICES = ["I haven't changed your plan", 'There is nothing waiting for your confirmation', 'I left the starting weight', 'As you asked, I replaced', 'Your plan changed since I asked'];

    private const BOUNDARY = '/^[\s>*_#\-•]*(?:(option|plan|workout|routine|session|alternative|choice|version|day|week)\s*(?:#|no\.?|number)?\s*(\d{1,2}|[a-d])\b|(alternatively|another option|if you prefer|or if you prefer))/iu';

    private const LABEL = '/\b(option|plan|workout|routine|session|alternative|choice|version|day|week)\s*(?:#|no\.?|number)?\s*(\d{1,2}|[a-d])\b/iu';

    private const ORDINALS = ['first' => 1, '1st' => 1, 'second' => 2, '2nd' => 2, 'third' => 3, '3rd' => 3];

    /**
     * @param  Collection<int, Message>  $history  the conversation up to and including the user's current message
     * @param  bool  $inMessageOnly  the request names its own workout ("add a 30 minute run to my plan"), so nothing earlier is looked at
     * @param  bool  $retry  the message repeats an earlier request, so that request and the replies that did not save it
     *                       ("I can't…") are not in the way of the workout they were about
     */
    public function resolve(string $userMessage, Collection $history, bool $inMessageOnly = false, bool $retry = false): WorkoutReference
    {
        $own = self::routines($userMessage);
        $reply = $inMessageOnly ? null : self::precedingReply($history, $retry);
        $inReply = $reply !== null ? self::routines($reply) : [];

        if ($own !== []) {
            // The user spelled a workout out, and the reply before it holds another: which one is "this"?
            // Only a routine written out as the subject of the request ("add this workout: ...") is theirs.
            if ($inReply !== [] && ! preg_match('/[:\n]/u', $userMessage)) {
                return WorkoutReference::unresolved(WorkoutReference::AMBIGUOUS);
            }

            return $this->judge($own, $userMessage, $userMessage, WorkoutReference::FROM_MESSAGE);
        }

        if ($inReply === []) {
            return WorkoutReference::unresolved(WorkoutReference::NONE);
        }

        return $this->judge($inReply, (string) $reply, $userMessage, WorkoutReference::FROM_PREVIOUS_REPLY);
    }

    /**
     * The exercise lines of a text: a line (or a comma-separated part of one) with an amount and an exercise.
     *
     * @return array<int, string>
     */
    public static function items(string $text): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[*_`#]+/u', '', $line));

            // The request around a routine is not part of it: "Add this workout to my dashboard: squats 3x10".
            $line = (string) preg_replace('/^(?:(?:please|can you|could you|would you)\s+)?(?:add|save|put|push|send|pin|apply|use|schedule)\b[^:\d]{0,80}:\s*/iu', '', $line);
            // A list marker is not part of an exercise either.
            $line = trim((string) preg_replace('/^(?:[-•–—>]+|\d+[.)])\s+/u', '', $line));

            if ($line === '') {
                continue;
            }

            foreach (self::pieces($line) as $piece) {
                if (self::isItem($piece)) {
                    $items[] = $piece;
                }
            }
        }

        return $items;
    }

    public static function describesWorkout(string $text): bool
    {
        return self::items($text) !== [];
    }

    // ---- choosing ---------------------------------------------------------------------------------

    /**
     * @param  array<int, array{label: ?string, text: string}>  $routines
     */
    private function judge(array $routines, string $whole, string $userMessage, string $origin): WorkoutReference
    {
        if (count($routines) > 1) {
            $routines = self::pick($routines, $userMessage);

            if ($routines === null) {
                return WorkoutReference::unresolved(WorkoutReference::AMBIGUOUS);
            }
        }

        $text = $routines[0]['text'];

        if (self::quoted($whole)) {
            return WorkoutReference::unresolved(WorkoutReference::QUOTED);
        }

        if (self::incomplete($text)) {
            return WorkoutReference::unresolved(WorkoutReference::INCOMPLETE);
        }

        // "Romanian Deadlift or Goblet Squat": the user has to pick, and one of them is saved, never both and never a guess.
        $items = [];
        $choices = [];

        foreach (self::items($text) as $item) {
            $options = self::alternatives($item);
            $chosen = $options !== null ? self::selects($options, $userMessage) : null;
            $chosenItem = $chosen !== null ? self::withChoice($item, $options, $chosen) : null;

            if ($options !== null && $chosenItem === null) {
                $choices[] = ['item' => $item, 'options' => $options];
            }

            $items[] = $chosenItem ?? $item;
        }

        if ($choices !== []) {
            return WorkoutReference::needsChoice($choices);
        }

        return WorkoutReference::found($text, $items, $origin);
    }

    /**
     * The exercises an item offers instead of one another ("Romanian Deadlift or Goblet Squat 3x10"), or null if it
     * names one.
     *
     * @return array<int, string>|null
     */
    public static function alternatives(string $item): ?array
    {
        $text = (string) preg_replace('/[*_`#]+/u', '', $item);
        $text = (string) preg_replace('/'.self::AMOUNT.'/iu', ' ', $text);
        $text = (string) preg_replace('/\d+/u', ' ', $text);
        $text = (string) preg_replace('/\b(?:sets?|reps?|of|rounds?|min(?:ute)?s?|seconds?|secs?|each|per|side|x)\b/iu', ' ', $text);
        $text = (string) preg_replace('/(?<![\p{L}])-|-(?![\p{L}])/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\s\'’\/,-]+/u', ' ', $text)));

        if (! preg_match('/\bor\b|\//iu', $text)) {
            return null;
        }

        // "Squats, lunges or step-ups"
        $text = str_replace(',', ' or ', $text);

        $parts = preg_split('/\s+or\s+|\s*\/\s*/iu', $text) ?: [];
        $parts = array_values(array_filter(array_map(fn (string $p) => trim((string) preg_replace('/^or\s+/iu', '', trim($p))), $parts), fn (string $p) => $p !== ''));

        if (count($parts) < 2 || count($parts) > 4) {
            return null;
        }

        foreach ($parts as $part) {
            $words = preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) > 4 || mb_strlen($part) < 3 || in_array(mb_strtolower($words[0]), ['until', 'as', 'if', 'when', 'more', 'less', 'fewer', 'so', 'then', 'to', 'for', 'with', 'without', 'near', 'about', 'around', 'up', 'both', 'either'], true)) {
                return null;
            }
        }

        return $parts;
    }

    /**
     * The one option the message names, or null if it names none or cannot be told apart ("squat" in "goblet squat" is
     * the longer one's, not a second answer).
     *
     * @param  array<int, string>  $options
     */
    public static function selects(array $options, string $message): ?string
    {
        $haystack = self::squash($message);
        $named = array_values(array_filter($options, fn (string $o) => self::squash($o) !== '' && str_contains($haystack, self::squash($o))));

        if (count($named) > 1) {
            $named = array_values(array_filter($named, function (string $o) use ($named) {
                foreach ($named as $other) {
                    if ($other !== $o && str_contains(self::squash($other), self::squash($o))) {
                        return false;
                    }
                }

                return true;
            }));
        }

        return count($named) === 1 ? $named[0] : null;
    }

    /**
     * Whether the message picks one of the offered exercises in at least one of the sets.
     *
     * @param  array<int, array<int, string>>  $groups
     */
    public static function selectsAny(array $groups, string $message): bool
    {
        foreach ($groups as $options) {
            if (is_array($options) && self::selects($options, $message) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Whether the message picks one of several workouts ("option 2", "workout B", "the second one"). */
    public static function picks(string $message): bool
    {
        $message = mb_strtolower($message);

        if (preg_match(self::LABEL, $message)) {
            return true;
        }

        foreach (array_keys(self::ORDINALS) as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\s+(?:one|option|workout|routine|session|plan)\b/u', $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item with "A or B" replaced by the chosen exercise, or null if the item is not written in a way that can be.
     *
     * @param  array<int, string>  $options
     */
    private static function withChoice(string $item, array $options, string $chosen): ?string
    {
        $pattern = '/'.implode('\s*(?:,|\bor\b|\/)\s*', array_map(fn (string $o) => preg_quote($o, '/'), $options)).'/iu';
        $replaced = preg_replace($pattern, $chosen, $item, 1, $count);

        return $count === 1 ? $replaced : null;
    }

    private static function squash(string $text): string
    {
        return rtrim((string) preg_replace('/[^\p{L}]+/u', '', mb_strtolower($text)), 's');
    }

    /**
     * The one routine the user named ("option 2", "workout B", "the second one"), or null if they named none or
     * the name fits more than one.
     *
     * @param  array<int, array{label: ?string, text: string}>  $routines
     * @return array<int, array{label: ?string, text: string}>|null
     */
    private static function pick(array $routines, string $userMessage): ?array
    {
        $message = mb_strtolower($userMessage);
        $named = [];

        if (preg_match_all(self::LABEL, $message, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $named[] = $hit[1].' '.$hit[2];
            }
        }

        if ($named !== []) {
            $matches = array_values(array_filter($routines, fn (array $r) => $r['label'] !== null && in_array($r['label'], $named, true)));

            return count($matches) === 1 ? $matches : null;
        }

        foreach (self::ORDINALS as $word => $index) {
            if (preg_match('/\b'.preg_quote($word, '/').'\s+(?:one|option|workout|routine|session|plan)\b/u', $message) && isset($routines[$index - 1])) {
                return [$routines[$index - 1]];
            }
        }

        return null;
    }

    // ---- reading the conversation ------------------------------------------------------------------

    /**
     * The reply the user is answering: the nearest assistant message, ignoring the application's own save questions. On
     * a retry, also ignoring earlier requests to save and the replies that held no workout (a failure, an apology), but
     * never an unrelated exchange.
     */
    private static function precedingReply(Collection $history, bool $retry): ?string
    {
        $skipRequest = false;
        $skipSaveRequest = false;

        foreach ($history->slice(0, -1)->reverse() as $message) {
            if ($message->role === Message::ROLE_ASSISTANT) {
                if (($message->meta['save_clarification'] ?? false) === true) {
                    $skipRequest = true;

                    continue;
                }

                $content = self::withoutApplicationNotices((string) $message->content);

                if ($retry && self::routines($content) === []) {
                    $skipSaveRequest = true;

                    continue;
                }

                return $content;
            }

            // The request that the application's question was about is part of the question, not a change of subject.
            if ($skipRequest) {
                $skipRequest = false;

                continue;
            }

            if ($skipSaveRequest && SaveRetry::isSaveLike((string) $message->content)) {
                $skipSaveRequest = false;

                continue;
            }

            return null;
        }

        return null;
    }

    /** The coach's words only: whatever the application appended below them (its own examples, dates and quotes) is cut off. */
    private static function withoutApplicationNotices(string $reply): string
    {
        $cut = null;

        foreach (self::APPLICATION_NOTICES as $notice) {
            $at = mb_strpos($reply, "\n\n".$notice);

            if ($at !== false && ($cut === null || $at < $cut)) {
                $cut = $at;
            }
        }

        return $cut === null ? $reply : mb_substr($reply, 0, $cut);
    }

    /**
     * The routines in a text, split where it offers alternatives ("Option A", "Workout 2", "Alternatively"). Parts
     * with no exercise line are not routines.
     *
     * @return array<int, array{label: ?string, text: string}>
     */
    private static function routines(string $text): array
    {
        $segments = [['label' => null, 'lines' => []]];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match(self::BOUNDARY, $line, $m)) {
                $segments[] = ['label' => ($m[1] ?? '') !== '' ? mb_strtolower($m[1].' '.$m[2]) : null, 'lines' => [$line]];

                continue;
            }

            $segments[count($segments) - 1]['lines'][] = $line;
        }

        $routines = [];

        foreach ($segments as $segment) {
            $body = implode("\n", $segment['lines']);

            if (self::items($body) !== []) {
                $routines[] = ['label' => $segment['label'], 'text' => $body];
            }
        }

        return $routines;
    }

    // ---- judging a routine ---------------------------------------------------------------------------

    /** Somebody else's words: a quoted block, or a routine attributed to a friend, a trainer, an article. */
    private static function quoted(string $text): bool
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*>/u', $line) && self::items($line) !== []) {
                return true;
            }
        }

        if (preg_match_all('/["“«]([^"”»]{8,})["”»]/us', $text, $spans)) {
            foreach ($spans[1] as $span) {
                if (self::items($span) !== []) {
                    return true;
                }
            }
        }

        return (bool) preg_match(
            '/\b(?:my|your|his|her|their|a|the)\s+(?:friend|trainer|coach|doctor|physio\w*|colleague|brother|sister|teammate)\s+(?:said|says|wrote|writes|sent|told|recommended|recommends|suggested|suggests|gave)\b|\baccording to\b|\b(?:someone|somebody)\s+(?:else\s+)?(?:said|wrote|sent)\b|\bfrom (?:an?|the) (?:article|website|video|post|blog)\b/iu',
            $text,
        );
    }

    /** Visibly unfinished: cut off, trailing off, announcing more than it lists, or a numbered step with no detail. */
    private static function incomplete(string $text): bool
    {
        if (preg_match('/…|\.{3}|\betc\b|\band so on\b|\band more\b|\bthe rest\b|\bremaining (?:exercises|sets|moves)\b|\bas before\b|\bsame as\b|\bto be (?:decided|determined|added)\b|\btbd\b|\bmore (?:exercises|moves) (?:to come|later)\b/iu', $text)) {
            return true;
        }

        if (preg_match('/[:,]$/u', rtrim($text))) {
            return true;
        }

        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        if (preg_match('/\b(\d+|two|three|four|five|six|seven|eight|nine|ten)\s+(?:exercises|moves|movements)\b/iu', $text, $m)) {
            $announced = is_numeric($m[1]) ? (int) $m[1] : $words[mb_strtolower($m[1])];

            if (count(self::items($text)) < $announced) {
                return true;
            }
        }

        // "1. Squats 3x10 / 2. Lunges / 3. Plank": a numbered step with no amount is a gap, unless it is a warm-up, a
        // cool-down or a rest.
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*\d+[.)]\s+/u', $line)
                && self::items($line) === []
                && ! preg_match('/\b(?:warm|cool|rest|stretch|breath|hydrat|finish|walk)\w*/iu', $line)) {
                return true;
            }
        }

        return false;
    }

    // ---- exercise lines --------------------------------------------------------------------------------

    /** @return array<int, string> a line, or the amount-bearing parts of a line that lists several exercises */
    private static function pieces(string $line): array
    {
        if (preg_match_all('/'.self::AMOUNT.'/iu', $line) < 2) {
            return [$line];
        }

        $pieces = [];
        $buffer = '';

        foreach (preg_split('/\s*(?:[;,]|\bthen\b|\band\b|\+)\s*/iu', $line) ?: [] as $part) {
            $buffer = trim($buffer.' '.$part);

            if (preg_match('/'.self::AMOUNT.'/iu', $buffer)) {
                $pieces[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            if ($pieces === []) {
                $pieces[] = $buffer;
            } else {
                $pieces[count($pieces) - 1] .= ' '.$buffer;
            }
        }

        return $pieces;
    }

    private static function isItem(string $piece): bool
    {
        if (! preg_match('/'.self::AMOUNT.'/iu', $piece)) {
            return false;
        }

        if (preg_match('/\b'.self::EXERCISE.'\b/iu', $piece)) {
            return true;
        }

        // "Goblet thruster 3x8": a name in front of the amount.
        $name = trim((string) (preg_split('/\d/u', $piece, 2)[0] ?? ''), " \t:-–—x×(");
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words !== []
            && count($words) <= 5
            && preg_match('/^[\p{L}\'’\/ -]+$/u', $name) === 1
            && ! in_array(mb_strtolower($name), self::NOT_AN_EXERCISE, true)
            && ! in_array(mb_strtolower($words[0]), self::NOT_AN_EXERCISE, true);
    }
}
