<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\AiProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConversationController extends Controller
{
    public function __construct(private readonly AiCoachService $coachService) {}

    public function index(Request $request)
    {
        return ConversationResource::collection(
            $request->user()->conversations()->orderByDesc('updated_at')->get()
        );
    }

    public function store(Request $request)
    {
        $conversation = $request->user()->conversations()->create([
            'title' => $request->string('title')->toString() ?: null,
        ]);

        return new ConversationResource($conversation);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        return MessageResource::collection($conversation->messages()->get());
    }

    public function sendMessage(StoreMessageRequest $request, Conversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        // One turn per conversation at a time. Two overlapping turns would
        // each be built from a history that's missing the other's messages,
        // and could persist their replies out of order — so a second request
        // is turned away instead of racing. The TTL outlives the longest
        // possible turn (see set_time_limit in AiCoachService::respond) so a
        // crashed request can't leave the conversation locked.
        $lock = Cache::lock("conversation-turn:{$conversation->id}", 210);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Your coach is still working on your previous message — please wait for the reply first.',
            ], 409);
        }

        try {
            $assistantMessage = $this->coachService->respond(
                $request->user(),
                $conversation,
                $request->string('content')->toString(),
            );
        } catch (AiProviderException $e) {
            return response()->json(['message' => $e->userFacingMessage()], 503);
        } finally {
            $lock->release();
        }

        $conversation->touch();

        return new MessageResource($assistantMessage);
    }
}
