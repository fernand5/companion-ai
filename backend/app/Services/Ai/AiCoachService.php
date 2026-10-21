<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Authorization\GuardedToolExecutor;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Authorization\PlanChangeProposalParser;
use App\Services\Ai\Authorization\ProposalMatcher;
use App\Services\Ai\Authorization\ToolAuthorization;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\WeeklyPlanService;
use Illuminate\Support\Collection;
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
        private readonly GuardedToolExecutor $toolExecutor,
        private readonly PlanChangeIntentClassifier $intentClassifier,
        private readonly PendingProposalStore $proposals,
        private readonly PlanChangeProposalParser $proposalParser,
        private readonly ProposalMatcher $proposalMatcher,
        private readonly FitnessContextBuilder $contextBuilder,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly WeeklyPlanService $weeklyPlanService,
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

        $startedAt = microtime(true);

        $userMessageRecord = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $userMessage,
        ]);

        try {
            return $this->generateReply($user, $conversation, $userMessage);
        } catch (AiProviderException $e) {
            Log::warning('ai_coach.turn_failed', [
                'conversation_id' => $conversation->id,
                'user_message_id' => $userMessageRecord->id,
                'provider' => config('ai.provider'),
                'model' => config('ai.model'),
                'error_type' => $e->userFacingReason(),
                'status_code' => $e->statusCode,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // The model was never reached, so nothing meaningful happened this
            // turn — remove the orphaned user message rather than leaving a
            // question with no answer sitting in the conversation.
            $userMessageRecord->delete();

            throw $e;
        }
    }

    private function generateReply(User $user, Conversation $conversation, string $userMessage): Message
    {
        $startedAt = microtime(true);

        // Ordinary chat needs to see the whole week (not just today) so it can
        // reason about whether a historical activity the user just reported
        // should affect an upcoming day, not only today's plan.
        $weekRange = $this->weeklyPlanService->weekRange($user);
        $context = $this->contextBuilder->build($user, $userMessage, $conversation, includeWeekPlan: true, weekRange: $weekRange, includePerformance: true);
        $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context));

        // The reading rules are only worth their tokens when there is history to read.
        if (($context['performance'] ?? '') !== '') {
            $systemPrompt .= $this->promptBuilder->performanceInterpretationAddendum();
        }

        // Conversation::messages() carries a baked-in ->orderBy('created_at')
        // (ascending — correct for the frontend's own GET .../messages, which
        // uses this same relation to display the thread in order). Appending
        // ->orderByDesc('created_at') on top of that does NOT override it —
        // Eloquent appends order clauses rather than replacing them, so the
        // relation's ascending clause silently wins and this call was a
        // no-op. That meant this query actually returned messages OLDEST
        // first, and the .reverse() below then flipped that into NEWEST
        // first — planting the brand-new current message second in the
        // array (right after the system prompt) with older turns AFTER it,
        // ending on whatever the oldest retained message happened to be.
        // Confirmed against a real conversation: the model was handed the
        // transcript back-to-front, with the actual latest user message
        // buried instead of last — which alone explains a reply that
        // ignores new input and continues an old thread. reorder() clears
        // the inherited clause so this query's own order actually applies;
        // `id` remains as a deterministic tiebreaker for same-second ties.
        $history = $conversation->messages()
            ->reorder('created_at', 'desc')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        // What this turn may change is decided here, from the user's own message, before the
        // model is called. Nothing the model says or any tool returns can widen it. A bare
        // "yes" only counts if it redeems a proposal the application itself presented to this
        // user in this conversation (and then only for exactly that change).
        $authorization = $this->authorize($user, $conversation, $userMessage, $history);
        $turn = $this->toolExecutor->begin($authorization);
        $withheld = array_values(array_intersect(['create_workout_plan', 'propose_weekly_plan_changes', 'update_exercise_status'], $turn->withheldTools()));

        // The base prompt tells the model to call these tools; without a word here it would
        // write the call out as text instead (as the dashboard note below explains).
        if ($withheld !== []) {
            $systemPrompt .= "\n\nNote: ".implode(', ', $withheld).' '.(count($withheld) === 1 ? 'is' : 'are')
                .' not available for this message because the user has not asked to change their plan. '
                .'Do not call, describe or write out a call to '.(count($withheld) === 1 ? 'it' : 'them').'; answer in plain text. '
                .'A suggestion is fine, but say you have not changed anything; the user can ask you to update their plan.';
        }

        if (($proposal = $authorization->scopedProposal()) !== null) {
            $systemPrompt .= "\n\nThe user just confirmed exactly one change: ".$proposal->describe().'. Make that change and nothing else: call create_workout_plan once, '
                .'with exactly these planned_date, activity_type, title, duration_minutes and exercises, plus your own decision_summary and reasoning_factors: '
                .json_encode($this->proposalMatcher->expectedArguments($user, $proposal)).'. Any other change will be refused.';
        }

        $llmMessages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $message) {
            $llmMessages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $tools = $turn->declarations();
        $toolTrace = [];
        $finalText = null;
        $providerCalls = 0;
        $maxIterations = (int) config('ai.max_tool_iterations', 4);

        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $this->aiProvider->chat($llmMessages, $tools);
            $providerCalls++;

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
                $result = $turn->call($toolCall->name, $toolCall->arguments, $user);

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

        $forcedFinalCall = $finalText === null;

        if ($forcedFinalCall) {
            $response = $this->aiProvider->chat($llmMessages, []);
            $providerCalls++;
            $finalText = $response->text ?? "Here's what I found from your recent activity and schedule — let me know if you'd like more detail.";
        }

        // The application, not the model, speaks for the plan: what was and was not changed, and
        // any confirmation it asks for, is its own wording. A model's own offer to change the plan
        // authorizes nothing.
        $meta = $toolTrace !== [] ? ['tool_calls' => $toolTrace] : [];

        if ($turn->blocked() !== []) {
            $meta['blocked_tool_calls'] = $turn->blocked();
        }

        [$notice, $pending] = $this->followUp($user, $conversation, $userMessage, $authorization, $turn->confirmedChangeMade());

        if ($notice !== null) {
            $finalText = rtrim($finalText)."\n\n".$notice;
        }

        // Loads the application would not accept from the model (nothing to base them on) are said so in its own words.
        if (($unset = $turn->loadsLeftUnsetNotice()) !== null) {
            $finalText = rtrim($finalText)."\n\n".$unset;
        }

        if ($pending !== null) {
            $meta += $this->proposals->present($pending);
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $finalText,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        // Always-on, production-safe turn summary — structured metadata only,
        // never message content, context values, or profile/fitness data, so
        // it stays safe to enable in production unlike the full ai_debug dump
        // below. Enough to audit history ordering/tool-calling behavior for a
        // reported bug without needing AI_DEBUG on for a real user.
        Log::info('ai_coach.turn', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'assistant_message_id' => $assistantMessage->id,
            'provider' => config('ai.provider'),
            'model' => config('ai.model'),
            'provider_calls' => $providerCalls,
            'forced_final_call' => $forcedFinalCall,
            'history_message_count' => $history->count(),
            'history_roles' => $history->pluck('role')->push(Message::ROLE_ASSISTANT)->all(),
            'context_sections' => array_keys($context),
            'tools_available_count' => count($tools),
            'tool_call_count' => count($toolTrace),
            'tool_names_called' => array_column($toolTrace, 'name'),
            'plan_authorization' => $authorization->basis,
            'plan_capabilities' => $authorization->grantedPlanCapabilities(),
            'blocked_tool_calls' => count($turn->blocked()),
            'response_type' => $toolTrace !== [] ? 'tool_assisted' : 'direct',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
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
     * Decide what this turn may change. A bare "yes" is redeemed against the proposal the
     * previous assistant message presented; everything else is read from the user's words.
     */
    private function authorize(User $user, Conversation $conversation, string $userMessage, Collection $history): ToolAuthorization
    {
        $previous = $history->slice(0, -1)->last();

        if (PlanChangeIntentClassifier::isBareAffirmation($userMessage) && $previous?->role === Message::ROLE_ASSISTANT) {
            $proposal = $this->proposals->claim($conversation, $user, $previous);

            if ($proposal !== null) {
                // Spent now, whether or not the change can still be made: nothing reusable is left behind.
                return is_array($this->proposalMatcher->expectedArguments($user, $proposal))
                    ? ToolAuthorization::confirmedProposal($proposal)
                    : ToolAuthorization::readOnly('confirmed_proposal_no_longer_valid', 'proposal_no_longer_valid');
            }
        }

        return $this->intentClassifier->classify($userMessage);
    }

    /**
     * The application's own closing words, and the proposal (if any) it is now asking the user to confirm.
     *
     * @return array{0: ?string, 1: ?PlanChangeProposal}
     */
    private function followUp(User $user, Conversation $conversation, string $userMessage, ToolAuthorization $authorization, bool $confirmedChangeMade): array
    {
        $explicit = 'To change your plan, send an explicit request that names the exercise, the load in kg and the day, for example "update my bench press to 45 kg on 2026-10-17".';

        if ($authorization->scopedProposal() !== null) {
            return [$confirmedChangeMade ? null : "I couldn't make the change exactly as you confirmed it, so nothing was changed. If you still want it, ask again.", null];
        }

        return match ($authorization->followUp) {
            'proposal_no_longer_valid' => ["Your plan changed since I asked, so I didn't make that change. {$explicit}", null],
            ToolAuthorization::FOLLOW_UP_NOTHING_PENDING => ["There is nothing waiting for your confirmation (it may have expired). {$explicit}", null],
            ToolAuthorization::FOLLOW_UP_EXPLICIT_REQUEST => ["I haven't changed your plan. {$explicit}", null],
            ToolAuthorization::FOLLOW_UP_PROPOSE => $this->propose($user, $conversation, $userMessage, $explicit),
            default => [null, null],
        };
    }

    /** @return array{0: string, 1: ?PlanChangeProposal} */
    private function propose(User $user, Conversation $conversation, string $userMessage, string $explicit): array
    {
        $result = $this->proposalParser->propose($user, $conversation, $userMessage);

        if ($result->proposal !== null) {
            $minutes = PlanChangeProposal::TTL_MINUTES;

            return [
                "I haven't changed your plan. Do you want me to {$result->proposal->describe()}? Reply \"yes\" within {$minutes} minutes to confirm exactly that, and nothing else. "
                .'The limit on how much one change may add only caps its size; it does not mean the load suits you.',
                $result->proposal,
            ];
        }

        if ($result->failure === 'above_maximum_change') {
            $d = $result->detail;
            $fmt = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

            return [
                "I haven't changed your plan, and I can't offer {$fmt($d['requested_kg'])} kg: one change may add at most 10% to your heaviest recorded load ({$fmt($d['recorded_kg'])} kg), which is {$fmt($d['maximum_kg'])} kg. "
                .'That is a maximum, not a recommendation, and it is not a judgment that any load up to it suits you.',
                null,
            ];
        }

        return ["I haven't changed your plan, and I couldn't turn that into one specific change to confirm. {$explicit}", null];
    }

    /**
     * Changes whenever anything the recommendation is written from (activity,
     * plans, recovery check-ins, schedule, profile) is added, edited or
     * removed. Without it the day-long cache below would keep serving a
     * recommendation written before the user logged the activity that
     * invalidates it — the same "answering an old situation" symptom as a
     * stale chat reply. Costs a handful of indexed aggregate queries per
     * dashboard load, far cheaper than the model call it protects.
     */
    private function dashboardInputsFingerprint(User $user): string
    {
        $parts = [];

        foreach ([$user->activityLogs(), $user->workoutPlans(), $user->recoveryCheckins(), $user->trainingSchedules(), $user->fitnessProfile()] as $relation) {
            $row = $relation->toBase()->selectRaw('count(*) as c, max(updated_at) as m')->first();
            $parts[] = ($row->c ?? 0).'|'.($row->m ?? '');
        }

        return substr(md5(implode('#', $parts)), 0, 12);
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
        // Keyed and expired against the user's own local calendar day, not
        // the server's UTC clock — otherwise a user behind UTC (most of the
        // Americas) would get a cache boundary hours before their actual
        // midnight, either serving a stale recommendation into their new day
        // or invalidating mid-evening while it's still "today" for them.
        $localNow = $user->localNow();
        $cacheKey = "dashboard_recommendation:{$user->id}:".$localNow->toDateString().':'.$this->dashboardInputsFingerprint($user);

        return Cache::remember($cacheKey, $localNow->copy()->endOfDay(), function () use ($user) {
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
