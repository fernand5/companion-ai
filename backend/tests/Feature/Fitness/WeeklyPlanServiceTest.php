<?php

namespace Tests\Feature\Fitness;

use App\Models\TrainingSchedule;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\WeeklyPlanService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class WeeklyPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private function monday(): Carbon
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY);
    }

    public function test_apply_changes_replaces_only_the_targeted_dates_and_preserves_others(): void
    {
        $user = User::factory()->create();
        $workoutPlanService = app(WorkoutPlanService::class);

        $monday = $this->monday()->toDateString();
        $wednesday = $this->monday()->addDays(2)->toDateString();
        $friday = $this->monday()->addDays(4)->toDateString();

        foreach ([$monday, $wednesday, $friday] as $date) {
            $workoutPlanService->createOrReplace($user, [
                'planned_date' => $date,
                'activity_type' => 'strength',
                'title' => 'Original Strength',
            ]);
        }

        app(WeeklyPlanService::class)->applyChanges($user, [
            ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'User has soccer Wednesday.'],
        ], ['User has soccer Wednesday.']);

        // Carbon's `date` cast serializes planned_date with a time component on
        // save, so assertDatabaseHas can't exact-match the raw date string —
        // WorkoutPlanService itself works around this with whereDate() lookups
        // (see its forDate()), which is what these assertions reuse.
        $this->assertSame('Soccer', $workoutPlanService->forDate($user, $wednesday)?->title);
        $this->assertSame('sport', $workoutPlanService->forDate($user, $wednesday)?->activity_type);
        $this->assertSame('Original Strength', $workoutPlanService->forDate($user, $monday)?->title);
        $this->assertSame('Original Strength', $workoutPlanService->forDate($user, $friday)?->title);
    }

    public function test_apply_changes_clear_action_marks_the_day_skipped_rather_than_deleting_it(): void
    {
        $user = User::factory()->create();
        $friday = $this->monday()->addDays(4)->toDateString();

        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $friday,
            'activity_type' => 'strength',
            'title' => 'Friday Strength',
        ]);

        app(WeeklyPlanService::class)->applyChanges($user, [
            ['date' => $friday, 'action' => 'clear', 'reason' => "Can't train Friday."],
        ]);

        // Kept as a real row (not deleted) so the explanation stays visible and
        // a later schedule-gap fill doesn't mistake this for "never generated"
        // and silently recreate the very session the user cleared.
        $plan = app(WorkoutPlanService::class)->forDate($user, $friday);
        $this->assertNotNull($plan);
        $this->assertSame(WorkoutPlan::STATUS_SKIPPED, $plan->status);
        $this->assertSame("Can't train Friday.", $plan->reasoning);
    }

    public function test_apply_changes_rejects_a_date_outside_the_current_week_before_writing_anything(): void
    {
        $user = User::factory()->create();
        $wednesday = $this->monday()->addDays(2)->toDateString();
        $outOfWeek = $this->monday()->addDays(10)->toDateString();

        try {
            app(WeeklyPlanService::class)->applyChanges($user, [
                ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'x'],
                ['date' => $outOfWeek, 'action' => 'replace', 'activity_type' => 'sport', 'title' => 'Soccer', 'reason' => 'x'],
            ]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count());
    }

    public function test_apply_changes_skips_a_day_that_is_already_completed_without_erroring(): void
    {
        $user = User::factory()->create();
        $monday = $this->monday()->toDateString();

        $plan = app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $monday,
            'activity_type' => 'strength',
            'title' => 'Completed Session',
        ]);
        $plan->update(['status' => WorkoutPlan::STATUS_COMPLETED]);

        $result = app(WeeklyPlanService::class)->applyChanges($user, [
            ['date' => $monday, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Recovery', 'reason' => 'Sore from soccer.'],
        ]);

        $this->assertSame('Completed Session', app(WorkoutPlanService::class)->forDate($user, $monday)?->title);
        $this->assertSame([], $result['applied']);
        $this->assertSame($monday, $result['skipped'][0]['date']);
    }

    public function test_apply_changes_rolls_back_all_writes_when_a_later_change_in_the_batch_fails(): void
    {
        $user = User::factory()->create();
        $monday = $this->monday()->toDateString();
        $wednesday = $this->monday()->addDays(2)->toDateString();

        // A partial mock that passes the first createOrReplace() call through to a
        // real WorkoutPlanService instance (so it performs a genuine DB write inside
        // its own transaction, nested as a savepoint under WeeklyPlanService's outer
        // transaction), then throws on the second call. If the outer transaction
        // truly rolls back the already-executed first write (not just stopping
        // before the second), Monday's row must not survive.
        $real = app()->make(WorkoutPlanService::class);
        $callCount = 0;
        $partialMock = Mockery::mock(WorkoutPlanService::class);
        $partialMock->shouldReceive('forDate')->andReturnUsing(fn (...$args) => $real->forDate(...$args));
        $partialMock->shouldReceive('createOrReplace')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$callCount, $real) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \RuntimeException('simulated failure');
                }

                return $real->createOrReplace(...$args);
            });

        $this->app->instance(WorkoutPlanService::class, $partialMock);

        try {
            app(WeeklyPlanService::class)->applyChanges($user, [
                ['date' => $monday, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'A', 'reason' => 'x'],
                ['date' => $wednesday, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'B', 'reason' => 'x'],
            ]);
            $this->fail('Expected RuntimeException.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count());
    }

    public function test_apply_changes_validates_exercise_fields_the_same_as_create_or_replace(): void
    {
        $user = User::factory()->create();
        $monday = $this->monday()->toDateString();

        $this->expectException(ValidationException::class);

        app(WeeklyPlanService::class)->applyChanges($user, [
            [
                'date' => $monday,
                'action' => 'replace',
                'activity_type' => 'strength',
                'title' => 'Bad Exercise',
                'reason' => 'x',
                'exercises' => [['exercise_name' => 'Squat', 'planned_sets' => -1]],
            ],
        ]);
    }

    public function test_scheduled_days_missing_plans_finds_gaps_but_not_already_covered_days(): void
    {
        $user = User::factory()->create();
        $tuesday = $this->monday()->addDays(1)->toDateString();
        $thursday = $this->monday()->addDays(3)->toDateString();

        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 2, 'activity_type' => 'Soccer']);
        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 4, 'activity_type' => 'Soccer']);

        // Reproduces the reported bug: an ad-hoc plan already exists for one
        // scheduled day (Tuesday), which must not hide the other gap (Thursday).
        app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $tuesday,
            'activity_type' => 'sport',
            'title' => 'Soccer Match Tonight',
        ]);

        $missing = app(WeeklyPlanService::class)->scheduledDaysMissingPlans($user);

        $this->assertSame([$thursday], $missing);
    }

    public function test_scheduled_days_missing_plans_is_empty_when_there_is_no_recurring_schedule(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], app(WeeklyPlanService::class)->scheduledDaysMissingPlans($user));
    }

    public function test_scheduled_days_missing_plans_excludes_a_day_that_was_explicitly_cleared(): void
    {
        $user = User::factory()->create();
        $friday = $this->monday()->addDays(4)->toDateString();

        TrainingSchedule::factory()->for($user)->create(['day_of_week' => 5, 'activity_type' => 'Soccer']);

        app(WeeklyPlanService::class)->applyChanges($user, [
            ['date' => $friday, 'action' => 'clear', 'reason' => "Can't train Friday."],
        ]);

        // The cleared day is marked skipped, not deleted — scheduledDaysMissingPlans()
        // must see it as "covered" so a later auto-fill doesn't undo the clear.
        $this->assertSame([], app(WeeklyPlanService::class)->scheduledDaysMissingPlans($user));
    }
}
