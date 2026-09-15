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

        try {
            $assistantMessage = $this->coachService->respond(
                $request->user(),
                $conversation,
                $request->string('content')->toString(),
            );
        } catch (AiProviderException $e) {
            return response()->json(['message' => $e->userFacingMessage()], 503);
        }

        $conversation->touch();

        return new MessageResource($assistantMessage);
    }
}
