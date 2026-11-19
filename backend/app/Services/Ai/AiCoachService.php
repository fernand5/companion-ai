<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Authorization\Confirmation;
use App\Services\Ai\Authorization\GuardedToolExecutor;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Authorization\PlanChangeProposalParser;
use App\Services\Ai\Authorization\PlanReplacer;
use App\Services\Ai\Authorization\ProposalMatcher;
use App\Services\Ai\Authorization\SavedWorkoutReference;
use App\Services\Ai\Authorization\SaveRetry;
use App\Services\Ai\Authorization\SaveWorkoutDay;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use App\Services\Ai\Authorization\ToolAuthorization;
use App\Services\Ai\Authorization\ToolCapabilities;
use App\Services\Ai\Authorization\ToolCapability;
use App\Services\Ai\Authorization\ToolTurn;
use App\Services\Ai\Authorization\WeekPlan;
use App\Services\Ai\Authorization\WeekPlanReference;
use App\Services\Ai\Authorization\WeekSaveScope;
use App\Services\Ai\Authorization\WorkoutReference;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Notices\ApplicationNotices;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\NoticeText;
use App\Services\Ai\Notices\PlanClaims;
use App\Services\Ai\Notices\ReplyText;
use App\Services\Fitness\WeeklyPlanService;
use App\Support\AppLocale;
use App\Support\ModelFacingLocale;
use Carbon\CarbonImmutable;
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
    /** How long the dashboard card may be per response-detail setting (it is a small widget, so even "detailed" stays short). */
    private const DASHBOARD_LENGTH = [
        'concise' => '1-2 short sentences',
        'standard' => '2-3 short sentences',
        'detailed' => '3-5 short sentences',
    ];

    /**
     * The same one-line request, worded in the user's language, so the application's own request does not
     * pull the answer toward English. The system prompt's language section still decides the language.
     */
    private const DASHBOARD_LENGTH_ES = [
        'concise' => '1-2 frases cortas',
        'standard' => '2-3 frases cortas',
        'detailed' => '3-5 frases cortas',
    ];

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
        private readonly SavedWorkoutReference $workoutReference,
        private readonly PlanReplacer $planReplacer,
        private readonly SaveRetry $saveRetry,
        private readonly WeekPlanReference $weekReference,
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

        // A "yes" to a whole-day replacement the application asked about is answered by the application: it makes
        // exactly that replacement (or says why it could not) and the model is not asked to do or to describe it.
        if (($confirmed = $this->confirmReplacement($user, $conversation, $userMessage, $startedAt)) !== null) {
            return $confirmed;
        }

        // A short answer that is not clearly a yes ("si" with no accent, "maybe") confirms nothing: the application asks
        // again, in its own words, and the question it asked stays open for a clear answer.
        if (($clarified = $this->clarifyConfirmation($user, $conversation, $userMessage, $startedAt)) !== null) {
            return $clarified;
        }

        // "¿Por qué no lo agregaste?" / "why didn't you add it?" is answered from what the application actually did.
        if (($why = $this->whyNotSaved($user, $conversation, $userMessage, $startedAt)) !== null) {
            return $why;
        }

        // Ordinary chat needs to see the whole week (not just today) so it can
        // reason about whether a historical activity the user just reported
        // should affect an upcoming day, not only today's plan.
        $weekRange = $this->weeklyPlanService->weekRange($user);
        $context = $this->contextBuilder->build($user, $userMessage, $conversation, includeWeekPlan: true, weekRange: $weekRange, includePerformance: true);
        $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context), CoachingPreferences::forUser($user));

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

        // A save that cannot be pinned down (which workout? which day?) is asked about, not guessed at, and the
        // model is not asked to improvise around it.
        $words = NoticeText::for($user);

        if (($question = $this->saveQuestion($authorization, $words)) !== null) {
            return $this->answerWithoutModel($user, $conversation, $authorization, ReplyText::application(NoticeCode::SAVE_QUESTION_PREFIX.$authorization->followUp, $question['text']), ['save_clarification' => true, 'save_pending' => $question['pending']], $startedAt);
        }

        $turn = $this->toolExecutor->begin($authorization, $conversation->id);
        $withheld = array_values(array_intersect(['create_workout_plan', 'propose_weekly_plan_changes', 'update_exercise_status'], $turn->withheldTools()));

        // The base prompt tells the model to call these tools; without a word here it would
        // write the call out as text instead (as the dashboard note below explains).
        if ($withheld !== []) {
            $systemPrompt .= "\n\nNote: ".implode(', ', $withheld).' '.(count($withheld) === 1 ? 'is' : 'are')
                .' not available for this message because the user has not asked to change their plan. '
                .'Do not call, describe or write out a call to '.(count($withheld) === 1 ? 'it' : 'them').'; answer in plain text. '
                .'A suggestion is fine, but say you have not changed anything; the user can ask you to update their plan. '
                .'This is only a rule for this message and not a fault: never say you lack access to a tool, that the app cannot update plans, or that anything is unavailable or down. '
                .'You can add and change plans whenever the user asks; if it would help, tell them how to ask, for example "add this workout to my dashboard".';
        }

        // "Add this workout to my dashboard": the write is allowed (and only that write), so say exactly what to write.
        if (($scope = $authorization->saveScope) !== null) {
            $systemPrompt .= "\n\nThe user asked you to put one workout on their plan. Call create_workout_plan once, with planned_date ".$scope->date.' and exactly these exercises, each as written (same name, sets, reps and duration): '
                .$scope->describeItems().'. Do not add, drop, rename or reorder exercises and do not use another date. Do not choose or raise a weight: give a weight only if the user has a recorded or planned weight for that exact exercise. '
                .'If the tool says nothing has changed until the user confirms, tell them that; the application is asking them. If it returns any other error, tell the user what it says instead of claiming it was saved.';
        }

        // "Add this plan to my dashboard": a plan of several days, saved whole or not at all.
        if (($week = $authorization->weekScope) !== null) {
            $systemPrompt .= "\n\nThe user asked you to put a plan of several days on their plan. Call propose_weekly_plan_changes once, with a decision_summary, reasoning_factors, and changes for exactly these days and no others: "
                .$week->describe().'. Each change needs its date (as given), its action, a short reason, and for a workout a title and activity_type (one of strength, treadmill, sport, steps) plus its exercises. '
                .'Do not add, drop, rename or reorder exercises, do not change a date, and do not choose or raise a weight: give a weight only if the user has a recorded or planned weight for that exact exercise. '
                .'The application saves the days together and tells the user what was saved, or asks them to confirm; do not say it was saved yourself. If the tool returns an error, tell the user what it says.';
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
                $result = ModelFacingLocale::english(fn () => $turn->call($toolCall->name, $toolCall->arguments, $user));

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
        // authorizes nothing. Everything the application adds below the model's words is recorded as a notice
        // (a stable code and where it sits), so that a later turn can tell the two apart without reading either.
        $meta = $toolTrace !== [] ? ['tool_calls' => $toolTrace] : [];

        if ($turn->blocked() !== []) {
            $meta['blocked_tool_calls'] = $turn->blocked();
        }

        [$notice, $pending] = $this->followUp($user, $conversation, $userMessage, $authorization, $turn->confirmedChangeMade(), $history, $words);

        $reply = ReplyText::fromModel($finalText);

        // A change that needed the user's confirmation, and could not be asked for, is said not to have happened.
        if ($authorization->weekScope === null && array_filter($turn->blocked(), fn (array $b) => $b['reason'] === 'cannot_ask_for_confirmation') !== [] && $turn->pendingReplacement() === null) {
            $reply->with(NoticeCode::CANNOT_ASK_CONFIRMATION, $words->cannotAskConfirmation());
        }

        // What happened to a requested save is the application's to say, not the model's.
        if ($authorization->saveScope !== null) {
            $meta += $this->saveOutcome($reply, $turn, $authorization->saveScope, $words);
        } elseif ($authorization->weekScope !== null) {
            [$reply, $savedMeta] = $this->weekOutcome($turn, $authorization->weekScope, $words);
            $meta += $savedMeta;
        } elseif (($held = $turn->pendingReplacement()) !== null) {
            // Any other turn that ran into a day with a plan on it: the reply stays (it may be the workout the user
            // asked for), and the application adds what has NOT happened and what it needs to be told.
            $reply->with(NoticeCode::REPLACEMENT_QUESTION, $words->replacementQuestion($held));
            $meta += $this->proposals->presentReplacement($held);
        }

        if ($notice !== null) {
            $reply->with($notice['code'], $notice['text']);
        }

        // Loads the application would not accept from the model (nothing to base them on) are said so in its own words.
        if (($unset = $words->loadsLeftUnset($turn->loadsLeftUnsetNotice())) !== null) {
            $reply->with(NoticeCode::LOAD_LEFT_UNSET, $unset);
        }

        // Two things the application can check against what actually happened this turn, whatever the model wrote.
        $this->correctClaims($reply, $authorization, $turn, $toolTrace, $words);

        if ($pending !== null) {
            $meta += $this->proposals->present($pending);
        }

        $finalText = $reply->text();
        $meta += $reply->meta();

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
        $previous = $this->presenterOf($history->slice(0, -1)->last(), $conversation);

        if (PlanChangeIntentClassifier::isBareAffirmation($userMessage) && $previous?->role === Message::ROLE_ASSISTANT) {
            $proposal = $this->proposals->claim($conversation, $user, $previous);

            if ($proposal !== null) {
                // Spent now, whether or not the change can still be made: nothing reusable is left behind.
                return is_array($this->proposalMatcher->expectedArguments($user, $proposal))
                    ? ToolAuthorization::confirmedProposal($proposal)
                    : ToolAuthorization::readOnly('confirmed_proposal_no_longer_valid', 'proposal_no_longer_valid');
            }
        }

        $classified = $this->intentClassifier->classify($userMessage);
        $effective = $userMessage;
        $retry = false;

        // "could you try to add them again?", "Friday", "Goblet Squat": not requests by themselves. If they continue a
        // request to put a workout on the plan that the user made earlier in this conversation (and, for an answer, a
        // question the application asked about it), the earlier request is the authorization and these are its details.
        if (! $this->asksToSave($classified)) {
            $continued = $this->saveRetry->continuation($userMessage, $history, $user);

            // Too old, or made on another day: nothing is replayed and nothing is written; the user is told, and asks again.
            if (($continued['kind'] ?? null) === SaveRetry::EXPIRED) {
                return ToolAuthorization::readOnly('save_retry_expired', ToolAuthorization::FOLLOW_UP_RETRY_EXPIRED, ['reason' => $continued['reason'], 'made_on' => $continued['made_on']]);
            }

            if ($continued !== null) {
                $classified = $this->intentClassifier->classify($continued['request']);
                $effective = $continued['message'];
                // Whatever the earlier replies were (an apology, a false "no access", the application's own questions), they are not the workout.
                $retry = true;
            }
        }

        return $this->scopeSave($classified, $user, $conversation, $effective, $history, $retry);
    }

    private function asksToSave(ToolAuthorization $classified): bool
    {
        return array_intersect(explode('+', $classified->basis), [PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, 'add_workout']) !== [];
    }

    /**
     * "Add this workout to my dashboard" authorizes one thing: this workout on this day. When the request is only that
     * (not also a broader change the user asked for), the workout and the day are pinned down here, before the model
     * is called, from the user's words and the conversation: if either cannot be established the plan is left alone
     * and the user is asked. Only ever narrows what the classifier granted.
     *
     * @param  Collection<int, Message>  $history
     */
    private function scopeSave(ToolAuthorization $authorization, User $user, Conversation $conversation, string $userMessage, Collection $history, bool $retry = false): ToolAuthorization
    {
        $sources = explode('+', $authorization->basis);

        $saving = in_array(PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, $sources, true) || in_array('add_workout', $sources, true);

        if (! $saving || in_array('explicit_change', $sources, true) || in_array('session_request', $sources, true)) {
            return $authorization;
        }

        // A plan of several days is not one workout: its days, dates and rest days are resolved together or asked about.
        $week = $this->weekReference->resolve($userMessage, $history, CarbonImmutable::parse($user->localToday()), ! in_array(PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, $sources, true), $retry);

        if ($week->isAWeek()) {
            return $this->scopeWeek($authorization, $week, $conversation);
        }

        // "add a 30 minute run to my plan" names its own workout; "add this workout" points at one.
        $reference = $this->workoutReference->resolve($userMessage, $history, inMessageOnly: ! in_array(PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, $sources, true), retry: $retry);

        if (! $reference->isFound()) {
            return ToolAuthorization::readOnly('save_workout_'.$reference->status, match ($reference->status) {
                WorkoutReference::AMBIGUOUS => ToolAuthorization::FOLLOW_UP_WHICH_WORKOUT,
                WorkoutReference::INCOMPLETE => ToolAuthorization::FOLLOW_UP_INCOMPLETE_WORKOUT,
                WorkoutReference::QUOTED => ToolAuthorization::FOLLOW_UP_QUOTED_WORKOUT,
                WorkoutReference::CHOICE => ToolAuthorization::FOLLOW_UP_CHOOSE_EXERCISE,
                default => ToolAuthorization::FOLLOW_UP_NO_WORKOUT_TO_SAVE,
            }, ['choices' => $reference->choices]);
        }

        $day = SaveWorkoutDay::resolve($userMessage, $reference->origin === WorkoutReference::FROM_PREVIOUS_REPLY ? $reference->source : '', CarbonImmutable::parse($user->localToday()));

        if ($day['date'] === null) {
            return ToolAuthorization::readOnly('save_workout_'.$day['problem'], ToolAuthorization::FOLLOW_UP_WHICH_DAY, ['problem' => $day['problem']]);
        }

        $scope = new SaveWorkoutScope($conversation->id, $day['date'], $reference->source, $reference->items, PlanChangeIntentClassifier::statesReplacement($userMessage));

        return new ToolAuthorization(
            array_map(ToolCapability::from(...), $authorization->grantedPlanCapabilities()),
            $authorization->basis,
            saveScope: $scope,
        );
    }

    /** The authorization for "add this plan to my dashboard": exactly these days, or the question that has to be answered first. */
    private function scopeWeek(ToolAuthorization $authorization, WeekPlan $week, Conversation $conversation): ToolAuthorization
    {
        return match ($week->status) {
            WeekPlan::FOUND => new ToolAuthorization(
                [ToolCapability::PlanAdapt],
                $authorization->basis,
                weekScope: new WeekSaveScope($conversation->id, $week->days, $week->source, $week->omitted),
            ),
            WeekPlan::CHOICE => ToolAuthorization::readOnly('save_plan_choice', ToolAuthorization::FOLLOW_UP_CHOOSE_EXERCISE, ['choices' => $week->choices]),
            WeekPlan::QUOTED => ToolAuthorization::readOnly('save_plan_quoted', ToolAuthorization::FOLLOW_UP_QUOTED_WORKOUT),
            default => ToolAuthorization::readOnly('save_plan_'.$week->status, ToolAuthorization::FOLLOW_UP_WEEK_PLAN, ['problem' => $week->status, 'problems' => $week->problems, 'detail' => $week->detail]),
        };
    }

    /**
     * The application asking which workout, which exercise or which day the user means, in words that say exactly what to
     * reply, and what it stores with the question so that the reply can be recognised as the answer (see SaveRetry).
     *
     * @return array{text: string, pending: array<string, mixed>}|null
     */
    private function saveQuestion(ToolAuthorization $authorization, NoticeText $words): ?array
    {
        return match ($authorization->followUp) {
            ToolAuthorization::FOLLOW_UP_NO_WORKOUT_TO_SAVE => ['text' => $words->noWorkoutToSave(), 'pending' => ['kind' => 'none']],
            ToolAuthorization::FOLLOW_UP_WHICH_WORKOUT => ['text' => $words->whichWorkout(), 'pending' => ['kind' => 'which_workout']],
            ToolAuthorization::FOLLOW_UP_INCOMPLETE_WORKOUT => ['text' => $words->incompleteWorkout(), 'pending' => ['kind' => 'incomplete']],
            ToolAuthorization::FOLLOW_UP_QUOTED_WORKOUT => ['text' => $words->quotedWorkout(), 'pending' => ['kind' => 'quoted']],
            ToolAuthorization::FOLLOW_UP_WEEK_PLAN => $this->weekPlanQuestion($authorization->followUpData, $words),
            ToolAuthorization::FOLLOW_UP_RETRY_EXPIRED => [
                'text' => ($authorization->followUpData['reason'] ?? null) === SaveRetry::NEW_DAY
                    ? $words->retryExpiredNewDay($authorization->followUpData['made_on'] ?? null)
                    : $words->retryExpiredTooOld(),
                'pending' => ['kind' => 'expired'],
            ],
            ToolAuthorization::FOLLOW_UP_CHOOSE_EXERCISE => $this->exerciseChoiceQuestion($authorization->followUpData['choices'] ?? [], $words),
            ToolAuthorization::FOLLOW_UP_WHICH_DAY => [
                'text' => $words->whichDay(($authorization->followUpData['problem'] ?? null) === SaveWorkoutDay::PAST),
                'pending' => ['kind' => 'day'],
            ],
            default => null,
        };
    }

    /**
     * Why a plan of several days cannot be saved yet, said specifically, with the words that fix it. Which case it is
     * (and what is stored with the question) is decided here from the closed problem code; the words come from NoticeText.
     *
     * @param  array<string, mixed>  $data
     * @return array{text: string, pending: array<string, mixed>}
     */
    private function weekPlanQuestion(array $data, NoticeText $words): array
    {
        $problems = (array) ($data['problems'] ?? []);
        $detail = (string) ($data['detail'] ?? '');
        $ask = fn (string $case) => $words->weekQuestion($case, $problems, $detail, WeekPlanReference::MAX_DAYS);

        return match ($data['problem'] ?? null) {
            WeekPlan::UNCLEAR_DAY => ['text' => $ask('unclear_day'), 'pending' => ['kind' => 'week_plan']],
            WeekPlan::PAST_DAYS => ['text' => $ask('past_days'), 'pending' => ['kind' => 'week_days']],
            WeekPlan::DATES => $problems !== [] && $detail === 'the plan has no such day'
                ? ['text' => $ask('no_such_day'), 'pending' => ['kind' => 'week_days']]
                : ['text' => $ask('dates'), 'pending' => ['kind' => 'week_plan']],
            WeekPlan::TOO_MANY => ['text' => $ask('too_many'), 'pending' => ['kind' => 'week_plan']],
            default => ['text' => $ask('unreadable'), 'pending' => ['kind' => 'week_plan']],
        };
    }

    /**
     * One or more exercises in the workout are "A or B". Say so plainly (not that anything is unavailable) and ask for
     * the one to use.
     *
     * @param  array<int, array{item: string, options: array<int, string>}>  $choices
     * @return array{text: string, pending: array<string, mixed>}
     */
    private function exerciseChoiceQuestion(array $choices, NoticeText $words): array
    {
        return [
            'text' => $words->exerciseChoice($choices),
            'pending' => ['kind' => 'choice', 'options' => array_column($choices, 'options')],
        ];
    }

    /**
     * Reply without calling the model: the application's own words, stored like any assistant message.
     *
     * @param  array<string, mixed>  $meta
     */
    private function answerWithoutModel(User $user, Conversation $conversation, ToolAuthorization $authorization, ReplyText $reply, array $meta, float $startedAt): Message
    {
        $meta += $reply->meta();
        $message = $conversation->messages()->create(['role' => Message::ROLE_ASSISTANT, 'content' => $reply->text(), 'meta' => $meta !== [] ? $meta : null]);

        Log::info('ai_coach.turn', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'assistant_message_id' => $message->id,
            'provider_calls' => 0,
            'plan_authorization' => $authorization->basis,
            'plan_capabilities' => $authorization->grantedPlanCapabilities(),
            'response_type' => 'application',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $message;
    }

    /**
     * The message that presents the proposal an answer is about: the one just before it, or, when that is the
     * application's own clarification, the question it points back to. Only a message of THIS conversation is followed,
     * and the proposal stays on the message that holds it (one holder, so one use): nothing is copied.
     */
    private function presenterOf(?Message $previous, Conversation $conversation): ?Message
    {
        $target = $previous?->role === Message::ROLE_ASSISTANT ? ($previous->meta['confirms_message_id'] ?? null) : null;

        if (! is_int($target)) {
            return $previous;
        }

        return $conversation->messages()->whereKey($target)->where('role', Message::ROLE_ASSISTANT)->first() ?? $previous;
    }

    /** A reply that is not clearly a yes, to a question that is still open: the application says nothing was confirmed. */
    /**
     * "Why didn't you add it?": answered by the application from what it recorded in this conversation, not by the model.
     * If a write DID happen here, the turn goes on as usual (the model may describe it). If its last reply was the
     * application explaining a blocker, that explanation is repeated in its own words; otherwise the user is told that
     * nothing was saved and how to ask. Nothing is claimed that is not in the database.
     */
    private function whyNotSaved(User $user, Conversation $conversation, string $userMessage, float $startedAt): ?Message
    {
        if (! PlanChangeIntentClassifier::asksWhyNotSaved($userMessage)) {
            return null;
        }

        $assistant = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->orderBy('id')->get();

        foreach ($assistant as $message) {
            foreach ((array) ($message->meta['tool_calls'] ?? []) as $call) {
                $result = $call['result'] ?? null;

                if (in_array($call['name'] ?? '', ['create_workout_plan', 'propose_weekly_plan_changes'], true) && is_array($result) && ! array_key_exists('error', $result)) {
                    return null;
                }
            }

            if (($message->meta['plan_replacement'] ?? null) === 'applied') {
                return null;
            }
        }

        $words = NoticeText::for($user);
        $last = $assistant->last();
        $notice = '';

        if (($last?->meta['save_clarification'] ?? false) === true) {
            $notice = trim(mb_substr((string) $last->content, mb_strlen(ApplicationNotices::modelText($last))));
        }

        return $this->answerWithoutModel(
            $user,
            $conversation,
            ToolAuthorization::readOnly('why_not_saved'),
            ReplyText::application(NoticeCode::WHY_NOT_SAVED, $notice !== '' ? $words->whyNotSavedLast($notice) : $words->whyNotSaved()),
            [],
            $startedAt,
        );
    }

    private function clarifyConfirmation(User $user, Conversation $conversation, string $userMessage, float $startedAt): ?Message
    {
        if (! Confirmation::isUnclear($userMessage)) {
            return null;
        }

        $previous = $conversation->messages()->reorder('created_at', 'desc')->orderByDesc('id')->limit(2)->get()->get(1);
        $presenter = $this->presenterOf($previous, $conversation);

        // Only a proposal that could still be redeemed right now is "still open": an expired, used, forged or foreign one is not.
        $open = fn (?Message $m, string $key) => $this->proposals->isOpen($conversation, $user, $m, $key);

        if (! $open($presenter, PendingProposalStore::REPLACEMENT_META_KEY) && ! $open($presenter, PendingProposalStore::META_KEY)) {
            return null;
        }

        $text = NoticeText::for($user)->confirmationUnclear();
        $message = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $text,
            'meta' => ['confirms_message_id' => $presenter->id] + ReplyText::application(NoticeCode::CONFIRMATION_UNCLEAR, $text)->meta(),
        ]);

        Log::info('ai_coach.turn', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'assistant_message_id' => $message->id,
            'provider_calls' => 0,
            'plan_authorization' => 'confirmation_unclear',
            'response_type' => 'application',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $message;
    }

    /**
     * A "yes" to a whole-day replacement: make exactly the replacement that was shown, if the day is still as it was
     * shown, and say what happened in the application's own words. Returns null when the message is not that.
     */
    private function confirmReplacement(User $user, Conversation $conversation, string $userMessage, float $startedAt): ?Message
    {
        if (! PlanChangeIntentClassifier::isBareAffirmation($userMessage)) {
            return null;
        }

        // Newest first: this "yes" (just stored), then the message it answers (or the question a clarification pointed back to).
        $previous = $this->presenterOf($conversation->messages()->reorder('created_at', 'desc')->orderByDesc('id')->limit(2)->get()->get(1), $conversation);

        if (! $this->proposals->presentsReplacement($previous)) {
            return null;
        }

        $proposal = $this->proposals->claimReplacement($conversation, $user, $previous);
        $words = NoticeText::for($user);

        if ($proposal === null) {
            $text = $words->confirmationInvalid();
            $status = 'invalid';
            $code = NoticeCode::REPLACEMENT_INVALID;
        } else {
            $result = ModelFacingLocale::english(fn () => $this->planReplacer->apply($user, $proposal));

            if (is_string($result)) {
                $text = $words->confirmationRefused($result);
                $status = 'refused';
                $code = NoticeCode::REPLACEMENT_REFUSED;
            } else {
                $text = $words->confirmationApplied($proposal);
                $status = 'applied';
                $code = NoticeCode::REPLACEMENT_APPLIED;
            }
        }

        // The whole reply is the application's: recorded so, beside the status it has always carried.
        $message = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $text,
            'meta' => ['plan_replacement' => $status] + ReplyText::application($code, $text)->meta(),
        ]);

        Log::info('ai_coach.turn', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'assistant_message_id' => $message->id,
            'provider_calls' => 0,
            'plan_authorization' => 'confirmed_replacement',
            'plan_replacement' => $status,
            'response_type' => 'application',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $message;
    }

    /**
     * Claims about the plan that the application can check:
     *  - "I don't have access to the plan tool" / "I'm unable to update your dashboard": false. Plans are updated when the
     *    user asks, and this turn's tools were withheld by a rule, not by a fault. The user is told how to ask.
     *  - "I've added it to your dashboard": only true if something was written this turn. If not, the user is told that
     *    nothing was saved.
     *
     * @param  array<int, array{name: string, arguments: array, result: mixed}>  $toolTrace
     */
    private function correctClaims(ReplyText $reply, ToolAuthorization $authorization, ToolTurn $turn, array $toolTrace, NoticeText $words): void
    {
        // A plan of several days is reported by the application alone (weekOutcome()): nothing of the model's is left to correct.
        if ($authorization->weekScope !== null) {
            return;
        }

        $outcomes = $this->writeOutcomes($toolTrace);

        // Only what the MODEL wrote is a claim: the application's own notices under it are not. A claim is checked
        // against what the turn did (the tool results above, the held confirmation, the confirmed change), never the
        // other way round: finding a claim writes nothing and permits nothing.
        $claimed = $reply->modelText();

        // Whether the application has already told the user, in a notice of its own, that nothing was changed.
        $saidNothingChanged = $reply->hasAny(NoticeCode::SAYS_NOTHING_CHANGED);

        if (PlanClaims::claimsNoAccess($claimed) !== null) {
            // "I haven't changed anything" is only said when nothing at all was written (an activity that was logged is a change).
            $reply->with(NoticeCode::CLAIM_ACCESS_CORRECTED, $words->noAccessCorrection(! $outcomes['any'] && ! $saidNothingChanged));

            return;
        }

        if ($saidNothingChanged || PlanClaims::claimsPlanWritten($claimed) === null) {
            return;
        }

        // "It was saved" needs a plan to have been written: logging an activity, a weight, a preference or a profile
        // field is not the plan, and neither is a call that was refused, failed, skipped or is waiting for the user.
        if (! $outcomes['plan'] && $authorization->saveScope === null && $turn->pendingReplacement() === null && ! $turn->confirmedChangeMade()) {
            $reply->with(NoticeCode::CLAIM_SAVED_CORRECTED, $words->notSavedCorrection());

            return;
        }

        // Something on the plan WAS written, but another plan change in the same turn was refused, failed or skipped
        // (and nothing later made it good): the model cannot be taken to mean only the part that worked.
        if ($outcomes['unfinished'] && $turn->pendingReplacement() === null) {
            $reply->with(NoticeCode::CLAIM_PARTIAL_CORRECTED, $words->partialCorrection());
        }
    }

    /**
     * What this turn's tool results say was written, from the results themselves (never from what the model said):
     *
     *  - any:        something other than a read succeeded (an activity, a weight, a preference, a profile field, a plan);
     *  - plan:       a plan or the training schedule was written: a workout saved for a day, days of the week replaced or
     *                cleared, an exercise's progress stored, a schedule entry added. Logging an activity or any other
     *                record is NOT this; a call that errored, was refused, is held for confirmation or changed no day is not either;
     *  - unfinished: a plan write was refused, errored, held or partly skipped, and no later call of the same tool completed.
     *
     * @param  array<int, array{name: string, arguments: array, result: mixed}>  $toolTrace
     * @return array{any: bool, plan: bool, unfinished: bool}
     */
    private function writeOutcomes(array $toolTrace): array
    {
        $any = false;
        $plan = false;
        $unfinished = [];

        foreach ($toolTrace as $call) {
            $name = $call['name'];
            $capability = ToolCapabilities::for($name);

            if ($capability === ToolCapability::Read) {
                continue;
            }

            $result = $call['result'] ?? null;
            $failed = ! is_array($result) || array_key_exists('error', $result);
            $writesPlan = $capability->needsAuthorization() || $name === 'add_training_schedule_entry';

            if ($failed) {
                // A refused, errored or held plan write; a failed record of an activity or a weight is not about the plan.
                // A repeat of a change that was already made (and refused as such) is not an unfinished one.
                if ($writesPlan && ! in_array($result['reason'] ?? null, ['save_already_handled', 'confirmation_already_used'], true)) {
                    $unfinished[$name] = true;
                }

                continue;
            }

            $partial = false;

            if ($name === 'propose_weekly_plan_changes') {
                $changed = ($result['applied'] ?? []) !== [] || ($result['cleared'] ?? []) !== [];
                $partial = ($result['skipped'] ?? []) !== [] || ($result['held_for_confirmation'] ?? []) !== [];

                if (! $changed) {
                    // Nothing was changed on any day: not a write, and a refusal if something was asked for.
                    if ($partial) {
                        $unfinished[$name] = true;
                    }

                    continue;
                }
            }

            $any = true;
            $plan = $plan || $writesPlan;

            if ($writesPlan) {
                // A later complete success makes good an earlier failed attempt of the same kind (a retry).
                $unfinished[$name] = $partial;
            }
        }

        return ['any' => $any, 'plan' => $plan, 'unfinished' => in_array(true, $unfinished, true)];
    }

    /**
     * What the user is told about a plan of several days, whatever the model wrote: what was saved (after it was read
     * back), the changes waiting for their confirmation, the days that stopped it, or that nothing was saved and why.
     * The reply is the application's alone, so it is recorded whole as a notice.
     *
     * @return array{0: ReplyText, 1: array<string, mixed>}
     */
    private function weekOutcome(ToolTurn $turn, WeekSaveScope $scope, NoticeText $words): array
    {
        if (($pending = $turn->pendingReplacement()) !== null) {
            return [ReplyText::application(NoticeCode::REPLACEMENT_QUESTION, $words->replacementQuestion($pending)), $this->proposals->presentReplacement($pending) + ['save_clarification' => true]];
        }

        if (($saved = $turn->weekSaved()) !== null) {
            $reply = ReplyText::application(NoticeCode::WEEK_SAVED, $words->weekSaved($saved, $turn->weekAlready(), $scope->omitted));

            if (($rangeNote = $words->rangeNote($turn->rangeNote())) !== null) {
                $reply->with(NoticeCode::RANGE_NOTE, $rangeNote);
            }

            return [$reply, ['plan_replacement' => 'applied']];
        }

        if ($turn->weekBlocked() !== []) {
            return [
                ReplyText::application(NoticeCode::WEEK_BLOCKED, $words->weekBlocked($turn->weekBlocked())),
                ['save_clarification' => true, 'save_pending' => ['kind' => 'week_days']],
            ];
        }

        if ($turn->weekAlready() !== []) {
            return [ReplyText::application(NoticeCode::WEEK_ALREADY, $words->weekAlready($turn->weekAlready())), ['save_clarification' => true]];
        }

        return [ReplyText::application(NoticeCode::WEEK_NOT_SAVED, $words->weekNotSaved($turn->weekProblem())), ['save_clarification' => true]];
    }

    /**
     * What the user is told about a requested save, whatever the model wrote: a replacement waiting for their
     * confirmation (the model's text is replaced by the question), a replacement they asked for (stated), or a save
     * that did not happen (stated, so a model that claims it did is corrected). Adds to $reply; returns the meta.
     *
     * @return array<string, mixed>
     */
    private function saveOutcome(ReplyText $reply, ToolTurn $turn, SaveWorkoutScope $scope, NoticeText $words): array
    {
        if (($pending = $turn->pendingReplacement()) !== null) {
            $reply->replaceWith(NoticeCode::REPLACEMENT_QUESTION, $words->replacementQuestion($pending));

            return $this->proposals->presentReplacement($pending) + ['save_clarification' => true];
        }

        if (($replaced = $turn->replaced()) !== null) {
            $reply->with(NoticeCode::REPLACED_AS_ASKED, $words->replacedAsAsked($replaced['was'], $replaced['date']));

            if (($rangeNote = $words->rangeNote($turn->rangeNote())) !== null) {
                $reply->with(NoticeCode::RANGE_NOTE, $rangeNote);
            }

            return [];
        }

        if ($turn->workoutSaved()) {
            $reply->trimEnd();

            if (($rangeNote = $words->rangeNote($turn->rangeNote())) !== null) {
                $reply->with(NoticeCode::RANGE_NOTE, $rangeNote);
            }

            return [];
        }

        $performed = array_filter($turn->blocked(), fn (array $b) => $b['reason'] === 'day_already_performed') !== [];

        $reply->with($performed ? NoticeCode::SAVE_DAY_PERFORMED : NoticeCode::SAVE_FAILED, $performed
            ? $words->saveDayPerformed($scope->date)
            : $words->saveFailed());

        // The retry still points at the workout above this notice, not at the notice.
        return ['save_clarification' => true];
    }

    /**
     * The application's own closing words, and the proposal (if any) it is now asking the user to confirm.
     *
     * @param  Collection<int, Message>  $history
     * @return array{0: ?array{code: string, text: string}, 1: ?PlanChangeProposal}
     */
    private function followUp(User $user, Conversation $conversation, string $userMessage, ToolAuthorization $authorization, bool $confirmedChangeMade, Collection $history, NoticeText $words): array
    {
        if ($authorization->scopedProposal() !== null) {
            return [$confirmedChangeMade ? null : ['code' => NoticeCode::CONFIRMED_CHANGE_FAILED, 'text' => $words->confirmedChangeFailed()], null];
        }

        return match ($authorization->followUp) {
            'proposal_no_longer_valid' => [['code' => NoticeCode::PROPOSAL_NO_LONGER_VALID, 'text' => $words->proposalNoLongerValid()], null],
            // A "yes" to a workout the coach described and offered to add confirms nothing: say what does.
            ToolAuthorization::FOLLOW_UP_NOTHING_PENDING => [
                ['code' => NoticeCode::NOTHING_PENDING, 'text' => $words->nothingPending($this->workoutReference->resolve($userMessage, $history)->origin === WorkoutReference::FROM_PREVIOUS_REPLY)],
                null,
            ],
            ToolAuthorization::FOLLOW_UP_EXPLICIT_REQUEST => [['code' => NoticeCode::EXPLICIT_REQUEST_NEEDED, 'text' => $words->explicitRequestNeeded()], null],
            ToolAuthorization::FOLLOW_UP_PROPOSE => $this->propose($user, $conversation, $userMessage, $words),
            default => [null, null],
        };
    }

    /** @return array{0: array{code: string, text: string}, 1: ?PlanChangeProposal} */
    private function propose(User $user, Conversation $conversation, string $userMessage, NoticeText $words): array
    {
        $result = $this->proposalParser->propose($user, $conversation, $userMessage);

        if ($result->proposal !== null) {
            return [
                ['code' => NoticeCode::CHANGE_PROPOSED, 'text' => $words->changeProposed($result->proposal)],
                $result->proposal,
            ];
        }

        if ($result->failure === 'above_maximum_change') {
            return [['code' => NoticeCode::ABOVE_MAXIMUM_CHANGE, 'text' => $words->aboveMaximumChange($result->detail)], null];
        }

        return [['code' => NoticeCode::CHANGE_NOT_SPECIFIC, 'text' => $words->changeNotSpecific()], null];
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
        // The voice is part of the key: changing the coach style or detail must show on the next load,
        // not only once something else the recommendation is written from has changed.
        $preferences = CoachingPreferences::forUser($user);
        $cacheKey = "dashboard_recommendation:{$user->id}:".$localNow->toDateString().':'.$this->dashboardInputsFingerprint($user).':'.$preferences->key();

        return Cache::remember($cacheKey, $localNow->copy()->endOfDay(), function () use ($user, $preferences) {
            set_time_limit(60);

            $context = $this->contextBuilder->build($user, 'What should I do today?');
            $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context), $preferences);

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
                ['role' => 'user', 'content' => $this->dashboardRequest($preferences)],
            ]);

            return $response->text ?? NoticeText::for($user)->dashboardFallback();
        });
    }

    /** The one-line request the dashboard recommendation is generated from, in the user's language. */
    private function dashboardRequest(CoachingPreferences $preferences): string
    {
        return match ($preferences->locale) {
            AppLocale::Spanish => 'En '.self::DASHBOARD_LENGTH_ES[$preferences->detail->value].', ¿qué debo hacer hoy y por qué?',
            AppLocale::English => 'In '.self::DASHBOARD_LENGTH[$preferences->detail->value].', what should I do today and why?',
        };
    }
}
