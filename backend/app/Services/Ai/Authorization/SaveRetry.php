<?php

namespace App\Services\Ai\Authorization;

use App\Models\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Could you try to add them again?" and "Friday" only mean something because of what was asked a moment ago. This
 * finds that earlier request, so the message can be read as the same request with one more detail, never as a
 * permission of its own.
 *
 * Two ways a message continues an earlier request to put a workout on the plan:
 *
 *  - ANSWER: the application asked a question about the request (which exercise, which day, which of two workouts) and
 *    stored what it asked next to its own message. The user's very next message answers it. It only counts if it really
 *    does (it picks one of the offered exercises, names a day, picks a workout, or spells out the workout).
 *  - RETRY: the user says to try it again ("try again", "add them again"), and an earlier request to save a workout
 *    in this conversation is still open: it came within the last few messages and no workout has been saved since.
 *
 * Either way the request must still be fresh: made within PlanReplacementProposal::TTL_MINUTES (the same 15 minutes a
 * confirmation lasts) and on the same calendar day, in the user's own timezone, so that "today" and "tomorrow" in it mean
 * what they meant when it was made. A stale one is not replayed (EXPIRED): the user is told, and asks again.
 *
 * Either way the authorization is the user's EARLIER, explicit request (read again from the conversation, and only if it
 * asked for nothing more than putting a workout on the plan), plus the new words. Nothing else in the conversation
 * counts: an arbitrary "yes", "ok", "again" or "that one" with no such request open grants nothing.
 */
final class SaveRetry
{
    public const ANSWER = 'answer';

    public const RETRY = 'retry';

    /** The request this continues is no longer fresh: nothing is replayed. `reason` is TOO_OLD or NEW_DAY. */
    public const EXPIRED = 'expired';

    public const TOO_OLD = 'too_old';

    public const NEW_DAY = 'new_day';

    private const LEAD = '(?:(?:yes|yeah|yep|ok|okay|sure|please|so|hey|and|then|well)[,. ]+)*(?:(?:can|could|would|will) you (?:please )?|please )?';

    private const REPEAT = '(?:again|once more|one more time|another (?:try|go|attempt))';

    private const VERB = '(?:add|save|put|push|send|schedule|apply)';

    private const OBJECT = '(?:them|it|that|those|these|this|the workout|this workout|that workout|the exercises|those exercises|these exercises|the same)';

    private const PLACE = '(?:(?:to|on|onto|in|into)\s+(?:my|the)\s+(?:dashboard|plan|planner|schedule|week|calendar|workouts?))';

    /** "try again", "could you try to add them again?", "add them to my dashboard again", "give it another try". */
    public static function isRetryPhrase(string $message): bool
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));
        $text = trim(str_replace(['’', '‘'], "'", $text), " \t.!?,;:");
        $text = trim((string) preg_replace('/\s+(?:please|pls|thanks|thank you)$/u', '', $text), " \t.!?,;:");

        if ($text === '' || mb_strlen($text) > 100) {
            return false;
        }

        // A denial, or a question about what happened, is not a request: "don't try again", "why didn't you try again".
        if (preg_match("/\b(?:don't|do not|dont|never|stop|no need|why|how come|didn't|did not|haven't|have not|wouldn't|won't)\b/u", $text)) {
            return false;
        }

        $lead = self::LEAD;
        $verb = self::VERB;
        $object = self::OBJECT;
        $repeat = self::REPEAT;
        $place = self::PLACE;

        foreach ([
            // try again / retry / try that again / try to add them again / try adding them to my plan again
            "/^{$lead}(?:retry|try)(?:\\s+(?:that|it|this))?(?:\\s+(?:to\\s+)?(?:{$verb}|adding|saving|putting|pushing))?(?:\\s+{$object})?(?:\\s+{$place})?(?:\\s+{$repeat})?$/u",
            // add them again / add them to my dashboard again / save it once more
            "/^{$lead}{$verb}\\s+{$object}(?:\\s+{$place})?\\s+{$repeat}$/u",
            // do that again / give it another try
            "/^{$lead}(?:do|redo)\\s+(?:that|it|this)\\s+{$repeat}$/u",
            "/^{$lead}give\\s+(?:that|it|this)\\s+{$repeat}$/u",
        ] as $pattern) {
            if (preg_match($pattern, $text)) {
                // A bare "try" or "try it" with nothing to repeat is not enough; "retry" and anything saying "again" is.
                return preg_match('/\b(?:retry|again|once more|one more time|another)\b/u', $text) === 1;
            }
        }

        return false;
    }

    /** A request to put a workout on the plan (and nothing broader), or a retry of one. */
    public static function isSaveLike(string $content): bool
    {
        return self::isRetryPhrase($content) || self::isSaveRequest($content);
    }

    /** True if the message asks for nothing but putting a workout on the plan. */
    public static function isSaveRequest(string $content): bool
    {
        $sources = explode('+', (new PlanChangeIntentClassifier)->classify($content)->basis);

        return array_intersect($sources, [PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, 'add_workout']) !== []
            && array_diff($sources, [PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, 'add_workout']) === [];
    }

    /**
     * The request this message continues, with the user's words since then that belong to it, or null if it continues
     * nothing.
     *
     * @param  Collection<int, Message>  $history  the conversation up to and including the user's current message
     * @return array{kind: string, request: string, message: string, reason?: string, made_on?: string}|null `message` is the request and every answer since, as one text
     */
    public function continuation(string $userMessage, Collection $history, User $user): ?array
    {
        $before = $history->slice(0, -1)->values();
        $previous = $before->last();

        // ANSWER: the application's own question is the message just before this one. A message that does not answer
        // it may still be a retry, below; anything else continues nothing.
        if ($previous?->role === Message::ROLE_ASSISTANT && is_array($previous->meta['save_pending'] ?? null)) {
            if (self::answers($previous->meta['save_pending'], $userMessage)) {
                $found = $this->requestBefore($before, $before->count() - 1);

                if ($found !== null) {
                    if (($stale = self::stale($found['at'], $user)) !== null) {
                        return self::expired($found, $stale, $userMessage, $user);
                    }

                    // The day the user gives now is the day; the one in the request it replaces (or the earlier answer) is not a second day.
                    $dayAnswer = ($previous->meta['save_pending']['kind'] ?? null) === 'day';
                    $request = $dayAnswer ? SaveWorkoutDay::without($found['request']) : $found['request'];
                    $earlier = $dayAnswer ? array_map(SaveWorkoutDay::without(...), $found['answers']) : $found['answers'];

                    return ['kind' => self::ANSWER, 'request' => $found['request'], 'message' => self::join($request, [...$earlier, $userMessage])];
                }
            }
        }

        // RETRY: an earlier request is still open.
        if (self::isRetryPhrase($userMessage)) {
            $found = $this->openRequest($before);

            if ($found !== null) {
                if (($stale = self::stale($found['at'], $user)) !== null) {
                    return self::expired($found, $stale, $userMessage, $user);
                }

                return ['kind' => self::RETRY, 'request' => $found['request'], 'message' => self::join($found['request'], [...$found['answers'], $userMessage])];
            }
        }

        return null;
    }

    /**
     * Why a request made at $at can no longer be continued, or null if it can: it is older than a confirmation lasts, or
     * it was made on another calendar day for the user (so "today" would now mean a different day).
     */
    private static function stale(?CarbonInterface $at, User $user): ?string
    {
        if ($at === null) {
            return self::TOO_OLD; // unknown age is not fresh
        }

        $now = $user->localNow();

        if ($now->getTimestamp() - $at->getTimestamp() > PlanReplacementProposal::TTL_MINUTES * 60) {
            return self::TOO_OLD;
        }

        return $at->copy()->setTimezone($now->getTimezone())->toDateString() === $now->toDateString() ? null : self::NEW_DAY;
    }

    /** @param array{request: string, answers: array<int, string>, at: ?CarbonInterface} $found */
    private static function expired(array $found, string $reason, string $userMessage, User $user): array
    {
        // The day as the user lived it, not the server's.
        $madeOn = $found['at']?->copy()->setTimezone($user->localNow()->getTimezone())->toDateString() ?? '';

        return ['kind' => self::EXPIRED, 'request' => $found['request'], 'message' => $userMessage, 'reason' => $reason, 'made_on' => $madeOn];
    }

    /** Whether $message is a real answer to what the application asked ($pending is what it stored). */
    public static function answers(array $pending, string $message): bool
    {
        if (mb_strlen($message) > 200 || str_contains($message, '?')) {
            return false;
        }

        return match ($pending['kind'] ?? null) {
            'day' => SaveWorkoutDay::names($message),
            'choice' => SavedWorkoutReference::selectsAny(is_array($pending['options'] ?? null) ? $pending['options'] : [], $message),
            'which_workout' => SavedWorkoutReference::picks($message),
            // Nothing could be found to save, or what was found could not be used: the answer is the workout itself.
            'none', 'incomplete', 'quoted' => SavedWorkoutReference::items($message) !== [],
            default => false,
        };
    }

    /**
     * The request that the application's question at $index was about, and the answers given to its earlier questions.
     *
     * @param  Collection<int, Message>  $before
     * @return array{request: string, answers: array<int, string>, at: ?CarbonInterface}|null
     */
    private function requestBefore(Collection $before, int $index): ?array
    {
        $answers = [];
        $i = $index - 1; // the application's question is at $index

        while ($i >= 0) {
            $message = $before[$i];

            if ($message->role !== Message::ROLE_USER) {
                return null;
            }

            if (self::isSaveRequest($message->content)) {
                return ['request' => $message->content, 'answers' => array_reverse($answers), 'at' => $message->created_at];
            }

            // An earlier answer or retry: the application's earlier question comes just before it.
            $answers[] = $message->content;
            $prior = $before[$i - 1] ?? null;

            if ($prior?->role === Message::ROLE_ASSISTANT && is_array($prior->meta['save_pending'] ?? null)) {
                $i -= 2;

                continue;
            }

            // A retry: its request is whatever was still open before it.
            if (self::isRetryPhrase($message->content)) {
                $open = $this->openRequest($before->slice(0, $i)->values());

                return $open === null ? null : ['request' => $open['request'], 'answers' => [...$open['answers'], ...array_reverse($answers)], 'at' => $open['at']];
            }

            return null;
        }

        return null;
    }

    /**
     * The newest earlier request to save a workout that is still open: no workout was saved after it, and it is within
     * the conversation window. Answers the user gave to the application's questions about it come along.
     *
     * @param  Collection<int, Message>  $before
     * @return array{request: string, answers: array<int, string>, at: ?CarbonInterface}|null
     */
    private function openRequest(Collection $before): ?array
    {
        $answers = [];

        for ($i = $before->count() - 1; $i >= 0; $i--) {
            $message = $before[$i];

            if ($message->role === Message::ROLE_ASSISTANT) {
                if (self::savedSomething($message)) {
                    return null; // it went through: there is nothing left to retry
                }

                continue;
            }

            if (self::isSaveRequest($message->content)) {
                return ['request' => $message->content, 'answers' => array_reverse($answers), 'at' => $message->created_at];
            }

            // Only the user's answers to the application's own questions about that request belong to it.
            $prior = $before[$i - 1] ?? null;

            if ($prior?->role === Message::ROLE_ASSISTANT && is_array($prior->meta['save_pending'] ?? null)) {
                $answers[] = $message->content;
            }
        }

        return null;
    }

    /** A reply in which a plan was actually written (a successful tool call, or a confirmed replacement). */
    private static function savedSomething(Message $reply): bool
    {
        if (($reply->meta['plan_replacement'] ?? null) === 'applied') {
            return true;
        }

        foreach ($reply->meta['tool_calls'] ?? [] as $call) {
            if (($call['name'] ?? null) === 'create_workout_plan' && is_array($call['result'] ?? null) && ! array_key_exists('error', $call['result'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $answers */
    private static function join(string $request, array $answers): string
    {
        return trim($request."\n".implode("\n", $answers));
    }
}
