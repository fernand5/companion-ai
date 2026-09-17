<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Tools\ToolRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyPlanToolTest extends TestCase
{
    use RefreshDatabase;

    private function monday(): Carbon
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY);
    }

    public function test_propose_weekly_plan_changes_persists_targeted_replace(): void
    {
        $user = User::factory()->create();
        $wednesday = $this->monday()->addDays(2)->toDateString();

        $result = app(ToolRegistry::class)->call('propose_weekly_plan_changes', [
            'decision_summary' => 'Swapped Wednesday for soccer.',
            'reasoning_factors' => ['User has soccer Wednesday.'],
            'changes' => [
                ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'User has soccer Wednesday.'],
            ],
        ], $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([$wednesday], $result['applied']);
        $this->assertSame('Soccer', app(WorkoutPlanService::class)->forDate($user, $wednesday)?->title);
    }

    public function test_propose_weekly_plan_changes_with_empty_changes_writes_nothing(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('propose_weekly_plan_changes', [
            'decision_summary' => 'Nothing material changed.',
            'reasoning_factors' => ['Input was unrelated to training.'],
            'changes' => [],
        ], $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([], $result['applied']);
        $this->assertDatabaseCount('workout_plans', 0);
    }

    public function test_propose_weekly_plan_changes_returns_error_for_invalid_date_without_persisting(): void
    {
        $user = User::factory()->create();
        $outOfWeek = $this->monday()->addDays(30)->toDateString();

        $result = app(ToolRegistry::class)->call('propose_weekly_plan_changes', [
            'decision_summary' => 'Bad date.',
            'reasoning_factors' => ['x'],
            'changes' => [
                ['date' => $outOfWeek, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'X', 'reason' => 'x'],
            ],
        ], $user);

        $this->assertArrayHasKey('error', $result);
        $this->assertDatabaseCount('workout_plans', 0);
    }

    public function test_only_the_authenticated_users_week_is_ever_touched(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $monday = $this->monday()->toDateString();

        app(WorkoutPlanService::class)->createOrReplace($owner, [
            'planned_date' => $monday,
            'activity_type' => 'strength',
            'title' => "Owner's Monday",
        ]);

        app(ToolRegistry::class)->call('propose_weekly_plan_changes', [
            'decision_summary' => 'Change Monday.',
            'reasoning_factors' => ['x'],
            'changes' => [
                ['date' => $monday, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Recovery', 'reason' => 'x'],
            ],
        ], $intruder);

        $workoutPlanService = app(WorkoutPlanService::class);
        $this->assertSame("Owner's Monday", $workoutPlanService->forDate($owner, $monday)?->title);
        $this->assertSame('Recovery', $workoutPlanService->forDate($intruder, $monday)?->title);
    }
}
