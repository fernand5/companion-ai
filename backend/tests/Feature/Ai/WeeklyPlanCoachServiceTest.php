<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\WeeklyPlanCoachService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\ThrowingAiProvider;
use Tests\TestCase;

class WeeklyPlanCoachServiceTest extends TestCase
{
    use RefreshDatabase;

    private function monday(): Carbon
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY);
    }

    private function seedFullWeek(User $user): array
    {
        $workoutPlanService = app(WorkoutPlanService::class);
        $dates = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $this->monday()->addDays($i)->toDateString();
            $dates[] = $date;
            $workoutPlanService->createOrReplace($user, [
                'planned_date' => $date,
                'activity_type' => 'strength',
                'title' => 'Original Strength',
            ]);
        }

        return $dates;
    }

    public function test_soccer_input_targets_only_the_affected_day_and_preserves_the_rest(): void
    {
        $user = User::factory()->create();
        $dates = $this->seedFullWeek($user);
        $wednesday = $dates[2];

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => "Swapped Wednesday's strength session for soccer.",
                'reasoning_factors' => ['User has soccer Wednesday.'],
                'changes' => [
                    ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'User reported having soccer on Wednesday.'],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(WeeklyPlanCoachService::class)->run($user, 'I have soccer on Wednesday.');

        $this->assertTrue($result['applied']);
        $this->assertStringContainsString('soccer', mb_strtolower($result['explanation']));

        $workoutPlanService = app(WorkoutPlanService::class);
        $this->assertSame('Soccer', $workoutPlanService->forDate($user, $wednesday)?->title);
        foreach ($dates as $date) {
            if ($date === $wednesday) {
                continue;
            }
            $this->assertSame('Original Strength', $workoutPlanService->forDate($user, $date)?->title);
        }
    }

    public function test_schedule_conflict_input_changes_only_the_conflicting_day(): void
    {
        $user = User::factory()->create();
        $dates = $this->seedFullWeek($user);
        $friday = $dates[4];

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => "Cleared Friday's session since the user can't train that day.",
                'reasoning_factors' => ["User can't train Friday."],
                'changes' => [
                    ['date' => $friday, 'action' => 'clear', 'reason' => "User said they can't train Friday."],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(WeeklyPlanCoachService::class)->run($user, "I can't train Friday.");

        $this->assertTrue($result['applied']);
        $this->assertStringContainsString('friday', mb_strtolower($result['explanation']));

        $workoutPlanService = app(WorkoutPlanService::class);
        $this->assertSame(WorkoutPlan::STATUS_SKIPPED, $workoutPlanService->forDate($user, $friday)?->status);
        foreach ($dates as $date) {
            if ($date === $friday) {
                continue;
            }
            $this->assertSame('Original Strength', $workoutPlanService->forDate($user, $date)?->title);
        }
    }

    public function test_recovery_soreness_input_adjusts_the_smallest_reasonable_set_of_days(): void
    {
        $user = User::factory()->create();
        $dates = $this->seedFullWeek($user);
        [$today, $tomorrow] = [$dates[0], $dates[1]];

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => 'Lightened the next two days due to reported soreness.',
                'reasoning_factors' => ['User reported feeling very sore this week.'],
                'changes' => [
                    ['date' => $today, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'User is very sore.'],
                    ['date' => $tomorrow, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Light Mobility', 'reason' => 'User is very sore.'],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(WeeklyPlanCoachService::class)->run($user, "I'm feeling very sore this week.");

        $this->assertTrue($result['applied']);
        $this->assertLessThan(7, count($result['changes']));
        $this->assertStringContainsString('sor', mb_strtolower(implode(' ', $result['reasoning_factors'])));
    }

    public function test_irrelevant_input_does_not_write_anything(): void
    {
        $user = User::factory()->create();
        $this->seedFullWeek($user);

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: "That doesn't affect your training week."));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(WeeklyPlanCoachService::class)->run($user, "What's the weather like?");

        $this->assertFalse($result['applied']);
        foreach (['Original Strength'] as $title) {
            $this->assertSame(7, WorkoutPlan::where('user_id', $user->id)->where('title', $title)->count());
        }
    }

    public function test_malformed_tool_call_is_not_persisted(): void
    {
        $user = User::factory()->create();
        $this->seedFullWeek($user);
        $wednesday = $this->monday()->addDays(2)->toDateString();

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: null, toolCalls: [
            new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => 'Missing reason field.',
                'reasoning_factors' => ['x'],
                // 'reason' intentionally omitted from the change item.
                'changes' => [
                    ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer'],
                ],
            ]),
        ]));
        $this->app->instance(AiProvider::class, $fake);

        $result = app(WeeklyPlanCoachService::class)->run($user, 'I have soccer Wednesday.');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Original Strength', app(WorkoutPlanService::class)->forDate($user, $wednesday)?->title);
    }

    public function test_provider_failure_does_not_touch_the_existing_plan(): void
    {
        $user = User::factory()->create();
        $dates = $this->seedFullWeek($user);

        $this->app->instance(AiProvider::class, new ThrowingAiProvider);

        try {
            app(WeeklyPlanCoachService::class)->run($user, 'I have soccer Wednesday.');
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException) {
            // expected
        }

        $workoutPlanService = app(WorkoutPlanService::class);
        foreach ($dates as $date) {
            $this->assertSame('Original Strength', $workoutPlanService->forDate($user, $date)?->title);
        }
    }

    public function test_duplicate_submission_does_not_create_duplicate_rows(): void
    {
        $user = User::factory()->create();
        $wednesday = $this->monday()->addDays(2)->toDateString();

        $arguments = [
            'decision_summary' => 'Swapped Wednesday for soccer.',
            'reasoning_factors' => ['User has soccer Wednesday.'],
            'changes' => [
                ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'User has soccer Wednesday.'],
            ],
        ];

        $fake = new FakeAiProvider;
        $fake->queue(
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'call_1', name: 'propose_weekly_plan_changes', arguments: $arguments)]),
            new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'call_2', name: 'propose_weekly_plan_changes', arguments: $arguments)]),
        );
        $this->app->instance(AiProvider::class, $fake);

        app(WeeklyPlanCoachService::class)->run($user, 'I have soccer Wednesday.');
        $firstCount = WorkoutPlan::where('user_id', $user->id)->count();

        app(WeeklyPlanCoachService::class)->run($user, 'I have soccer Wednesday.');
        $secondCount = WorkoutPlan::where('user_id', $user->id)->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame($firstCount, $secondCount);
    }

    public function test_only_read_only_tools_and_the_decision_tool_are_offered_to_the_model(): void
    {
        $user = User::factory()->create();

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'No change needed.'));
        $this->app->instance(AiProvider::class, $fake);

        app(WeeklyPlanCoachService::class)->run($user, 'I have soccer Wednesday.');

        $toolNames = array_column($fake->calls[0]['tools'], 'name');

        $this->assertContains('propose_weekly_plan_changes', $toolNames);
        $this->assertContains('get_upcoming_schedule', $toolNames);
        $this->assertNotContains('create_workout_plan', $toolNames);
        $this->assertNotContains('log_activity', $toolNames);
        $this->assertNotContains('update_fitness_profile', $toolNames);
    }

    public function test_the_weekly_flow_asks_the_provider_for_its_longer_timeout(): void
    {
        config(['ai.weekly_timeout' => 75]);
        $user = User::factory()->create();

        $fake = new FakeAiProvider;
        $fake->queue(new AiResponse(text: 'No change needed.'));
        $this->app->instance(AiProvider::class, $fake);

        app(WeeklyPlanCoachService::class)->run($user, 'Generate an initial plan for this week.');

        $this->assertSame(75, $fake->calls[0]['options']['timeout_seconds']);
    }
}
