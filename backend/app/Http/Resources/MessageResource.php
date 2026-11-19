<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Services\Ai\Authorization\PendingProposalStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $out = [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        // A replacement question the application is holding says so in structured form, computed now by the server: the
        // client shows buttons only when "actionable" is true, never by reading the wording of a message.
        $user = $request->user();

        if ($user !== null && is_array($this->meta[PendingProposalStore::REPLACEMENT_META_KEY] ?? null)) {
            $conversation = $this->relationLoaded('conversation') ? $this->conversation : Conversation::find($this->conversation_id);
            $out['confirmation'] = $conversation !== null && $conversation->user_id === $user->id
                ? app(PendingProposalStore::class)->replacementState($conversation, $user, $this->resource)
                : null;
        }

        return $out;
    }
}
