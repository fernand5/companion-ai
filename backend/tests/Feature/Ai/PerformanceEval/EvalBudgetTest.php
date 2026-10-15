<?php

namespace Tests\Feature\Ai\PerformanceEval;

use PHPUnit\Framework\TestCase;
use Tests\Support\PerformanceEval\EvalBudget;

class EvalBudgetTest extends TestCase
{
    public function test_it_halts_after_repeated_provider_errors_instead_of_retrying_past_a_quota(): void
    {
        $budget = new EvalBudget(maxAnswers: 100, maxConsecutiveErrors: 2);

        $budget->record(false);
        $budget->record(true);
        $this->assertNull($budget->haltReason(), 'One error alone is tolerated.');

        $budget->record(true);
        $this->assertStringContainsString('2 provider errors in a row', (string) $budget->haltReason());
    }

    public function test_a_success_resets_the_error_streak(): void
    {
        $budget = new EvalBudget(maxAnswers: 100, maxConsecutiveErrors: 2);

        foreach ([true, false, true, false, true] as $failed) {
            $budget->record($failed);
        }

        $this->assertNull($budget->haltReason());
    }

    public function test_it_halts_when_the_answer_budget_is_spent(): void
    {
        $budget = new EvalBudget(maxAnswers: 3, maxConsecutiveErrors: 2);

        $budget->record(false);
        $budget->record(false);
        $this->assertNull($budget->haltReason());

        $budget->record(false);
        $this->assertStringContainsString('answer budget of 3', (string) $budget->haltReason());
    }
}
