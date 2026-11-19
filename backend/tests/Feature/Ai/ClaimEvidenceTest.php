<?php

namespace Tests\Feature\Ai;

use App\Models\ActivityLog;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\AiCoachService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\ReplyText;
use App\Services\Fitness\WorkoutPlanService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3a refinement: what a claim about the plan is checked against is what the turn's tool results say was
 * written TO THE PLAN, not "some write succeeded". An activity that was logged is not a plan that was saved; a call that
 * was refused, errored, skipped or is waiting for the user is not a change; and a turn where one plan change worked and
 * another did not is not taken at the model's word that everything did. A correction only ever adds a notice.
 */
class ClaimEvidenceTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->user = $this->newUser();
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->today = $this->user->localToday();
        Cache::flush();
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function asSpanishUser(): void
    {
        $this->user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
    }

    private function toolCall(string $name, array $arguments, string $id = 'c'): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: $id, name: $name, arguments: $arguments)]);
    }

    private function logSoccer(): AiResponse
    {
        return $this->toolCall('log_activity', ['type' => 'sport', 'logged_date' => now()->subDay()->toDateString(), 'intensity' => 'high', 'metadata' => ['sport' => 'Soccer']], 'log');
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function weekly(array $changes, bool $valid = true): AiResponse
    {
        return $this->toolCall('propose_weekly_plan_changes', ($valid ? ['decision_summary' => 'Adapted after the match.', 'reasoning_factors' => ['Played soccer yesterday.']] : []) + ['changes' => $changes], 'week');
    }

    private function recoveryOn(string $date, string $title = 'Active Recovery'): array
    {
        return ['date' => $date, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => $title, 'reason' => 'High load yesterday.'];
    }

    private function saveCall(): AiResponse
    {
        return $this->toolCall('create_workout_plan', [
            'activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'Saved as asked.', 'reasoning_factors' => ['You asked to add it'],
            'exercises' => [
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ], 'save');
    }

    private function conversation(): int
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
    }

    private function say(string $message, int $conversation, AiResponse ...$responses): Message
    {
        Cache::flush();
        $this->fake->queue(...$responses);
        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail();
    }

    /** @return array<int, string> */
    private function codes(Message $message): array
    {
        return array_column($message->meta[ReplyText::META_KEY]['segments'] ?? [], 'code');
    }

    private function existingPlan(string $date, string $status = WorkoutPlan::STATUS_PLANNED): WorkoutPlan
    {
        $plan = app(WorkoutPlanService::class)->createOrReplace($this->user, [
            'planned_date' => $date, 'activity_type' => 'strength', 'title' => 'Old plan',
            'exercises' => [['exercise_name' => 'Squats', 'planned_sets' => 3, 'planned_reps' => 10]],
        ], replaceExisting: true);

        if ($status !== WorkoutPlan::STATUS_PLANNED) {
            $plan->forceFill(['status' => $status])->save();
        }

        return $plan;
    }

    private function planOn(string $date): ?WorkoutPlan
    {
        return WorkoutPlan::where('user_id', $this->user->id)->whereDate('planned_date', $date)->first();
    }

    private function tomorrow(): string
    {
        return now()->parse($this->today)->addDay()->toDateString();
    }

    // ---- the activity was logged, the plan was not --------------------------------------------------------------------

    public function test_a_logged_activity_does_not_make_a_plan_saved_claim_true_when_the_plan_save_was_refused(): void
    {
        // "I played soccer yesterday" lets the model log it and adapt the week; it does NOT let it write a day's plan.
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->saveCall(), new AiResponse(text: "I've logged your match. I've added the workout to your plan."));

        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->count(), 'The activity was recorded.');
        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->count());
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($message));
        $this->assertStringContainsString("I haven't changed your plan: nothing was saved this time", $message->content);
    }

    public function test_a_false_plan_saved_claim_after_a_logged_activity_is_corrected_in_spanish(): void
    {
        $this->asSpanishUser();
        $message = $this->say('Jugué fútbol ayer. I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->saveCall(), new AiResponse(text: 'Registré tu partido y agregué el entrenamiento a tu plan.'));

        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->count());
        $this->assertSame(0, WorkoutPlan::where('user_id', $this->user->id)->count());
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($message));
        $this->assertStringContainsString('No he cambiado tu plan', $message->content);
    }

    public function test_a_logged_activity_does_not_make_a_weekly_update_claim_true_when_the_update_failed(): void
    {
        $this->existingPlan($this->today);

        // The update is malformed (a day that is not a date): it errors, writes nothing.
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([['date' => 'not-a-date'] + $this->recoveryOn($this->today)]), new AiResponse(text: 'I updated your weekly plan.'));

        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->count());
        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($message));
        $this->assertStringStartsWith('I updated your weekly plan.', $message->content);
    }

    public function test_a_weekly_update_that_changed_no_day_does_not_make_the_claim_true(): void
    {
        // The only day was already completed: the tool succeeds as a call and reports it skipped.
        $this->existingPlan($this->today, WorkoutPlan::STATUS_COMPLETED);

        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([$this->recoveryOn($this->today)]), new AiResponse(text: "I've updated your plan for today."));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->codes($message));
    }

    public function test_a_replacement_waiting_for_confirmation_is_never_reported_as_applied(): void
    {
        $this->existingPlan($this->today);

        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([$this->recoveryOn($this->today)]), new AiResponse(text: "I've changed today's workout to recovery."));

        $this->assertSame('Old plan', $this->planOn($this->today)->title, 'Held: nothing was written.');
        $this->assertSame([NoticeCode::REPLACEMENT_QUESTION], $this->codes($message), 'The application is already asking; no second correction is added.');
        $this->assertStringContainsString("I haven't changed your plan", $message->content);
    }

    // ---- valid claims are left alone ----------------------------------------------------------------------------------

    public function test_activity_and_weekly_update_both_succeeding_is_a_true_claim_and_gets_no_correction(): void
    {
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([$this->recoveryOn($this->today)]), new AiResponse(text: "I've logged your match and updated your plan: today is now active recovery."));

        $this->assertSame(1, ActivityLog::where('user_id', $this->user->id)->count());
        $this->assertSame('Active Recovery', $this->planOn($this->today)->title);
        $this->assertNull($message->meta[ReplyText::META_KEY] ?? null);
        $this->assertSame("I've logged your match and updated your plan: today is now active recovery.", $message->content);
    }

    public function test_the_same_valid_flow_in_spanish_gets_no_correction(): void
    {
        $this->asSpanishUser();
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([$this->recoveryOn($this->today)]), new AiResponse(text: 'Registré tu partido y actualicé tu plan: hoy es recuperación activa.'));

        $this->assertSame('Active Recovery', $this->planOn($this->today)->title);
        $this->assertNull($message->meta[ReplyText::META_KEY] ?? null);
    }

    public function test_a_logged_activity_with_no_claim_about_the_plan_gets_no_correction(): void
    {
        $message = $this->say('I played soccer yesterday.', $this->conversation(), $this->logSoccer(), new AiResponse(text: 'Nice, I logged your match. How do your legs feel?'));

        $this->assertNull($message->meta[ReplyText::META_KEY] ?? null);
    }

    // ---- one plan change worked, another did not -----------------------------------------------------------------------

    public function test_a_workout_save_does_not_make_a_claim_about_another_plan_change_true(): void
    {
        $conversation = $this->conversation();
        $this->say('Give me a quick upper body workout', $conversation, new AiResponse(text: "Here's a 30-minute session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds"));
        $monday = $this->tomorrow();

        // The save is exactly what was asked and works. The model also tries to change another day, which this request
        // does not allow (refused), and then says both were done.
        $message = $this->say('Add this workout to my dashboard', $conversation,
            $this->saveCall(),
            $this->weekly([$this->recoveryOn($monday)]),
            new AiResponse(text: "I've saved the workout to your plan and updated your plan for tomorrow too."));

        $this->assertNotNull($this->planOn($this->today), 'The requested workout was saved.');
        $this->assertNull($this->planOn($monday), 'The other change was refused.');
        $this->assertSame([NoticeCode::CLAIM_PARTIAL_CORRECTED], $this->codes($message));
        $this->assertStringContainsString('not everything was changed', $message->content);
        $this->assertNotEmpty($message->meta['blocked_tool_calls']);
    }

    public function test_a_workout_save_does_not_validate_a_spanish_claim_about_another_change(): void
    {
        $this->asSpanishUser();
        $conversation = $this->conversation();
        $this->say('Give me a quick upper body workout', $conversation, new AiResponse(text: "Here's a 30-minute session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds"));

        $message = $this->say('Add this workout to my dashboard', $conversation,
            $this->saveCall(), $this->weekly([$this->recoveryOn($this->tomorrow())]),
            new AiResponse(text: 'Guardé el entrenamiento en tu plan y actualicé también tu plan de mañana.'));

        $this->assertNull($this->planOn($this->tomorrow()));
        $this->assertSame([NoticeCode::CLAIM_PARTIAL_CORRECTED], $this->codes($message));
        $this->assertStringContainsString('no todo lo que pediste se cambió', $message->content);
    }

    public function test_a_plain_successful_save_still_needs_no_correction(): void
    {
        $conversation = $this->conversation();
        $this->say('Give me a quick upper body workout', $conversation, new AiResponse(text: "Here's a 30-minute session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10\n3. Plank 3x30 seconds"));

        $message = $this->say('Add this workout to my dashboard', $conversation, $this->saveCall(), new AiResponse(text: "I've saved the workout to your plan."));

        $this->assertNotNull($this->planOn($this->today));
        $this->assertNull($message->meta[ReplyText::META_KEY] ?? null);
    }

    public function test_a_weekly_update_that_changed_some_days_and_skipped_one_is_not_taken_at_the_models_word(): void
    {
        $this->existingPlan($this->today, WorkoutPlan::STATUS_COMPLETED);

        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), $this->weekly([$this->recoveryOn($this->today), $this->recoveryOn($this->tomorrow())]),
            new AiResponse(text: 'I updated your weekly plan.'));

        $this->assertSame('Old plan', $this->planOn($this->today)->title);
        $this->assertSame('Active Recovery', $this->planOn($this->tomorrow())->title);
        $this->assertSame([NoticeCode::CLAIM_PARTIAL_CORRECTED], $this->codes($message));
    }

    public function test_a_retry_that_worked_makes_good_an_earlier_failed_attempt(): void
    {
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(),
            $this->weekly([$this->recoveryOn($this->today)], valid: false),
            $this->weekly([$this->recoveryOn($this->today)]),
            new AiResponse(text: 'I updated your plan: today is now active recovery.'));

        $this->assertSame('Active Recovery', $this->planOn($this->today)->title);
        $this->assertNull($message->meta[ReplyText::META_KEY] ?? null);
    }

    // ---- what a "no access" correction says --------------------------------------------------------------------------

    public function test_the_no_access_correction_does_not_say_nothing_changed_when_an_activity_was_logged(): void
    {
        $message = $this->say('I played soccer yesterday.', $this->conversation(),
            $this->logSoccer(), new AiResponse(text: "I'm sorry, I don't have access to the plan tool."));

        $this->assertStringContainsString('To be clear, that is not a limit of the app', $message->content);
        $this->assertStringNotContainsString("I haven't changed anything this time.", $message->content, 'The activity WAS recorded.');
        $this->assertSame([NoticeCode::CLAIM_ACCESS_CORRECTED], $this->codes($message));
    }

    // ---- the evidence itself, call by call ------------------------------------------------------------------------------

    /** @return array{any: bool, plan: bool, unfinished: bool} */
    private function outcomes(array $trace): array
    {
        $method = new ReflectionMethod(AiCoachService::class, 'writeOutcomes');

        return $method->invoke(app(AiCoachService::class), array_map(fn (array $c) => ['name' => $c[0], 'arguments' => [], 'result' => $c[1]], $trace));
    }

    public static function traces(): array
    {
        $ok = ['planned_date' => '2026-11-12', 'title' => 'Upper body'];
        $error = ['error' => 'Nothing was changed.'];
        $held = ['error' => 'Nothing was changed yet.', 'pending_confirmation' => true];

        return [
            'nothing' => [[], false, false, false],
            'reads only' => [[['get_todays_plan', $ok], ['get_recent_activity', ['activities' => []]]], false, false, false],
            'activity logged' => [[['log_activity', ['id' => 1]]], true, false, false],
            'activity failed' => [[['log_activity', $error]], false, false, false],
            'weight recorded' => [[['update_weight', ['weight_kg' => 80]]], true, false, false],
            'preference remembered' => [[['remember_preference', ['status' => 'remembered']]], true, false, false],
            'profile updated' => [[['update_fitness_profile', ['updated' => ['goal']]]], true, false, false],
            'workout logged' => [[['log_workout', ['id' => 3]]], true, false, false],
            'activity logged, plan save refused' => [[['log_activity', ['id' => 1]], ['create_workout_plan', $error + ['blocked' => true, 'reason' => 'not_authorized']]], true, false, true],
            'workout saved' => [[['create_workout_plan', $ok]], true, true, false],
            'workout save errored' => [[['create_workout_plan', $error]], false, false, true],
            'workout held for confirmation' => [[['create_workout_plan', $held]], false, false, true],
            'errored then saved (a retry)' => [[['create_workout_plan', $error], ['create_workout_plan', $ok]], true, true, false],
            'saved then errored (another day)' => [[['create_workout_plan', $ok], ['create_workout_plan', $error]], true, true, true],
            'saved then repeated and refused as a repeat' => [[['create_workout_plan', $ok], ['create_workout_plan', $error + ['reason' => 'save_already_handled']]], true, true, false],
            'confirmed change repeated' => [[['create_workout_plan', $ok], ['create_workout_plan', $error + ['reason' => 'confirmation_already_used']]], true, true, false],
            'week: days applied' => [[['propose_weekly_plan_changes', ['applied' => ['2026-11-12'], 'skipped' => [], 'cleared' => []]]], true, true, false],
            'week: day cleared' => [[['propose_weekly_plan_changes', ['applied' => [], 'skipped' => [], 'cleared' => ['2026-11-12']]]], true, true, false],
            'week: applied and one skipped' => [[['propose_weekly_plan_changes', ['applied' => ['2026-11-12'], 'skipped' => [['date' => '2026-11-13', 'reason' => 'already_completed']], 'cleared' => []]]], true, true, true],
            'week: applied and one held' => [[['propose_weekly_plan_changes', ['applied' => ['2026-11-12'], 'skipped' => [], 'cleared' => [], 'held_for_confirmation' => ['2026-11-13']]]], true, true, true],
            'week: everything skipped' => [[['propose_weekly_plan_changes', ['applied' => [], 'skipped' => [['date' => '2026-11-12', 'reason' => 'already_started']], 'cleared' => []]]], false, false, true],
            'week: everything held' => [[['propose_weekly_plan_changes', ['applied' => [], 'skipped' => [], 'cleared' => [], 'held_for_confirmation' => ['2026-11-12']]]], false, false, true],
            'week: no changes asked' => [[['propose_weekly_plan_changes', ['applied' => [], 'skipped' => [], 'cleared' => []]]], false, false, false],
            'week: errored' => [[['propose_weekly_plan_changes', $error]], false, false, true],
            'week: skipped, then a retry applied' => [[['propose_weekly_plan_changes', ['applied' => [], 'skipped' => [['date' => 'x', 'reason' => 'already_started']], 'cleared' => []]], ['propose_weekly_plan_changes', ['applied' => ['2026-11-13'], 'skipped' => [], 'cleared' => []]]], true, true, false],
            'exercise progress stored' => [[['update_exercise_status', $ok]], true, true, false],
            'exercise not found' => [[['update_exercise_status', $error]], false, false, true],
            'schedule entry added' => [[['add_training_schedule_entry', ['activity_type' => 'run', 'day_of_week' => 1]]], true, true, false],
            'a result that is not a result' => [[['create_workout_plan', 'ok'], ['log_activity', null]], false, false, true],
        ];
    }

    #[DataProvider('traces')]
    public function test_the_evidence_is_read_from_each_tools_own_result(array $trace, bool $any, bool $plan, bool $unfinished): void
    {
        $this->assertSame(['any' => $any, 'plan' => $plan, 'unfinished' => $unfinished], $this->outcomes($trace));
    }
}
