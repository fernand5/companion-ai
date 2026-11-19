<?php

namespace App\Services\Ai\Notices;

use App\Models\Message;

/**
 * Reads back which part of an earlier assistant message the MODEL wrote, so that the application's own words under it
 * (its questions, examples, dates and quoted workouts) are never taken for a workout, a request to save one, or any
 * kind of evidence that something was asked or authorized.
 *
 * Three cases, and the language of the text never decides any of them:
 *
 *  1. The message carries valid structured notices (see ReplyText): the model's words are the text outside the
 *     recorded segments.
 *  2. The message carries notices that do not verify (wrong shape, offsets out of bounds, overlapping or unsorted,
 *     a length or hash that does not match the content): FAIL CLOSED. Nothing in it is read as the coach's workout.
 *  3. The message has no notice record at all (every reply stored before this existed): the historical rule. The
 *     application appended its notices after a blank line, each beginning with one of the sentences listed below, and
 *     everything from the first of them on is cut off. A reply the application wrote whole and marked with
 *     meta.plan_replacement (the result of a confirmation) holds no coach text.
 *
 * Only the model's words are ever returned, never more of the content than there was: this can narrow what a later
 * turn reads and cannot add to it. Only an assistant message is filtered: what a user wrote is theirs, and a user
 * message cannot carry notices (a request cannot set a message's meta).
 */
final class ApplicationNotices
{
    /** The most segments one message may carry; a reply has a handful. */
    private const MAX_SEGMENTS = 16;

    /**
     * How the application's own English notices began when it appended them under a reply, before they were
     * recorded. They are fixed sentences the application wrote itself, so matching their start is exact; this list is
     * closed (new notices are recorded structurally and need no entry here).
     */
    public const LEGACY_PREFIXES = [
        "I haven't changed your plan",
        'There is nothing waiting for your confirmation',
        'I left the starting weight',
        'As you asked, I replaced',
        'Your plan changed since I asked',
        // Appended too, but missing from the first version of this list:
        'Your plan keeps one number for each of these',
        'To be clear, that is not a limit of the app',
        "I couldn't make the change exactly as you confirmed it",
        'Everything in that plan',
    ];

    /** What the model wrote in this assistant message ('' when none of it can be trusted to be the coach's). */
    public static function modelText(Message $message): string
    {
        $content = (string) $message->content;

        if ($message->role !== Message::ROLE_ASSISTANT) {
            return $content;
        }

        $meta = is_array($message->meta) ? $message->meta : [];

        if (! array_key_exists(ReplyText::META_KEY, $meta)) {
            return self::legacyModelText($content, $meta);
        }

        $segments = self::verifiedSegments($content, $meta[ReplyText::META_KEY]);

        if ($segments === null) {
            return '';
        }

        $kept = '';
        $at = 0;

        foreach ($segments as $segment) {
            $kept .= mb_substr($content, $at, $segment['start'] - $at);
            $at = $segment['end'];
        }

        return $kept.mb_substr($content, $at);
    }

    /**
     * Messages stored before notices were recorded.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function legacyModelText(string $content, array $meta): string
    {
        // The result of a confirmation, and a plan saved whole, are the application's words from the first character.
        if (array_key_exists('plan_replacement', $meta)) {
            return '';
        }

        $cut = null;

        foreach (self::LEGACY_PREFIXES as $prefix) {
            $at = mb_strpos($content, "\n\n".$prefix);

            if ($at !== false && ($cut === null || $at < $cut)) {
                $cut = $at;
            }
        }

        return $cut === null ? $content : mb_substr($content, 0, $cut);
    }

    /**
     * The recorded segments if, and only if, they verify against this content; otherwise null.
     *
     * @return array<int, array{code: string, start: int, end: int}>|null
     */
    private static function verifiedSegments(string $content, mixed $raw): ?array
    {
        if (! is_array($raw) || ($raw['v'] ?? null) !== ReplyText::VERSION || ! mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $length = mb_strlen($content);

        if (($raw['length'] ?? null) !== $length || ! is_string($raw['sha256'] ?? null) || ! hash_equals(hash('sha256', $content), $raw['sha256'])) {
            return null;
        }

        $segments = $raw['segments'] ?? null;

        if (! is_array($segments) || ! array_is_list($segments) || $segments === [] || count($segments) > self::MAX_SEGMENTS) {
            return null;
        }

        $verified = [];
        $previousEnd = 0;

        foreach ($segments as $segment) {
            if (! is_array($segment) || ! NoticeCode::isWellFormed($segment['code'] ?? null)) {
                return null;
            }

            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;

            // Integers only, inside the content, in order, not overlapping, not empty.
            if (! is_int($start) || ! is_int($end) || $start < $previousEnd || $end <= $start || $end > $length) {
                return null;
            }

            $verified[] = ['code' => $segment['code'], 'start' => $start, 'end' => $end];
            $previousEnd = $end;
        }

        return $verified;
    }
}
