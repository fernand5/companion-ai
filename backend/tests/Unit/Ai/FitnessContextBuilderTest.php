<?php

namespace Tests\Unit\Ai;

use App\Models\ActivityLog;
use App\Models\FitnessProfile;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\Ai\FitnessContextBuilder;
use App\Services\Fitness\RecoveryService;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Memory\MemoryProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FitnessContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_includes_profile_recent_activity_upcoming_schedule_and_memories(): void
    {
        $user = User::factory()->has(FitnessProfile::factory(), 'fitnessProfile')->create();

        ActivityLog::factory()->for($user)->strength()->create(['logged_date' => now()->toDateString()]);

        TrainingSchedule::factory()->for($user)->create([
            'activity_type' => 'Football',
            'day_of_week' => now()->addDay()->dayOfWeek,
        ]);

        app(MemoryProvider::class)->remember($user, 'Prefers short workouts on football days.');

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertNotNull($context['profile']);
        $this->assertNotEmpty($context['recent_activity']);
        $this->assertNotEmpty($context['upcoming_schedule']);
        $this->assertSame('Football', $context['upcoming_schedule'][0]['activity_type']);
        $this->assertNotEmpty($context['memories']);
    }

    public function test_adaptive_scenario_football_tomorrow_and_hard_leg_day_today_both_present(): void
    {
        $user = User::factory()->create();

        ActivityLog::factory()->for($user)->strength()->create([
            'logged_date' => Carbon::today()->toDateString(),
            'notes' => 'Heavy leg day',
        ]);

        TrainingSchedule::factory()->for($user)->create([
            'activity_type' => 'Football',
            'day_of_week' => Carbon::tomorrow()->dayOfWeek,
        ]);

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $todayLegDay = collect($context['recent_activity'])
            ->contains(fn (array $log) => $log['type'] === 'strength' && $log['notes'] === 'Heavy leg day');

        $tomorrowFootball = collect($context['upcoming_schedule'])
            ->contains(fn (array $entry) => $entry['activity_type'] === 'Football' && $entry['date'] === Carbon::tomorrow()->toDateString());

        $this->assertTrue($todayLegDay, 'Expected today\'s hard leg workout to be present in context.');
        $this->assertTrue($tomorrowFootball, 'Expected tomorrow\'s football to be present in context.');
    }

    public function test_context_stays_bounded_and_does_not_dump_the_whole_history(): void
    {
        $user = User::factory()->create();

        ActivityLog::factory()->for($user)->count(40)->sequence(
            fn ($sequence) => ['logged_date' => now()->subDays($sequence->index)->toDateString()]
        )->create();

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertLessThanOrEqual(10, count($context['recent_activity']));
    }

    public function test_context_includes_todays_plan_and_adherence_signal(): void
    {
        $user = User::factory()->create();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'activity_type' => 'strength',
            'title' => 'Upper Body',
            'reasoning' => 'Keeping legs fresh for tomorrow.',
            'reasoning_factors' => ['Football tomorrow'],
            'exercises' => [['exercise_name' => 'Bench Press']],
        ]);

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertNotNull($context['today_plan']);
        $this->assertSame('Upper Body', $context['today_plan']['title']);
        $this->assertSame(['Football tomorrow'], $context['today_plan']['reasoning_factors']);
        $this->assertArrayHasKey('adherence_pct', $context['adherence_signal']);
    }

    public function test_context_today_plan_is_null_when_none_exists(): void
    {
        $user = User::factory()->create();

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertNull($context['today_plan']);
    }

    public function test_context_includes_the_latest_recovery_checkin(): void
    {
        $user = User::factory()->create();

        app(RecoveryService::class)->upsert($user, [
            'energy' => 1,
            'soreness' => 5,
            'motivation' => 2,
            'pain_notes' => 'Sore hamstrings',
        ]);

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertNotNull($context['latest_recovery']);
        $this->assertSame(1, $context['latest_recovery']['energy']);
        $this->assertSame('Sore hamstrings', $context['latest_recovery']['pain_notes']);
    }

    /**
     * Regression for a reported bug: a past-dated plan's title/reasoning is
     * written at creation time (e.g. "Soccer Match Tonight") and never
     * re-labeled once that date passes. Without an explicit marker, that
     * stale wording sat next to `today` with nothing telling the model the
     * two don't refer to the same day — evidenced in production by the coach
     * fabricating a same-day match that no context field actually supported.
     * relative_to_today must make this unambiguous from the data itself.
     */
    public function test_week_plan_marks_a_past_dated_entry_as_past_not_today_or_future(): void
    {
        $user = User::factory()->create();
        $weekRange = app(WeeklyPlanService::class)->weekRange($user);

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $weekRange['start'],
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
            'reasoning' => "Focus on tonight's soccer match and keep the rest of the day light.",
        ]);

        $context = app(FitnessContextBuilder::class)->build(
            $user,
            'What should I do today?',
            includeWeekPlan: true,
            weekRange: $weekRange,
        );

        $entry = collect($context['week_plan'])->firstWhere('planned_date', $weekRange['start']);

        $this->assertNotNull($entry);
        if ($weekRange['start'] === Carbon::today()->toDateString()) {
            $this->assertSame('today', $entry['relative_to_today']);
        } else {
            $this->assertSame('past', $entry['relative_to_today']);
        }
    }

    public function test_week_plan_marks_todays_and_future_entries_correctly(): void
    {
        $user = User::factory()->create();
        $weekRange = app(WeeklyPlanService::class)->weekRange($user);
        $today = Carbon::today()->toDateString();
        $future = Carbon::today()->addDays(2)->toDateString();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $today,
            'activity_type' => 'strength',
            'title' => "Today's Session",
        ]);
        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $future,
            'activity_type' => 'sport',
            'title' => 'Soccer Match',
        ]);

        $context = app(FitnessContextBuilder::class)->build(
            $user,
            'What should I do today?',
            includeWeekPlan: true,
            weekRange: $weekRange,
        );

        $todayEntry = collect($context['week_plan'])->firstWhere('planned_date', $today);
        $futureEntry = collect($context['week_plan'])->firstWhere('planned_date', $future);

        $this->assertSame('today', $todayEntry['relative_to_today']);
        $this->assertSame('future', $futureEntry['relative_to_today']);
    }

    public function test_week_plan_prompt_text_warns_that_past_entries_may_contain_stale_temporal_wording(): void
    {
        $user = User::factory()->create();
        $weekRange = app(WeeklyPlanService::class)->weekRange($user);

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $weekRange['start'],
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
        ]);

        $context = app(FitnessContextBuilder::class)->build(
            $user,
            'What should I do today?',
            includeWeekPlan: true,
            weekRange: $weekRange,
        );

        $promptText = app(FitnessContextBuilder::class)->toPromptText($context);

        $this->assertStringContainsString('relative_to_today', $promptText);
        $this->assertStringContainsString('historical record only', $promptText);
    }

    public function test_week_plan_is_absent_from_context_when_not_requested(): void
    {
        $user = User::factory()->create();

        $context = app(FitnessContextBuilder::class)->build($user, 'What should I do today?');

        $this->assertNull($context['week_plan']);
        $this->assertStringNotContainsString(
            "This week's plan",
            app(FitnessContextBuilder::class)->toPromptText($context),
        );
    }
}
