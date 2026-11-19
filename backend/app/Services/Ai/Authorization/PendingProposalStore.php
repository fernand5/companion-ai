<?php

namespace App\Services\Ai\Authorization;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Where a proposal waits for its confirmation: in the `meta` of the assistant
 * message that showed it to the user. Only server code writes message meta (the only
 * client-supplied field on a message is its text), and the proposal is signed, so it
 * cannot be edited from outside.
 *
 * Consuming it is a single conditional UPDATE ("set consumed_at where consumed_at is
 * still absent"), which the database applies atomically: of any number of concurrent
 * confirmations exactly one changes a row and receives the proposal; the rest get
 * nothing. A confirmation that is expired, for another user or conversation, forged,
 * or already used grants nothing and leaves nothing behind that could be used later.
 */
class PendingProposalStore
{
    public const META_KEY = 'pending_plan_proposal';

    /** A whole-day replacement waiting for its confirmation (see PlanReplacementProposal). */
    public const REPLACEMENT_META_KEY = 'pending_plan_replacement';

    /** The meta to store on the assistant message that presents $proposal. */
    public function present(PlanChangeProposal $proposal): array
    {
        return [self::META_KEY => $proposal->toMeta()];
    }

    /**
     * Redeem the proposal presented by $previous for this user's "yes" in $conversation.
     * $previous is the message as the caller read it (it may be stale, which is the
     * point: the conditional update decides, not that read).
     */
    public function claim(Conversation $conversation, User $user, Message $previous, ?CarbonImmutable $now = null): ?PlanChangeProposal
    {
        if ($conversation->user_id !== $user->id
            || $previous->conversation_id !== $conversation->id
            || $previous->role !== Message::ROLE_ASSISTANT) {
            return null;
        }

        $raw = $previous->meta[self::META_KEY] ?? null;

        if (! is_array($raw) || array_key_exists('consumed_at', $raw)) {
            return null;
        }

        $proposal = PlanChangeProposal::fromMeta($raw);
        $now ??= CarbonImmutable::now();

        if ($proposal === null
            || ! $proposal->isAuthentic()
            || $proposal->userId !== $user->id
            || $proposal->conversationId !== $conversation->id
            || $proposal->isExpired($now)) {
            return null;
        }

        return $this->consume($conversation, $previous, self::META_KEY, $now) ? $proposal : null;
    }

    /**
     * Whether $holder still presents a proposal (under $key) that a clear "yes" from this user, in this conversation, could
     * redeem right now: authentic, this user's, this conversation's, unexpired and unused. Read-only: it spends nothing and
     * extends nothing, so asking the user to clarify an ambiguous answer leaves the proposal exactly as it was, and a
     * proposal that has expired or been used is never described as "still open".
     */
    public function isOpen(Conversation $conversation, User $user, ?Message $holder, string $key, ?CarbonImmutable $now = null): bool
    {
        if ($holder === null || $conversation->user_id !== $user->id || $holder->conversation_id !== $conversation->id || $holder->role !== Message::ROLE_ASSISTANT) {
            return false;
        }

        $raw = $holder->meta[$key] ?? null;

        if (! is_array($raw) || array_key_exists('consumed_at', $raw)) {
            return false;
        }

        $proposal = $key === self::REPLACEMENT_META_KEY ? PlanReplacementProposal::fromMeta($raw) : PlanChangeProposal::fromMeta($raw);

        return $proposal !== null
            && $proposal->isAuthentic()
            && $proposal->userId === $user->id
            && $proposal->conversationId === $conversation->id
            && ! $proposal->isExpired($now ?? CarbonImmutable::now());
    }

    /**
     * What the client may know about the replacement question $message holds, decided here by the server at the moment of
     * reading and never from the wording of the message: null when it holds none; otherwise whether an answer sent now
     * could redeem it ("actionable": valid, unexpired, unused, this user's and conversation's, AND the question the
     * conversation is waiting on, not an old one), and when it lapses. Read-only; nothing is spent or extended.
     *
     * @return array{kind: string, actionable: bool, expires_at: ?string}|null
     */
    public function replacementState(Conversation $conversation, User $user, Message $message): ?array
    {
        $raw = $message->meta[self::REPLACEMENT_META_KEY] ?? null;

        if ($message->role !== Message::ROLE_ASSISTANT || ! is_array($raw)) {
            return null;
        }

        $latest = $conversation->messages()->reorder('created_at', 'desc')->orderByDesc('id')->first();
        $target = $latest?->id;

        if ($latest?->role === Message::ROLE_ASSISTANT && is_int($latest->meta['confirms_message_id'] ?? null)) {
            $target = $latest->meta['confirms_message_id'];
        }

        $proposal = PlanReplacementProposal::fromMeta($raw);

        return [
            'kind' => 'replacement',
            'actionable' => $target === $message->id && $this->isOpen($conversation, $user, $message, self::REPLACEMENT_META_KEY),
            'expires_at' => $proposal?->isAuthentic() ? CarbonImmutable::parse($proposal->expiresAt)->toIso8601String() : null,
        ];
    }

    /** The meta to store on the assistant message that presents $proposal. */
    public function presentReplacement(PlanReplacementProposal $proposal): array
    {
        return [self::REPLACEMENT_META_KEY => $proposal->toMeta()];
    }

    /** True when $message is waiting for a confirmation of a whole-day replacement (used or not). */
    public function presentsReplacement(?Message $message): bool
    {
        return $message?->role === Message::ROLE_ASSISTANT && is_array($message->meta[self::REPLACEMENT_META_KEY] ?? null);
    }

    /**
     * Redeem the replacement presented by $previous for this user's "yes". Same rules as claim(): the
     * proposal must be authentic, this user's, this conversation's, unexpired and unused, and using it is one
     * atomic conditional update.
     */
    public function claimReplacement(Conversation $conversation, User $user, Message $previous, ?CarbonImmutable $now = null): ?PlanReplacementProposal
    {
        if ($conversation->user_id !== $user->id
            || $previous->conversation_id !== $conversation->id
            || $previous->role !== Message::ROLE_ASSISTANT) {
            return null;
        }

        $raw = $previous->meta[self::REPLACEMENT_META_KEY] ?? null;

        if (! is_array($raw) || array_key_exists('consumed_at', $raw)) {
            return null;
        }

        $proposal = PlanReplacementProposal::fromMeta($raw);
        $now ??= CarbonImmutable::now();

        if ($proposal === null
            || ! $proposal->isAuthentic()
            || $proposal->userId !== $user->id
            || $proposal->conversationId !== $conversation->id
            || $proposal->isExpired($now)) {
            return null;
        }

        return $this->consume($conversation, $previous, self::REPLACEMENT_META_KEY, $now) ? $proposal : null;
    }

    /** "Set consumed_at where it is still absent": the database applies it atomically, so exactly one caller wins. */
    private function consume(Conversation $conversation, Message $previous, string $key, CarbonImmutable $now): bool
    {
        return Message::query()
            ->whereKey($previous->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', Message::ROLE_ASSISTANT)
            ->whereNull('meta->'.$key.'->consumed_at')
            ->update(['meta->'.$key.'->consumed_at' => $now->toIso8601String()]) === 1;
    }
}
