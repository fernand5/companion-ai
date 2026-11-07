<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\PlanReplacer;
use App\Services\Fitness\ExercisePerformanceService;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * The application writes a multi-day plan from a signed proposal, all or none, and reads it back before it commits.
 * These go at PlanReplacer and PlanReplacementProposal directly, where the chat flow cannot reach.
 */
class WeekPlanReplacerTest extends PerformanceEvalCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->user = $this->newUser();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(string $date, string $title, array $exercises): array
    {
        return [
            'date' => $date, 'existing_plan_id' => 0, 'existing_revision' => '', 'existing_summary' => PlanReplacementProposal::NOTHING_PLANNED, 'action' => 'create', 'reason' => 'x',
            'replacement' => ['activity_type' => 'strength', 'title' => $title, 'exercises' => $exercises, 'decision_summary' => 'x', 'reasoning_factors' => ['x']],
        ];
    }

    private function squat(?float $kg = null): array
    {
        return [['exercise_name' => 'Squat', 'planned_sets' => 3, 'planned_reps' => 10] + ($kg !== null ? ['planned_weight_kg' => $kg] : [])];
    }

    private function plans(): int
    {
        return WorkoutPlan::where('user_id', $this->user->id)->count();
    }

    public function test_two_empty_days_are_created_together(): void
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [
            $this->create('2026-10-31', 'Saturday', $this->squat()),
            ['date' => '2026-11-01', 'existing_plan_id' => 0, 'existing_revision' => '', 'existing_summary' => 'nothing planned', 'action' => 'clear', 'reason' => 'Rest day'],
        ]);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertIsArray($result);
        $this->assertSame(2, $this->plans());
        $this->assertTrue(WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', '2026-11-01')->first()->isRestDay());
    }

    public function test_a_load_above_the_limit_is_refused_again_when_it_is_applied(): void
    {
        $this->logSets($this->user, 1, 'Squat', [3, 10, 20], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 20]));
        $before = $this->plans();

        $proposal = PlanReplacementProposal::create($this->user->id, 1, [
            $this->create('2026-10-31', 'Fine', $this->squat()),
            $this->create('2026-11-01', 'Too heavy', $this->squat(40)),
        ]);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertIsString($result);
        $this->assertSame($before, $this->plans(), 'No day written: the first was fine, the second was not.');
    }

    public function test_a_load_with_no_baseline_is_left_unset_when_it_is_applied(): void
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [$this->create('2026-10-31', 'Day', $this->squat(60))]);

        app(PlanReplacer::class)->apply($this->user, $proposal);

        $plan = WorkoutPlan::with('exercises')->where('user_id', $this->user->id)->whereDate('planned_date', '2026-10-31')->first();
        $this->assertNull($plan->exercises->first()->planned_weight_kg);
    }

    public function test_what_was_written_is_read_back_and_a_missing_exercise_rolls_everything_back(): void
    {
        // A write that silently loses an exercise on the second day.
        $this->app->extend(WorkoutPlanService::class, fn (WorkoutPlanService $real) => new class(app(ExercisePerformanceService::class)) extends WorkoutPlanService
        {
            public function createOrReplace(User $user, array $data, bool $replaceExisting = false): WorkoutPlan
            {
                $plan = parent::createOrReplace($user, $data, $replaceExisting);

                if ($plan->planned_date->toDateString() === '2026-11-01') {
                    $plan->exercises()->delete();
                }

                return $plan;
            }
        });

        $proposal = PlanReplacementProposal::create($this->user->id, 1, [
            $this->create('2026-10-31', 'Saturday', $this->squat()),
            $this->create('2026-11-01', 'Sunday', $this->squat()),
        ]);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertIsString($result);
        $this->assertStringContainsString('2026-11-01 does not hold what was shown', $result);
        $this->assertSame(0, $this->plans(), 'Saturday was written, then rolled back with Sunday.');
    }

    public function test_a_day_shown_as_empty_must_still_be_empty(): void
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [
            $this->create('2026-10-31', 'Saturday', $this->squat()),
            $this->create('2026-11-01', 'Sunday', $this->squat()),
        ]);
        app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => '2026-11-01', 'activity_type' => 'strength', 'title' => 'Mine', 'source' => 'manual']);

        $result = app(PlanReplacer::class)->apply($this->user, $proposal);

        $this->assertSame('a plan appeared on 2026-11-01 after I asked.', $result);
        $this->assertSame(1, $this->plans());
    }

    // ---- the stored form -----------------------------------------------------------------------------------------------

    private function meta(array $change): array
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [$change]);

        return $proposal->toMeta();
    }

    public function test_a_create_names_no_existing_plan(): void
    {
        $change = $this->create('2026-10-31', 'Saturday', $this->squat());
        $this->assertNotNull(PlanReplacementProposal::fromMeta($this->meta($change)));

        foreach ([['existing_plan_id', 7], ['existing_revision', 'abc']] as [$field, $value]) {
            $meta = $this->meta($change);
            $meta['changes'][0][$field] = $value;
            $this->assertNull(PlanReplacementProposal::fromMeta($meta), "A create with {$field} set is not a create.");
        }
    }

    public function test_a_replace_must_name_the_plan_it_replaces_and_a_clear_has_no_replacement(): void
    {
        $meta = $this->meta($this->create('2026-10-31', 'Saturday', $this->squat()));
        $meta['changes'][0]['action'] = 'replace';
        $this->assertNull(PlanReplacementProposal::fromMeta($meta), 'A replace of plan 0 is not valid.');

        $meta = $this->meta($this->create('2026-10-31', 'Saturday', $this->squat()));
        $meta['changes'][0]['action'] = 'clear';
        $this->assertNull(PlanReplacementProposal::fromMeta($meta), 'A clear carries no replacement.');
    }

    public function test_the_signature_covers_a_created_day(): void
    {
        $proposal = PlanReplacementProposal::create($this->user->id, 1, [$this->create('2026-10-31', 'Saturday', $this->squat())]);
        $this->assertTrue($proposal->isAuthentic());

        $meta = $proposal->toMeta();
        $meta['changes'][0]['date'] = '2026-11-07';

        $this->assertFalse(PlanReplacementProposal::fromMeta($meta)->isAuthentic());
    }
}
