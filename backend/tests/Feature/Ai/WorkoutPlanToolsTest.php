<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutPlanToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_workout_plan_persists_with_reasoning_factors(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('create_workout_plan', [
            'activity_type' => 'strength',
            'title' => 'Upper Body Strength',
            'decision_summary' => 'Keeping legs fresh for tomorrow\'s match.',
            'reasoning_factors' => ['Football scheduled tomorrow', 'Legs sore from yesterday'],
            'exercises' => [
                ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10],
            ],
        ], $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Upper Body Strength', $result['title']);
        $this->assertSame(['Football scheduled tomorrow', 'Legs sore from yesterday'], $result['reasoning_factors']);
        $this->assertCount(1, $result['exercises']);

        $this->assertDatabaseHas('workout_plans', [
            'user_id' => $user->id,
            'title' => 'Upper Body Strength',
            'reasoning' => 'Keeping legs fresh for tomorrow\'s match.',
        ]);
    }

    public function test_create_workout_plan_requires_reasoning_factors(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('create_workout_plan', [
            'activity_type' => 'strength',
            'title' => 'Upper Body',
            'decision_summary' => 'Some reason.',
        ], $user);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_todays_plan_returns_the_plan_created_for_today(): void
    {
        $user = User::factory()->create();
        $registry = app(ToolRegistry::class);

        $registry->call('create_workout_plan', [
            'activity_type' => 'strength',
            'title' => 'Leg Day',
            'decision_summary' => 'Standard progression.',
            'reasoning_factors' => ['On schedule'],
        ], $user);

        $result = $registry->call('get_todays_plan', [], $user);

        $this->assertSame('Leg Day', $result['title']);
    }

    public function test_get_todays_plan_returns_an_error_when_none_exists(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->call('get_todays_plan', [], $user);

        $this->assertArrayHasKey('error', $result);
    }
}
