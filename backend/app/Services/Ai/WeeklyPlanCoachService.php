<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Tools\ToolRegistry;

/**
 * A non-conversational, one-shot AI decision: does this one input change the
 * user's current week's plan? Deliberately not a shared abstraction with
 * AiCoachService::generateReply() — this looks for exactly one terminal tool
 * call and treats "the model replied with plain text" as a valid, safe
 * terminal state ("no material change"), rather than always forcing a final
 * textual answer and persisting Conversation/Message rows like chat does.
 *
 * Only a fixed allow-list of tools is offered here — every read-only lookup
 * tool plus propose_weekly_plan_changes — never the general mutating tools
 * (create_workout_plan, log_activity, etc.), so this flow can't sidestep the
 * validated/transactional/week-scoped path the whole feature exists to
 * enforce. ToolRegistry::call() doesn't itself check what was offered, so
 * every tool call is also checked against this same allow-list before it's
 * ever executed, as defense in depth against a hallucinated call.
 */
class WeeklyPlanCoachService
{
    private const ALLOWED_READ_TOOLS = [
        'get_todays_plan',
        'get_upcoming_schedule',
        'get_recent_activity',
        'get_activity_for_date',
        'get_activity_history',
        'get_today_activity',
        'get_recent_workouts',
        'get_weight_history',
        'get_weekly_training_summary',
        'get_adherence_summary',
        'get_user_profile',
        'get_user_preferences',
    ];

    private const DECISION_TOOL = 'propose_weekly_plan_changes';

    public function __construct(
        private readonly AiProvider $aiProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly FitnessContextBuilder $contextBuilder,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly WeeklyPlanService $weeklyPlanService,
    ) {}

    /**
     * @return array{
     *   applied: bool,
     *   explanation: string|null,
     *   decision_summary?: string,
     *   reasoning_factors?: string[],
     *   changes?: string[],
     *   skipped?: array<int, array{date: string, reason: string}>,
     *   cleared?: string[],
     *   error?: string,
     * }
     */
    public function run(User $user, string $instruction): array
    {
        // Mirrors AiCoachService::respond() — the tool loop can make several
        // sequential Gemini calls, which can cumulatively exceed PHP's default
        // script execution limit.
        set_time_limit(120);

        $weekRange = $this->weeklyPlanService->weekRange($user);
        $context = $this->contextBuilder->build($user, $instruction, includeWeekPlan: true, weekRange: $weekRange);
        $systemPrompt = $this->promptBuilder->build($this->contextBuilder->toPromptText($context))
            .$this->promptBuilder->weeklyAdaptationAddendum();

        $allowedNames = [...self::ALLOWED_READ_TOOLS, self::DECISION_TOOL];
        $tools = array_values(array_filter(
            $this->toolRegistry->declarations(),
            fn (array $tool) => in_array($tool['name'], $allowedNames, true),
        ));

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $instruction],
        ];

        $maxIterations = (int) config('ai.max_weekly_adaptation_iterations', 3);

        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $this->aiProvider->chat($messages, $tools);

            if (! $response->hasToolCalls()) {
                $text = $response->text !== null && trim($response->text) !== ''
                    ? $response->text
                    : 'No change needed.';

                return ['applied' => false, 'explanation' => $text];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => $response->toolCalls,
            ];

            foreach ($response->toolCalls as $toolCall) {
                if (! in_array($toolCall->name, $allowedNames, true)) {
                    $result = ['error' => "Tool {$toolCall->name} is not available in this flow."];
                } else {
                    $result = $this->toolRegistry->call($toolCall->name, $toolCall->arguments, $user);
                }

                if ($toolCall->name === self::DECISION_TOOL) {
                    return $this->presentDecision($result);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => $result,
                ];
            }
        }

        return ['applied' => false, 'explanation' => 'Could not determine a plan change from that — try rephrasing.'];
    }

    private function presentDecision(mixed $result): array
    {
        if (is_array($result) && array_key_exists('error', $result)) {
            return ['applied' => false, 'explanation' => null, 'error' => $result['error']];
        }

        // Both a "replace" and a "clear" are real, applied changes — only
        // "skipped" (already-completed day) means nothing actually happened.
        $changedDates = array_merge($result['applied'] ?? [], $result['cleared'] ?? []);

        return [
            'applied' => $changedDates !== [],
            'explanation' => $result['decision_summary'] ?? null,
            'decision_summary' => $result['decision_summary'] ?? null,
            'reasoning_factors' => $result['reasoning_factors'] ?? [],
            'changes' => $changedDates,
            'skipped' => $result['skipped'] ?? [],
            'cleared' => $result['cleared'] ?? [],
        ];
    }
}
