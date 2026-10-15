<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * The coach may EXPLAIN a progression recommendation; the engine owns the numbers.
 * The tool is read-only, shipped switched off, and exposes only what the engine
 * calculated.
 */
class ProgressionRecommendationToolTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
    }

    private function enableTool(): void
    {
        config(['ai.progression_tool_enabled' => true]);
        $this->app->forgetInstance(ToolRegistry::class);
    }

    public function test_the_tool_is_off_by_default(): void
    {
        $this->assertFalse(config('ai.progression_tool_enabled'));
        $this->assertNull(app(ToolRegistry::class)->get('get_progression_recommendation'));

        $this->fake->queue(new AiResponse(text: 'Log your sets.'));
        $this->ask($this->user, 'Should I go heavier next time?');

        $this->assertNotContains('get_progression_recommendation', array_column($this->fake->calls[0]['tools'], 'name'));
    }

    public function test_when_enabled_a_read_only_question_can_use_it_and_nothing_changes(): void
    {
        $this->enableTool();
        $before = $this->snapshot();
        $plans = WorkoutPlan::count();

        $this->fake->queue(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 't', name: 'get_progression_recommendation', arguments: ['exercise_name' => 'Bench Press'])]),
            new AiResponse(text: 'The calculation suggests 42.5 kg. Nothing has been changed.'),
        );

        $result = $this->ask($this->user, 'Should I go heavier next time on bench press?');

        $this->assertNull($result['error']);
        $this->assertContains('get_progression_recommendation', array_column($this->fake->calls[0]['tools'], 'name'), 'Offered on a read-only turn: it is a read.');
        $this->assertNotContains('create_workout_plan', array_column($this->fake->calls[0]['tools'], 'name'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($plans, WorkoutPlan::count());

        $seen = collect($this->fake->calls[1]['messages'])->firstWhere('role', 'tool')['content'];
        $recommendation = $seen['recommendations'][0];

        $this->assertSame('INCREASE', $recommendation['decision']);
        $this->assertSame(42.5, $recommendation['proposed_target']['weight_kg']);
        $this->assertFalse($recommendation['applied']);
        $this->assertSame('Calculated by the application. Nothing has been applied or changed.', $seen['note']);
    }

    public function test_the_tool_reads_only_the_authenticated_users_data(): void
    {
        $this->enableTool();
        $stranger = $this->newUser();

        $result = app(ToolRegistry::class)->call('get_progression_recommendation', ['exercise_name' => 'Bench Press', 'user_id' => $this->user->id], $stranger);

        $this->assertSame('INSUFFICIENT_DATA', $result['recommendations'][0]['decision']);
        $this->assertSame([], app(ToolRegistry::class)->call('get_progression_recommendation', [], $stranger)['recommendations']);
    }

    public function test_the_tool_writes_nothing(): void
    {
        $this->enableTool();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        app(ToolRegistry::class)->call('get_progression_recommendation', ['exercise_name' => 'Bench Press'], $this->user);
        app(ToolRegistry::class)->call('get_progression_recommendation', [], $this->user);

        $this->assertSame([], $writes);
    }

    public function test_the_tool_is_not_part_of_the_weekly_plan_flow(): void
    {
        $this->enableTool();
        $this->fake->queue(new AiResponse(text: 'No change.'));

        $this->actingAs($this->user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Nothing new.'])->assertOk();

        $this->assertNotContains('get_progression_recommendation', array_column($this->fake->calls[0]['tools'], 'name'));
    }

    public function test_a_recommendation_in_a_tool_result_does_not_authorize_a_plan_change(): void
    {
        $this->enableTool();

        $this->fake->queue(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 't', name: 'get_progression_recommendation', arguments: [])]),
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'create_workout_plan', arguments: [
                'activity_type' => 'strength', 'title' => 'Apply recommendation', 'decision_summary' => 'Apply INCREASE', 'reasoning_factors' => ['engine said INCREASE'],
                'planned_date' => $this->d(-3), 'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 42.5]],
            ])]),
            new AiResponse(text: 'Done.'),
        );

        $this->ask($this->user, 'Should I go heavier next time on bench press?');

        $this->assertSame(0, WorkoutPlan::where('title', 'Apply recommendation')->count(), 'An INCREASE is a calculation, not a permission.');
        $this->assertSame('not_authorized', Message::where('role', 'assistant')->latest('id')->first()->meta['blocked_tool_calls'][0]['reason']);
    }
}
