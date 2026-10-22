<?php

namespace Tests\Feature\Ai\PerformanceEval;

use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\FitnessContextBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\Check;
use Tests\Support\PerformanceEval\LabeledContextBuilder;
use Tests\Support\PerformanceEval\PerformanceEvalCase;
use Tests\Support\PerformanceEval\PromptVariants;
use Tests\Support\PerformanceEval\RealAnswers;
use Tests\Support\PerformanceEval\Scenario;
use Tests\Support\PerformanceEval\Scenarios;

/**
 * Deterministic half of the evaluation: with a scripted model, prove that every scenario
 * builds through the real code, that the facts a correct answer needs actually reach the
 * model, that nothing is written, and that the automatic checks accept a correct answer
 * and catch each known-bad one (including the failures seen from the real model).
 * No real model is called here; see RealModelEvalTest for that.
 */
class ScenarioCatalogTest extends PerformanceEvalCase
{
    /** @return array<string, array{0: Scenario}> */
    public static function scenarios(): array
    {
        $cases = [];

        foreach (Scenarios::all() as $scenario) {
            $cases[$scenario->id] = [$scenario];
        }

        return $cases;
    }

    public function test_the_catalog_is_large_and_covers_every_required_area(): void
    {
        $scenarios = Scenarios::all();

        $this->assertGreaterThanOrEqual(20, count($scenarios));
        $this->assertSame(count($scenarios), count(array_unique(array_map(fn (Scenario $s) => $s->id, $scenarios))), 'Scenario ids are unique.');
        $this->assertSame([], array_values(array_diff(Scenarios::CATEGORIES, array_unique(array_map(fn (Scenario $s) => $s->category, $scenarios)))), 'Every required category has a scenario.');

        $questions = array_merge(...array_map(fn (Scenario $s) => $s->questions, $scenarios));
        $this->assertGreaterThanOrEqual(25, count($questions));

        foreach ($questions as $question) {
            $this->assertNotSame('', $question->expected, 'Each question states what a correct answer must establish.');
            $this->assertNotSame([], $question->checks);
            $this->assertNotSame('', $question->good);
        }

        $dimensions = array_unique(array_merge(...array_map(fn ($q) => array_map(fn (Check $c) => $c->dimension, $q->checks), $questions)));
        sort($dimensions);
        $expected = Check::DIMENSIONS;
        sort($expected);
        $this->assertSame($expected, $dimensions, 'Numbers, evidence, trend, safety, unsupported claims and tool use are all checked.');

        $guards = array_filter($scenarios, fn (Scenario $s) => $s->guards !== null);
        $this->assertGreaterThanOrEqual(5, count($guards), 'Scenarios built to catch the failures observed from the real model.');
    }

    #[DataProvider('scenarios')]
    public function test_the_scenario_reaches_the_model_exactly_and_the_checks_are_sound(Scenario $scenario): void
    {
        $fake = new FakeAiProvider;

        $result = $this->runScenario($scenario, $fake, before: fn ($question) => $fake->queue(new AiResponse(text: $question->good)));

        $this->assertTrue($result['readonly_ok'], 'Asking changes nothing in plans, workouts, activity, memories or profile.');

        foreach ($scenario->questions as $i => $question) {
            $ran = $result['questions'][$i];
            $this->assertNull($ran['error']);
            $this->assertSame($question->good, $ran['answer']);

            // The facts a correct answer needs are in what the model was sent.
            foreach ($scenario->facts as $fact) {
                $this->assertStringContainsString($fact, $ran['prompt'], "The model must be shown: {$fact}");
            }

            // A correct answer passes every check, using only numbers the model was given.
            $good = $this->evaluate($question, $question->good, $question->goodTools, $ran['facts_text']);
            $failed = array_map(fn ($r) => $r['label'], array_filter($good, fn ($r) => ! $r['pass']));
            $this->assertSame([], array_values($failed), "The known-good answer must pass every check ({$scenario->id}).");

            // Real model answers a person judged correct must also pass (calibration against false alarms),
            // and real answers judged flawed must be caught.
            foreach (RealAnswers::GOOD as $real) {
                if ($real['scenario'] === $scenario->id && $real['index'] === $i) {
                    $failed = array_map(fn ($r) => $r['label'], array_filter($this->evaluate($question, $real['text'], $this->realTools($real['scenario']), $ran['facts_text']), fn ($r) => ! $r['pass']));
                    $this->assertSame([], array_values($failed), "Real answer {$real['source']} was judged correct but a check rejected it.");
                }
            }

            foreach (RealAnswers::BAD as $real) {
                if ($real['scenario'] === $scenario->id && $real['index'] === $i) {
                    $results = $this->evaluate($question, $real['text'], $this->realTools($real['scenario']), $ran['facts_text']);

                    foreach ($real['fails'] as $dimension) {
                        $this->assertNotEmpty(array_filter($results, fn ($r) => $r['dimension'] === $dimension && ! $r['pass']), "Real answer {$real['source']} was judged flawed on {$dimension} but no check caught it.");
                    }
                }
            }

            // Each known-bad answer is caught on the dimensions it is meant to violate.
            foreach ($question->bad as $name => $bad) {
                $results = $this->evaluate($question, $bad['text'], [], $ran['facts_text']);

                foreach ($bad['fails'] as $dimension) {
                    $caught = array_filter($results, fn ($r) => $r['dimension'] === $dimension && ! $r['pass']);
                    $this->assertNotEmpty($caught, "{$scenario->id}: the bad answer \"{$name}\" must fail a {$dimension} check.");
                }
            }
        }
    }

    public function test_a_scenario_without_history_gives_the_model_no_performance_section(): void
    {
        $scenario = collect(Scenarios::all())->firstWhere('id', 'no_history');
        $fake = new FakeAiProvider;

        $result = $this->runScenario($scenario, $fake, before: fn ($q) => $fake->queue(new AiResponse(text: $q->good)));

        $this->assertStringNotContainsString('## Recent performance', $result['questions'][0]['prompt']);
        $this->assertStringNotContainsString('Reading "Recent performance"', $result['questions'][0]['prompt']);
    }

    /**
     * Phase 4C observed Gemini writing a plan in answer to a read-only question. The runner still
     * reports the attempt, but since Phase 5A the application refuses it: the database is untouched.
     */
    public function test_a_plan_write_attempted_on_a_read_only_question_is_reported_and_refused(): void
    {
        $scenario = collect(Scenarios::all())->firstWhere('id', 'focus_next_read_only');
        $fake = new FakeAiProvider;

        $result = $this->runScenario($scenario, $fake, before: function () use ($fake) {
            $fake->queue(
                new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w1', name: 'create_workout_plan', arguments: [
                    'activity_type' => 'strength', 'title' => 'Heavier bench', 'decision_summary' => 'Test write', 'reasoning_factors' => ['test'],
                    'planned_date' => now('America/Bogota')->addDays(3)->toDateString(),
                ])]),
                new AiResponse(text: 'I updated your plan.'),
            );
        });

        $this->assertTrue($result['readonly_ok'], 'The attempted write changed nothing: the application refused it.');
        $this->assertContains('create_workout_plan', $result['questions'][0]['tools'], 'The attempt is still visible to the evaluation.');
        $tools = array_filter($result['questions'][0]['checks'], fn ($c) => $c['dimension'] === Check::TOOLS);
        $this->assertNotEmpty(array_filter($tools, fn ($c) => ! $c['pass']), 'The tool check still fails on the attempt.');
    }

    public function test_the_labeled_format_experiment_changes_only_labels_never_numbers(): void
    {
        $scenario = collect(Scenarios::all())->firstWhere('id', 'three_session_trend');

        $plain = new FakeAiProvider;
        $plainPrompt = $this->runScenario($scenario, $plain, null, fn ($q) => $plain->queue(new AiResponse(text: $q->good)))['questions'][0]['prompt'];

        $this->app->bind(FitnessContextBuilder::class, LabeledContextBuilder::class);
        $labeled = new FakeAiProvider;
        $labeledPrompt = $this->runScenario($scenario, $labeled, null, fn ($q) => $labeled->queue(new AiResponse(text: $q->good)))['questions'][0]['prompt'];

        $this->assertStringContainsString('- latest session (', $labeledPrompt);
        $this->assertStringContainsString('- previous session (', $labeledPrompt);
        $this->assertStringContainsString('- older session (', $labeledPrompt);
        $this->assertStringContainsString('Latest vs previous measured session (', $labeledPrompt);
        $this->assertStringNotContainsString('- latest session (', $plainPrompt);

        $numbers = fn (string $prompt) => Check::numbersIn((string) $this->section($prompt, 'Recent performance'));
        $this->assertSame($numbers($plainPrompt), $numbers($labeledPrompt), 'The facts are identical; only the labels differ.');
    }

    public function test_prompt_variants_change_only_the_reading_rules(): void
    {
        $scenario = collect(Scenarios::all())->firstWhere('id', 'target_missed');
        $prompts = [];

        foreach (['baseline', 'none', 'current'] as $variant) {
            $fake = new FakeAiProvider;
            $result = $this->runScenario($scenario, $fake, PromptVariants::named($variant), fn ($q) => $fake->queue(new AiResponse(text: $q->good)));
            $this->assertNull($result['questions'][0]['error'], "Variant {$variant}: ".$result['questions'][0]['error']);
            $prompts[$variant] = $result['questions'][0]['prompt'];
        }

        $this->assertStringContainsString('## Reading "Recent performance"', $prompts['baseline']);
        $this->assertStringNotContainsString('## Reading "Recent performance"', $prompts['none']);
        $this->assertStringContainsString('## Recent performance', $prompts['none'], 'The facts are still sent without the rules.');
        $this->assertStringContainsString(PromptVariants::named('baseline'), $prompts['baseline']);
        $this->assertStringContainsString(PromptVariants::current(), $prompts['current']);
    }

    /** The tools the real model actually used for that scenario's questions (for the checks that look at tool use). */
    private function realTools(string $scenario): array
    {
        return $scenario === 'older_than_window' ? ['get_exercise_history'] : [];
    }
}
