<?php

namespace App\Services\Ai\Notices;

/**
 * An assistant reply as it is built: the model's own words first, then whatever the application adds under them
 * (what was and was not saved, a question it asks, a correction). Each addition is recorded as a notice with a
 * stable code and where it sits in the text, so a later turn can tell the model's words (which may hold a workout)
 * from the application's (which never do), without reading a single word of either.
 *
 * What is stored, in messages.meta under META_KEY:
 *
 *     {"v": 1, "length": N, "sha256": "...", "segments": [{"code": "...", "start": S, "end": E}, ...]}
 *
 *  - Offsets are counted in Unicode code points (mb_substr), never bytes. A segment starts at the blank line that
 *    separates the notice from what came before it, and ends where the next one starts (or at the end), so the text
 *    outside every segment is exactly what the model wrote. The offsets come from the text the application just built,
 *    so they cannot fall inside a character it wrote; the reader (ApplicationNotices) checks them anyway.
 *  - "length" and "sha256" bind the record to this exact content: copied to another message, or kept after the
 *    content was changed, it no longer verifies and the reader fails closed.
 *  - A reply the application wrote entirely (a question, a confirmation result) is one segment from 0 to the end.
 *
 * Only the server builds this; a request cannot set a message's meta.
 */
final class ReplyText
{
    public const META_KEY = 'application_notices';

    public const VERSION = 1;

    /** @var array<int, array{code: string, start: int}> */
    private array $notices = [];

    private function __construct(private string $text) {}

    /** The model's reply, with no notices yet. */
    public static function fromModel(string $text): self
    {
        return new self($text);
    }

    /** A reply the application wrote in full. */
    public static function application(string $code, string $text): self
    {
        $reply = new self($text);
        $reply->notices[] = ['code' => $code, 'start' => 0];

        return $reply;
    }

    /** Put the application's own text under what is there, after a blank line, and record it. */
    public function with(string $code, string $notice): self
    {
        if (trim($notice) === '') {
            return $this; // nothing to say, so nothing to record
        }

        $this->text = rtrim($this->text);
        $start = mb_strlen($this->text);
        $this->text .= "\n\n".$notice;
        $this->notices[] = ['code' => $code, 'start' => $start];

        return $this;
    }

    /** Replace the whole reply with something the application wrote (the model's words are dropped). */
    public function replaceWith(string $code, string $text): self
    {
        $this->text = $text;
        $this->notices = [['code' => $code, 'start' => 0]];

        return $this;
    }

    /** Trailing whitespace off the end, as a reply is stored. */
    public function trimEnd(): self
    {
        $this->text = rtrim($this->text);

        return $this;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** Only what the model wrote: everything before the first notice. */
    public function modelText(): string
    {
        if ($this->notices === []) {
            return $this->text;
        }

        return mb_substr($this->text, 0, $this->notices[0]['start']);
    }

    /** @param  array<int, string>  $codes */
    public function hasAny(array $codes): bool
    {
        foreach ($this->notices as $notice) {
            if (in_array($notice['code'], $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return array_column($this->notices, 'code');
    }

    /** The meta to store with this reply: empty when the application added nothing. */
    public function meta(): array
    {
        if ($this->notices === []) {
            return [];
        }

        $length = mb_strlen($this->text);
        $segments = [];

        foreach ($this->notices as $i => $notice) {
            $segments[] = [
                'code' => $notice['code'],
                'start' => $notice['start'],
                'end' => $this->notices[$i + 1]['start'] ?? $length,
            ];
        }

        return [self::META_KEY => [
            'v' => self::VERSION,
            'length' => $length,
            'sha256' => hash('sha256', $this->text),
            'segments' => $segments,
        ]];
    }
}
