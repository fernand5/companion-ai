<?php

namespace Tests\Feature\Ai;

use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

class WeeklyPlanEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_adapt_requires_authentication(): void
    {
        $this->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer Wednesday.'])
            ->assertUnauthorized();
    }

    public function test_adapt_validates_input_is_present(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/adapt', ['input' => ''])
            ->assertStatus(422);
    }

    public function test_adapt_returns_503_with_a_safe_message_on_provider_failure(): void
    {
        $this->app->instance(AiProvider::class, new ThrowingAiProvider);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer Wednesday.'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.');
    }

    public function test_adapt_applies_a_targeted_change_over_http(): void
    {
        $wednesday = now()->startOfWeek(Carbon::MONDAY)->addDays(2)->toDateString();

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => 'Swapped Wednesday for soccer.',
                'reasoning_factors' => ['User has soccer Wednesday.'],
                'changes' => [
                    ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'User has soccer Wednesday.'],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/adapt', ['input' => 'I have soccer on Wednesday.'])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertSame('Soccer', app(WorkoutPlanService::class)->forDate($user, $wednesday)?->title);
    }

    public function test_generate_is_a_no_op_when_the_week_already_has_a_plan(): void
    {
        $user = User::factory()->create();
        $monday = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $monday,
            'activity_type' => 'strength',
            'title' => 'Existing Plan',
        ]);

        $fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/generate', [])
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->assertCount(0, $fake->calls);
    }

    public function test_generate_fills_only_the_missing_scheduled_day_when_the_week_is_partially_planned(): void
    {
        $user = User::factory()->create();
        $tuesday = now()->startOfWeek(Carbon::MONDAY)->addDays(1)->toDateString();
        $thursday = now()->startOfWeek(Carbon::MONDAY)->addDays(3)->toDateString();

        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 2, 'activity_type' => 'Soccer']);
        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 4, 'activity_type' => 'Soccer']);

        // Reproduces the reported bug exactly: an ad-hoc plan already exists for
        // one scheduled day, which previously made the old "any plan exists in
        // the week" guard skip generation entirely, leaving Thursday empty.
        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $tuesday,
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
        ]);

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => "Filled Thursday's soccer session from your recurring schedule.",
                'reasoning_factors' => ['Recurring soccer schedule on Thursdays'],
                'changes' => [
                    ['date' => $thursday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'Matches recurring schedule.'],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/generate', [])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertSame('Soccer Match Tonight', app(WorkoutPlanService::class)->forDate($user, $tuesday)?->title);
        $this->assertSame('Soccer', app(WorkoutPlanService::class)->forDate($user, $thursday)?->title);

        // The instruction sent to the model must name the specific gap, not
        // ask for a full-week regeneration.
        $userMessage = collect($fake->calls[0]['messages'])->firstWhere('role', 'user');
        $this->assertStringContainsString($thursday, $userMessage['content']);
    }

    public function test_generate_does_not_call_the_ai_when_the_schedule_is_already_fully_covered(): void
    {
        $user = User::factory()->create();
        $tuesday = now()->startOfWeek(Carbon::MONDAY)->addDays(1)->toDateString();

        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 2, 'activity_type' => 'Soccer']);

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $tuesday,
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
        ]);

        $fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/generate', [])
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->assertCount(0, $fake->calls);
    }

    public function test_adapt_is_rate_limited_like_chat(): void
    {
        $fake = new FakeAiProvider;
        $fake->queue(...array_fill(0, 10, new AiResponse(text: 'No change needed.')));
        $this->app->instance(AiProvider::class, $fake);

        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/weekly-plan/adapt', ['input' => "input {$i}"])
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/weekly-plan/adapt', ['input' => 'one too many'])
            ->assertStatus(429);
    }
}
