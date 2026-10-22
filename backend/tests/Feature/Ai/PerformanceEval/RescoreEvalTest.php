<?php

namespace Tests\Feature\Ai\PerformanceEval;

use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\PerformanceEval\Scenarios;

/**
 * Re-score saved real-model answers with the CURRENT checks, without calling any model.
 * SKIPPED unless EVAL_RESCORE is set (a comma-separated list of result files written by
 * RealModelEvalTest, which store each answer, its tool calls and the facts it was shown).
 *
 *   EVAL_RESCORE=/tmp/a.jsonl,/tmp/b.jsonl EVAL_RESCORE_OUT=/tmp/rescored.jsonl \
 *   php artisan test --filter=RescoreEvalTest
 *
 * Changing a check therefore never costs a model call, and every variant can be scored
 * by exactly the same version of the checks.
 */
class RescoreEvalTest extends PerformanceEvalCase
{
    public function test_rescore_saved_answers(): void
    {
        $inputs = array_filter(explode(',', getenv('EVAL_RESCORE') ?: ''));

        if ($inputs === []) {
            $this->markTestSkipped('Opt-in: set EVAL_RESCORE to the result files to re-score.');
        }

        $out = getenv('EVAL_RESCORE_OUT') ?: sys_get_temp_dir().'/performance-eval-rescored.jsonl';
        file_put_contents($out, '');
        $scenarios = [];

        foreach (Scenarios::all() as $scenario) {
            $scenarios[$scenario->id] = $scenario;
        }

        foreach ($inputs as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                $question = $scenarios[$row['scenario']]->questions[$row['index']];

                $row['checks'] = $this->evaluate($question, $row['answer'], $row['tools'], $row['facts_text'] ?? '', $row['error']);
                file_put_contents($out, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            }
        }

        $this->assertFileExists($out);
    }
}
