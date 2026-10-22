<?php

namespace Tests\Feature\Ai\PerformanceEval;

use App\Services\Ai\FitnessContextBuilder;
use App\Services\Ai\GeminiProvider;
use Tests\Support\PerformanceEval\EvalBudget;
use Tests\Support\PerformanceEval\LabeledContextBuilder;
use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\PerformanceEval\PromptVariants;
use Tests\Support\PerformanceEval\Scenarios;

/**
 * Real-model half of the evaluation. SKIPPED unless EVAL_REAL_MODEL=1, so the normal
 * suite never calls a model or needs a key. Everything runs against an in-memory
 * database with synthetic users; only the scenario's synthetic prompt leaves the machine.
 *
 *   EVAL_REAL_MODEL=1 EVAL_VARIANTS=baseline,current EVAL_REPEATS=2 [EVAL_FORMAT=labeled] \
 *   EVAL_SCENARIOS=exact_sets_same_load,target_missed EVAL_OUT=/tmp/eval.jsonl \
 *   [EVAL_MAX_ANSWERS=200] [EVAL_PACE_SECONDS=10] \
 *   php artisan test --filter=RealModelEvalTest
 *
 * The application's own throttle stays on (10 chat messages a minute): requests are
 * paced under it, and a 429/503 is waited out and retried, never bypassed. Results are
 * appended one JSON line per question, so a long run can be summarised even if cut short.
 * The configured Gemini key is read from .env here and is never printed or written out.
 */
class RealModelEvalTest extends PerformanceEvalCase
{
    public function test_real_model_evaluation(): void
    {
        if (getenv('EVAL_REAL_MODEL') !== '1') {
            $this->markTestSkipped('Real-model evaluation is opt-in: set EVAL_REAL_MODEL=1.');
        }

        $key = $this->envValue('AI_API_KEY');
        $this->assertNotSame('', $key, 'AI_API_KEY must be configured in .env for a real-model run.');

        $variants = array_filter(explode(',', getenv('EVAL_VARIANTS') ?: 'current'));
        $repeats = max(1, (int) (getenv('EVAL_REPEATS') ?: 1));
        $only = array_filter(explode(',', getenv('EVAL_SCENARIOS') ?: ''));
        $out = getenv('EVAL_OUT') ?: sys_get_temp_dir().'/performance-eval.jsonl';

        $this->paceSeconds = (float) (getenv('EVAL_PACE_SECONDS') ?: 7.0);

        // EVAL_FORMAT=labeled renders the same facts with sessions labelled latest/previous (an experiment).
        $format = getenv('EVAL_FORMAT') ?: 'compact';
        if ($format === 'labeled') {
            $this->app->bind(FitnessContextBuilder::class, LabeledContextBuilder::class);
        }

        $provider = new GeminiProvider(apiKey: $key, model: $this->envValue('AI_MODEL'));

        // Each chat message can make several provider calls (tool use), and a provider may enforce a
        // spending cap as well as a rate limit. The budget stops the run on repeated errors or after
        // a fixed number of answers; it never retries its way past a quota.
        $budget = new EvalBudget((int) (getenv('EVAL_MAX_ANSWERS') ?: 400), 2);
        $halted = null;

        $scenarios = array_values(array_filter(Scenarios::all(), fn ($s) => $only === [] || in_array($s->id, $only, true)));

        foreach ($variants as $variant) {
            $rules = PromptVariants::named($variant);

            for ($repeat = 1; $repeat <= $repeats; $repeat++) {
                foreach ($scenarios as $scenario) {
                    $result = $this->runScenario($scenario, $provider, $rules);

                    foreach ($result['questions'] as $i => $q) {
                        $budget->record($q['error'] !== null);
                        file_put_contents($out, json_encode([
                            'variant' => $variant.($format === 'compact' ? '' : '+'.$format), 'repeat' => $repeat, 'scenario' => $scenario->id, 'category' => $scenario->category,
                            'index' => $i, 'question' => $q['question'], 'expected' => $q['expected'], 'answer' => $q['answer'],
                            'tools' => $q['tools'], 'error' => $q['error'], 'checks' => $q['checks'], 'readonly_ok' => $result['readonly_ok'],
                            'facts_text' => $q['facts_text'], 'prompt_chars' => $q['prompt_chars'], 'latency_ms' => $q['latency_ms'], 'provider_calls' => $q['provider_calls'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
                    }

                    if (($halted = $budget->haltReason()) !== null) {
                        break 3;
                    }
                }
            }
        }

        if ($halted !== null) {
            file_put_contents($out.'.halted', $halted."\n");
            $this->markTestIncomplete("Evaluation halted: {$halted}");
        }

        $this->assertFileExists($out);
    }

    private function envValue(string $key): string
    {
        foreach (file(base_path('.env')) as $line) {
            if (str_starts_with($line, $key.'=')) {
                return trim(trim(substr($line, strlen($key) + 1)), "\"'");
            }
        }

        return '';
    }
}
