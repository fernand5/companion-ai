<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Covers a historical activity reported in ordinary chat (not the dedicated
 * Weekly Plan input) also being able to trigger a targeted plan change, in
 * the same turn — via AiCoachService::respond(), chaining log_activity and
 * propose_weekly_plan_changes exactly like the existing
 * create_workout_plan + update_exercise_status chaining test does.
 */
class HistoricalActivityPlanAdaptationTest extends TestCase
{
    use RefreshDatabase;

    private function yesterday(): string
    {
        return now()->subDay()->toDateString();
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    /**
     * Carbon's `date` cast serializes with a time component on save, so
     * assertDatabaseHas can't exact-match a raw date string — query via
     * whereDate() instead, the same technique the app's own services use.
     */
    private function activityCountForDate(User $user, string $date): int
    {
        return ActivityLog::where('user_id', $user->id)->whereDate('logged_date', $date)->count();
    }

    public function test_historical_high_load_activity_materially_affecting_today_logs_and_adjusts_the_plan(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $this->today(),
            'activity_type' => 'strength',
            'title' => 'Original Strength',
        ]);

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'sport',
                    'logged_date' => $this->yesterday(),
                    'intensity' => 'high',
                    'metadata' => ['sport' => 'Soccer'],
                ]),
            ]),
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_2', name: 'propose_weekly_plan_changes', arguments: [
                    'decision_summary' => "Changed today's strength session to recovery after yesterday's soccer.",
                    'reasoning_factors' => ['Played high-intensity soccer yesterday.'],
                    'changes' => [
                        ['date' => $this->today(), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'High-load soccer session yesterday.'],
                    ],
                ]),
            ]),
            new AiResponse(text: "I've logged your soccer session from yesterday. Since that was high-load, I've changed today's workout to recovery."),
        );
        $this->app->instance(AiProvider::class, $fake);

        $reply = app(AiCoachService::class)->respond($user, $conversation, 'I played soccer yesterday.');

        $this->assertStringContainsString('soccer', mb_strtolower($reply->content));
        $this->assertStringContainsString('recovery', mb_strtolower($reply->content));

        $this->assertSame(1, $this->activityCountForDate($user, $this->yesterday()));
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'sport']);
        $this->assertSame('Active Recovery', app(WorkoutPlanService::class)->forDate($user, $this->today())?->title);
        $this->assertCount(2, $reply->meta['tool_calls']);
    }

    public function test_historical_activity_that_does_not_materially_affect_the_week_only_logs(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $this->today(),
            'activity_type' => 'strength',
            'title' => 'Original Strength',
        ]);

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'steps',
                    'logged_date' => $this->yesterday(),
                    'metadata' => ['steps' => 4000],
                ]),
            ]),
            new AiResponse(text: 'Logged your 4000 steps from yesterday.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond($user, $conversation, 'I did 4000 steps yesterday.');

        $this->assertSame(1, $this->activityCountForDate($user, $this->yesterday()));
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'type' => 'steps']);
        $this->assertSame('Original Strength', app(WorkoutPlanService::class)->forDate($user, $this->today())?->title);
        $this->assertSame(1, WorkoutPlan::where('user_id', $user->id)->count());
    }

    public function test_historical_day_had_no_existing_plan_at_all(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'sport',
                    'logged_date' => $this->yesterday(),
                    'intensity' => 'high',
                ]),
            ]),
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_2', name: 'propose_weekly_plan_changes', arguments: [
                    'decision_summary' => 'Added a recovery day today.',
                    'reasoning_factors' => ['High-load sport session yesterday.'],
                    'changes' => [
                        ['date' => $this->today(), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'High-load session yesterday.'],
                    ],
                ]),
            ]),
            new AiResponse(text: 'Logged it and set today to recovery.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond($user, $conversation, 'I played a match yesterday.');

        $this->assertSame(1, $this->activityCountForDate($user, $this->yesterday()));
        $this->assertSame('Active Recovery', app(WorkoutPlanService::class)->forDate($user, $this->today())?->title);
    }

    public function test_historical_day_already_has_other_activity_logged(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        ActivityLog::factory()->for($user)->create(['type' => 'steps', 'logged_date' => $this->yesterday()]);

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'sport',
                    'logged_date' => $this->yesterday(),
                ]),
            ]),
            new AiResponse(text: 'Logged your soccer session too.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond($user, $conversation, 'I also played soccer yesterday.');

        $this->assertSame(2, $this->activityCountForDate($user, $this->yesterday()));
    }

    public function test_activity_logged_several_days_late_preserves_the_historical_date(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();
        $fiveDaysAgo = now()->subDays(5)->toDateString();

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'sport',
                    'logged_date' => $fiveDaysAgo,
                ]),
            ]),
            new AiResponse(text: 'Got it, logged from that date.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond($user, $conversation, 'I forgot to mention — I played soccer 5 days ago.');

        $this->assertSame(1, $this->activityCountForDate($user, $fiveDaysAgo));
    }

    public function test_already_completed_day_is_not_overwritten_by_this_path_either(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create();

        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $this->today(),
            'activity_type' => 'strength',
            'title' => 'Completed Session',
        ]);
        $plan->update(['status' => WorkoutPlan::STATUS_COMPLETED]);

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_1', name: 'log_activity', arguments: [
                    'type' => 'sport',
                    'logged_date' => $this->yesterday(),
                    'intensity' => 'high',
                ]),
            ]),
            new AiResponse(text: null, toolCalls: [
                new ToolCallRequest(id: 'call_2', name: 'propose_weekly_plan_changes', arguments: [
                    'decision_summary' => "Attempted to change today's already-completed session.",
                    'reasoning_factors' => ['High-load session yesterday.'],
                    'changes' => [
                        ['date' => $this->today(), 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'x'],
                    ],
                ]),
            ]),
            new AiResponse(text: 'Logged your session.'),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(AiCoachService::class)->respond($user, $conversation, 'I played soccer yesterday.');

        $todayPlan = app(WorkoutPlanService::class)->forDate($user, $this->today());
        $this->assertSame('Completed Session', $todayPlan?->title);
        $this->assertSame(WorkoutPlan::STATUS_COMPLETED, $todayPlan?->status);
    }
}
