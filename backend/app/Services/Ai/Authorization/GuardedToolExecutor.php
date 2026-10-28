<?php

namespace App\Services\Ai\Authorization;

use App\Services\Fitness\WeeklyPlanService;
use App\Services\Tools\ToolRegistry;

/**
 * The one way an AI flow gets to run a tool. It hands out a ToolTurn bound to a
 * ToolAuthorization, so every tool call of that turn passes the same check
 * (see ToolTurn). Nothing outside this class and ToolRegistry should execute
 * AI tools; an architecture test holds that line.
 */
class GuardedToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly PlanLoadGuard $loadGuard,
        private readonly ProposalMatcher $matcher,
        private readonly WeeklyPlanService $weekly,
    ) {}

    /** @param int|null $conversationId the chat the turn belongs to; a confirmation can only be asked for in one */
    public function begin(ToolAuthorization $authorization, ?int $conversationId = null): ToolTurn
    {
        return new ToolTurn($this->registry, $this->loadGuard, $this->matcher, $authorization, $this->weekly, $conversationId);
    }
}
