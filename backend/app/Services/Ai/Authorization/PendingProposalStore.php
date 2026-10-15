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

        $consumed = Message::query()
            ->whereKey($previous->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', Message::ROLE_ASSISTANT)
            ->whereNull('meta->'.self::META_KEY.'->consumed_at')
            ->update(['meta->'.self::META_KEY.'->consumed_at' => $now->toIso8601String()]);

        return $consumed === 1 ? $proposal : null;
    }
}
