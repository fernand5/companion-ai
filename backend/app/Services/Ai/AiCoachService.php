<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates one chat turn end to end (spec §23): save the user message,
 * build compact context, call the LLM with tools, execute any tool calls the
 * model requests (authorized/scoped to $user), loop until the model produces
 * a final answer, persist it, and return it.
 */
class AiCoachService
{
    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly FitnessContextBuilder $contextBuilder,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {}

    public function respond(User $user, Conversation $conversation, string $userMessage): Message
    {
        // The tool-calling loop can make several sequential Gemini calls (each
        // individually capped at 20s in GeminiProvider), which can cumulatively
        // exceed PHP's default script execution limit — that kills the request
        // with a hard connection drop instead of the graceful AiProviderException
        // handling below. Raise it for just this operation (comfortably above
        // the worst case: max_tool_iterations x 20s + one forced final call).
        set_time_limit(200);

        $userMessageRecord = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $userMessage,
        ]);

        try {
            return $this->generateReply($user, $conversation, $userMessage);
        } catch (AiProviderException $e) {
            // The model was never reached, so nothing meaningful happened this
            // turn — remove the orphaned user message rather than leaving a
            // question with no answer sitting in the conversation.
            $userMessageRecord->delete();

            throw $e;
        }
    }

    private function generateReply(User $user, Conversation $conversation, string $userMessage): Message
    {
        $context = $this->contextBuilder->build($user, $userMessage, $conversation);
        $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context));

        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        $llmMessages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $message) {
            $llmMessages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $tools = $this->toolRegistry->declarations();
        $toolTrace = [];
        $finalText = null;
        $maxIterations = (int) config('ai.max_tool_iterations', 4);

        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $this->aiProvider->chat($llmMessages, $tools);

            if (! $response->hasToolCalls()) {
                // A model that just finished a string of tool calls sometimes
                // returns no closing text at all (empty string, not null) —
                // treat that the same as "no answer yet" so the fallback below
                // forces one more call asking it to summarize what it did,
                // rather than persisting a blank assistant bubble.
                if ($response->text !== null && trim($response->text) !== '') {
                    $finalText = $response->text;
                }

                break;
            }

            $llmMessages[] = [
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => $response->toolCalls,
            ];

            foreach ($response->toolCalls as $toolCall) {
                $result = $this->toolRegistry->call($toolCall->name, $toolCall->arguments, $user);

                $llmMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => $result,
                ];

                $toolTrace[] = [
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->arguments,
                    'result' => $result,
                ];
            }
        }

        if ($finalText === null) {
            $response = $this->aiProvider->chat($llmMessages, []);
            $finalText = $response->text ?? "Here's what I found from your recent activity and schedule — let me know if you'd like more detail.";
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $finalText,
            'meta' => $toolTrace !== [] ? ['tool_calls' => $toolTrace] : null,
        ]);

        if (config('ai.debug')) {
            Log::channel('ai_debug')->info('AI coach turn', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'user_message' => $userMessage,
                'context' => $context,
                'tools_available' => array_column($tools, 'name'),
                'tool_calls' => $toolTrace,
                'final_response' => $finalText,
            ]);
        }

        return $assistantMessage;
    }

    /**
     * A short, standalone recommendation for the dashboard widget — not tied to
     * any conversation, and not persisted as a message. Uses the same compact
     * context as a real chat turn but skips the tool loop to stay fast/cheap.
     *
     * Cached for the rest of the calendar day per user: the dashboard calls
     * this on every page load, and without caching that means a fresh Gemini
     * call on every refresh — burns quota for a value that isn't going to
     * meaningfully change between refreshes made minutes apart.
     */
    public function dashboardRecommendation(User $user): string
    {
        $cacheKey = "dashboard_recommendation:{$user->id}:".now()->toDateString();

        return Cache::remember($cacheKey, now()->endOfDay(), function () use ($user) {
            set_time_limit(60);

            $context = $this->contextBuilder->build($user, 'What should I do today?');
            $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context));

            // No tools are passed to this call (kept fast/cheap, not persisted),
            // but the shared system prompt still instructs the model to call
            // create_workout_plan whenever it makes a recommendation. Without
            // an override it complies by writing the tool call out as literal
            // text instead, which would otherwise leak into the widget.
            $systemPrompt .= "\n\nNote: no tools are available for this particular response — you "
                .'cannot call create_workout_plan or any other tool here. Do not attempt to call one, '
                .'describe one, or output any function-call syntax; just answer in plain prose.';

            $response = $this->aiProvider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'In 2-3 short sentences, what should I do today and why?'],
            ]);

            return $response->text ?? "Log today's activity and I'll tailor a recommendation.";
        });
    }
}
