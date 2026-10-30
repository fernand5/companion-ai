<?php

namespace App\Services\Ai\Authorization;

/**
 * Which workout "this workout" is, as far as the conversation can establish it: either exactly one routine
 * (its text and the exercise lines in it), or the reason it cannot be established. Never a guess.
 */
final class WorkoutReference
{
    /** Exactly one routine, from the user's own message or the reply just before it. */
    public const FOUND = 'found';

    /** Nothing in the conversation can be saved: no routine, or not in the reply the user is pointing at. */
    public const NONE = 'none';

    /** More than one routine, and the user's words do not pick one. */
    public const AMBIGUOUS = 'ambiguous';

    /** A routine that is visibly unfinished (cut off, "etc.", fewer exercises than it announces). */
    public const INCOMPLETE = 'incomplete';

    /** A routine somebody else wrote, quoted: nothing shows the user means to adopt it. */
    public const QUOTED = 'quoted';

    /** One routine, but an exercise in it is "A or B" and the user has not said which. */
    public const CHOICE = 'choice';

    public const FROM_MESSAGE = 'message';

    public const FROM_PREVIOUS_REPLY = 'previous_reply';

    /** @param array<int, string> $items one string per exercise line of the routine */
    private function __construct(
        public readonly string $status,
        public readonly string $source = '',
        public readonly array $items = [],
        public readonly ?string $origin = null,
        public readonly array $choices = [],
    ) {}

    /** @param array<int, string> $items */
    public static function found(string $source, array $items, string $origin): self
    {
        return new self(self::FOUND, $source, $items, $origin);
    }

    public static function unresolved(string $status): self
    {
        return new self($status);
    }

    /**
     * @param  array<int, array{item: string, options: array<int, string>}>  $choices  each exercise that is "A or B"
     */
    public static function needsChoice(array $choices): self
    {
        return new self(self::CHOICE, choices: $choices);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
