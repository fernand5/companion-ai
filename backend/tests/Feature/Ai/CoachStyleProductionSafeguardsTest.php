<?php

namespace Tests\Feature\Ai;

use App\Models\FitnessProfile;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PendingProposalStore;
use App\Services\Ai\CoachingPreferences;
use App\Services\Ai\CoachStyle;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Ai\ResponseDetail;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Fitness\WorkoutPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 6 on top of the production safeguards: the coach's style and response detail change wording, and
 * nothing else.
 *
 * Every flow that writes to a user's plan (a single workout, a plan of several days, a replacement the user
 * confirms, a retry, a protected day, a load above the limit, a status the user never reported) is replayed
 * with a scripted model under each of the nine style/detail combinations, with a fresh user for each, and
 * the outcome has to be IDENTICAL: the same rows in the database and the same reply, word for word, from the
 * application. The model is also sent the right voice on every call it makes.
 *
 * A baseline that "agrees with itself" would prove nothing if the flow quietly did nothing, so each test also
 * states what the flow must have done.
 */
class CoachStyleProductionSafeguardsTest extends PerformanceEvalCase
{
    private FakeAiProvider $fake;

    /** The week is Mon 26 Oct - Sun 1 Nov 2026; "today" is Thursday the 29th. */
    private const TODAY = '2026-10-29';

    private const SATURDAY = '2026-10-31';

    private const SUNDAY = '2026-11-01';

    private const PLAN = "Here is a plan for the rest of the week:\n\n**Saturday: Full Body Strength**\n* Goblet Squats: 3 sets of 10-12 reps\n* Push-ups: 3 sets of 8-12 reps\n* Plank: 3 sets of 30 seconds\n\n**Sunday: Active Recovery**\n* Easy walk: 30 minutes\n* Light stretching: 10 minutes\n\nIf this plan looks good to you, just say \"add this plan to my dashboard\" and I'll update it for Saturday and Sunday!";

    private const STYLE_MARKER = [
        'supportive' => 'supportive: empathetic, encouraging and patient',
        'balanced' => 'balanced: practical, motivating and direct',
        'intense' => 'intense: energetic, challenging and accountable',
    ];

    private const DETAIL_MARKER = [
        'concise' => 'concise: lead with the answer in 1-3 sentences',
        'standard' => 'standard: the answer first, then 1-2 short reasons',
        'detailed' => 'detailed: the answer first, then fuller reasoning',
    ];

    private const BASELINE = 'balanced / standard';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->travelTo(Carbon::parse(self::TODAY.' 10:00', 'America/Bogota'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- harness ----------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function voices(): array
    {
        $all = [];
        foreach (CoachStyle::values() as $style) {
            foreach (ResponseDetail::values() as $detail) {
                $all["{$style} / {$detail}"] = [$style, $detail];
            }
        }

        return $all;
    }

    /**
     * Runs $scenario once for each of the nine voices, each with its own user, and returns what each produced.
     * Fails at once if any model call in any run was not sent that user's voice.
     *
     * @param  callable(User): array  $scenario
     * @return array<string, array>
     */
    private function underEveryVoice(callable $scenario): array
    {
        $results = [];

        foreach (self::voices() as $label => [$style, $detail]) {
            $this->travelTo(Carbon::parse(self::TODAY.' 10:00', 'America/Bogota'));
            $this->fake->calls = [];
            (new \ReflectionProperty($this->fake, 'queue'))->setValue($this->fake, []); // nothing left over from the previous voice

            $user = $this->newUser();
            FitnessProfile::updateOrCreate(['user_id' => $user->id], ['coach_style' => $style, 'response_detail' => $detail]);
            $user = User::findOrFail($user->id);

            $results[$label] = $scenario($user);

            $this->assertNotEmpty($this->fake->calls, "{$label}: the scenario made no model call, so it proved nothing about the prompt.");

            foreach ($this->fake->calls as $i => $call) {
                $system = $call['messages'][0]['content'];
                $this->assertStringContainsString(self::STYLE_MARKER[$style], $system, "{$label}: model call {$i} was not sent the chosen style");
                $this->assertStringContainsString(self::DETAIL_MARKER[$detail], $system, "{$label}: model call {$i} was not sent the chosen detail");

                foreach (self::STYLE_MARKER as $other => $marker) {
                    if ($other !== $style) {
                        $this->assertStringNotContainsString($marker, $system, "{$label}: model call {$i} carried the {$other} style too");
                    }
                }
                foreach (self::DETAIL_MARKER as $other => $marker) {
                    if ($other !== $detail) {
                        $this->assertStringNotContainsString($marker, $system, "{$label}: model call {$i} carried the {$other} detail too");
                    }
                }
            }
        }

        $this->assertCount(9, $results);

        return $results;
    }

    /** Every voice must have produced exactly what the default voice produced. */
    private function assertNoVoiceChangesTheOutcome(array $results): void
    {
        $baseline = $results[self::BASELINE];

        foreach ($results as $label => $result) {
            $this->assertSame($baseline, $result, "{$label} changed the outcome compared with ".self::BASELINE.'.');
        }
    }

    /** The plans in the database as data a voice must not be able to change (no ids, no timestamps). */
    private function plansOf(User $user): array
    {
        return WorkoutPlan::with('exercises')->where('user_id', $user->id)->orderBy('planned_date')->get()->map(fn (WorkoutPlan $plan) => [
            'date' => Carbon::parse($plan->planned_date)->toDateString(),
            'title' => $plan->title,
            'type' => $plan->activity_type,
            'status' => $plan->status,
            'exercises' => $plan->exercises->sortBy('position')->map(fn (WorkoutPlanExercise $e) => [
                $e->exercise_name, $e->planned_sets, $e->planned_reps, $e->planned_weight_kg, $e->planned_duration_seconds,
                $e->status, $e->actual_sets, $e->actual_reps, $e->actual_weight_kg, $e->actual_duration_seconds,
            ])->values()->all(),
        ])->all();
    }

    private function conversationWith(User $user, string $reply, string $request = 'create a plan for the remainder of the week'): int
    {
        $conversation = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        Message::create(['conversation_id' => $conversation, 'role' => 'user', 'content' => $request, 'meta' => null]);
        Message::create(['conversation_id' => $conversation, 'role' => 'assistant', 'content' => $reply, 'meta' => null]);

        return $conversation;
    }

    /** @return array{answer: string, calls: int, meta: array} */
    private function say(User $user, string $message, int $conversation, AiResponse ...$responses): array
    {
        $before = count($this->fake->calls);
        $this->fake->queue(...$responses);
        $result = $this->ask($user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return [
            'answer' => $result['answer'],
            'calls' => count($this->fake->calls) - $before,
            'meta' => Message::where('conversation_id', $conversation)->where('role', 'assistant')->orderByDesc('id')->firstOrFail()->meta ?? [],
        ];
    }

    private function saturday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SATURDAY, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Full Body Strength', 'reason' => 'As in the plan you were shown',
            'exercises' => [
                ['exercise_name' => 'Goblet Squats', 'planned_sets' => 3, 'planned_reps' => 11],
                ['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ];
    }

    private function sunday(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::SUNDAY, 'action' => 'replace', 'activity_type' => 'recovery', 'title' => 'Active Recovery', 'reason' => 'As in the plan you were shown',
            'exercises' => [
                ['exercise_name' => 'Easy walk', 'planned_duration_seconds' => 1800],
                ['exercise_name' => 'Light stretching', 'planned_duration_seconds' => 600],
            ],
        ];
    }

    private function weekCall(array $changes): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
            'decision_summary' => 'The plan you were shown.', 'reasoning_factors' => ['You asked to add this plan'], 'changes' => $changes,
        ])]);
    }

    private function existing(User $user, string $date, string $title = 'My own session'): WorkoutPlan
    {
        return app(WorkoutPlanService::class)->createOrReplace($user, [
            'planned_date' => $date, 'activity_type' => 'strength', 'title' => $title, 'source' => 'manual',
            'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 8]],
        ]);
    }

    // ---- the nine combinations compose the prompt as intended, on top of the production prompt -----------------

    public function test_all_nine_combinations_send_the_voice_and_keep_the_production_rules(): void
    {
        $this->underEveryVoice(function (User $user): array {
            $this->fake->queue(new AiResponse(text: 'Noted.'));
            $result = $this->ask($user, 'What should I do today?');
            $this->assertNull($result['error'], (string) $result['error']);

            $system = $this->fake->calls[0]['messages'][0]['content'];

            // What production added after Phase 6 was written is still in every prompt.
            $this->assertStringContainsString('## Plans for several days', $system);
            $this->assertStringContainsString('the application then saves ALL of its days together, or none', $system);
            $this->assertStringContainsString('## App navigation', $system);
            $this->assertStringContainsString('Progress: adherence chart, weekly summary, performance history and the load progression recommendations', $system);
            $this->assertStringContainsString('Safety: you are a fitness/wellness coach, not a medical professional.', $system);

            // The voice comes before the rules, and the old fixed tone line is gone, so there is one voice, not two.
            $this->assertLessThan(strpos($system, 'Critical rules:'), strpos($system, '## Coaching voice'));
            $this->assertStringNotContainsString('Tone: supportive, practical, concise', $system);

            return [];
        });
    }

    public function test_a_concise_voice_does_not_cut_a_plan_the_application_has_to_save(): void
    {
        $this->underEveryVoice(function (User $user): array {
            $this->fake->queue(new AiResponse(text: 'Noted.'));
            $this->ask($user, 'Give me a plan for the rest of the week');
            $system = $this->fake->calls[0]['messages'][0]['content'];

            $this->assertStringContainsString('write any workout or plan you give out in full (every day and exercise with its sets, reps or time)', $system);
            $this->assertStringContainsString('detail sets how much you explain around it, never how much of it you write', $system);

            return [];
        });
    }

    public function test_the_performance_reading_rules_defer_to_the_chosen_voice_instead_of_overriding_it(): void
    {
        $user = $this->newUser();
        $this->logSets($user, 2, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));

        foreach (self::voices() as $label => [$style, $detail]) {
            FitnessProfile::updateOrCreate(['user_id' => $user->id], ['coach_style' => $style, 'response_detail' => $detail]);
            $this->fake->calls = [];
            $this->fake->queue(new AiResponse(text: 'ok'));
            $this->ask(User::findOrFail($user->id), 'Did I get stronger on bench?');
            $system = $this->fake->calls[0]['messages'][0]['content'];

            $this->assertStringContainsString('## Reading "Recent performance"', $system, "{$label}: the reading rules are present");
            $this->assertStringContainsString('answer in your coaching voice and detail (set above)', $system, $label);
            $this->assertStringNotContainsString('be concise and encouraging', $system, "{$label}: a fixed length and tone line would contradict the chosen voice");
            $this->assertStringContainsString('Never prescribe a maximal effort, a load jump justified by this history alone, or pushing harder because a target was missed', $system, "{$label}: the safety rule is untouched");
        }
    }

    public function test_concise_costs_no_more_prompt_than_standard_and_standard_no_more_than_detailed(): void
    {
        $builder = app(SystemPromptBuilder::class);
        $size = fn (string $detail) => strlen($builder->voiceSection(new CoachingPreferences(CoachStyle::Balanced, ResponseDetail::from($detail))));

        $this->assertLessThanOrEqual($size('standard'), $size('concise'));
        $this->assertLessThan($size('detailed'), $size('standard'));

        foreach (CoachStyle::cases() as $style) {
            $whole = fn (ResponseDetail $d) => strlen($builder->build('CONTEXT', new CoachingPreferences($style, $d)));
            $this->assertLessThanOrEqual($whole(ResponseDetail::Standard), $whole(ResponseDetail::Concise), "{$style->value}: a concise prompt is not longer than the standard one");
        }
    }

    // ---- single workout -----------------------------------------------------------------------------------------------

    public function test_saving_a_single_workout_is_the_same_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, "Here's a quick upper-body session:\n1. Push-ups 3x12\n2. Dumbbell rows 3x10", 'give me a quick upper body workout');
            $call = new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
                'activity_type' => 'strength', 'title' => 'Upper body', 'decision_summary' => 'x', 'reasoning_factors' => ['x'],
                'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Dumbbell rows', 'planned_sets' => 3, 'planned_reps' => 10]],
            ])]);

            $turn = $this->say($user, 'add this workout to my dashboard', $conversation, $call, new AiResponse(text: 'Added.'));

            return ['turn' => $turn, 'plans' => $this->plansOf($user)];
        });

        $plans = $results[self::BASELINE]['plans'];
        $this->assertCount(1, $plans);
        $this->assertSame(self::TODAY, $plans[0]['date']);
        $this->assertSame('Push-ups', $plans[0]['exercises'][0][0]);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- several days ---------------------------------------------------------------------------------------------------

    public function test_saving_the_production_week_plan_is_the_same_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            $turn = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Done, both days are on your dashboard!'));

            return ['turn' => $turn, 'plans' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertSame([self::SATURDAY, self::SUNDAY], array_column($base['plans'], 'date'), 'exactly the two days shown');
        $this->assertStringStartsWith('Done. I added this to your plan, all together, and checked that it is saved:', $base['turn']['answer']);
        $this->assertStringNotContainsString('both days are on your dashboard', $base['turn']['answer'], 'the application speaks for what was saved, in every voice');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_a_plan_that_cannot_be_saved_whole_saves_nothing_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            // The second day is one the model was never authorized to write.
            $turn = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday(['date' => '2026-11-02'])]), new AiResponse(text: 'Saved!'));

            return ['turn' => $turn, 'plans' => $this->plansOf($user)];
        });

        $this->assertSame([], $results[self::BASELINE]['plans'], 'nothing is half saved');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- replacement confirmation -----------------------------------------------------------------------------------------

    public function test_a_replacement_needs_the_same_confirmation_and_makes_the_same_change_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $this->existing($user, self::SUNDAY);
            $conversation = $this->conversationWith($user, self::PLAN);

            $held = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'Saved!'));
            $afterHold = $this->plansOf($user);
            $yes = $this->say($user, 'yes', $conversation);

            return [
                'held' => $held['answer'], 'held_has_proposal' => isset($held['meta'][PendingProposalStore::REPLACEMENT_META_KEY]),
                'after_hold' => $afterHold, 'yes' => $yes, 'after_yes' => $this->plansOf($user),
            ];
        });

        $base = $results[self::BASELINE];
        $this->assertTrue($base['held_has_proposal']);
        $this->assertSame(['2026-11-01'], array_column($base['after_hold'], 'date'), 'before the yes, only the user\'s own Sunday exists');
        $this->assertSame('My own session', $base['after_hold'][0]['title']);
        $this->assertSame(0, $base['yes']['calls'], 'the application applies the signed proposal; no model call, so no voice can matter');
        $this->assertSame([self::SATURDAY, self::SUNDAY], array_column($base['after_yes'], 'date'));
        $this->assertSame('Active Recovery', $base['after_yes'][1]['title']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_an_unconfirmed_replacement_leaves_the_plan_alone_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $this->existing($user, self::SUNDAY);
            $conversation = $this->conversationWith($user, self::PLAN);
            $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));

            // The user talks about something else instead of confirming.
            $other = $this->say($user, 'Thanks, what should I eat after training?', $conversation, new AiResponse(text: 'Protein and carbs.'));

            return ['other' => $other['answer'], 'plans' => $this->plansOf($user)];
        });

        $this->assertSame(['2026-11-01'], array_column($results[self::BASELINE]['plans'], 'date'));
        $this->assertSame('My own session', $results[self::BASELINE]['plans'][0]['title']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- retry -----------------------------------------------------------------------------------------------------------

    public function test_a_retry_inside_the_window_behaves_the_same_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            $bad = $this->weekCall([$this->saturday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]]), $this->sunday()]);

            $first = $this->say($user, 'add this plan to my dashboard', $conversation, $bad, $bad, $bad, new AiResponse(text: 'Done!'));
            $afterFirst = $this->plansOf($user);

            $this->travelTo(Carbon::parse(self::TODAY.' 10:05', 'America/Bogota'));
            $retry = $this->say($user, 'could you try to add them again?', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'ok'));

            return ['first' => $first['answer'], 'after_first' => $afterFirst, 'retry' => $retry['answer'], 'after_retry' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertSame([], $base['after_first']);
        $this->assertCount(2, $base['after_retry'], 'a retry inside the window is the same request');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_a_retry_after_the_window_is_refused_the_same_way_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            $bad = $this->weekCall([$this->saturday(['exercises' => [['exercise_name' => 'Burpees', 'planned_sets' => 3, 'planned_reps' => 10]]]), $this->sunday()]);
            $this->say($user, 'add this plan to my dashboard', $conversation, $bad, $bad, $bad, new AiResponse(text: 'Done!'));

            $this->travelTo(Carbon::parse(self::TODAY.' 10:30', 'America/Bogota'));
            $late = $this->say($user, 'try again', $conversation);

            return ['late' => $late['answer'], 'plans' => $this->plansOf($user)];
        });

        $this->assertStringContainsString('more than 15 minutes old', $results[self::BASELINE]['late']);
        $this->assertSame([], $results[self::BASELINE]['plans']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- protected completed and partial workouts ------------------------------------------------------------------------

    public function test_a_completed_day_blocks_the_whole_plan_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            $done = WorkoutPlan::factory()->for($user)->create(['planned_date' => self::SUNDAY, 'status' => WorkoutPlan::STATUS_COMPLETED, 'title' => 'Done already']);
            WorkoutPlanExercise::factory()->for($done, 'workoutPlan')->create(['exercise_name' => 'Squat', 'status' => WorkoutPlanExercise::STATUS_COMPLETED, 'position' => 0]);

            $turn = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'All saved!'));

            return ['answer' => $turn['answer'], 'plans' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertSame([self::SUNDAY], array_column($base['plans'], 'date'), 'Saturday is not saved either');
        $this->assertSame('Done already', $base['plans'][0]['title']);
        $this->assertStringContainsString("I didn't save any day of it", $base['answer']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_a_partly_performed_day_cannot_be_replaced_even_with_a_confirmation_in_hand_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $this->existing($user, self::SUNDAY);
            $conversation = $this->conversationWith($user, self::PLAN);
            $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$this->saturday(), $this->sunday()]), new AiResponse(text: 'x'));

            // After the question was shown, the user did part of Sunday's session.
            WorkoutPlan::where('user_id', $user->id)->whereDate('planned_date', self::SUNDAY)->firstOrFail()
                ->exercises->first()->update(['status' => WorkoutPlanExercise::STATUS_PARTIAL]);
            $before = $this->plansOf($user);

            $yes = $this->say($user, 'yes', $conversation);

            return ['yes' => $yes['answer'], 'unchanged' => $before === $this->plansOf($user), 'plans' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertTrue($base['unchanged'], 'the confirmation changed nothing');
        $this->assertStringContainsString("I haven't replaced it", $base['yes']);
        $this->assertSame([self::SUNDAY], array_column($base['plans'], 'date'));
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- loads ------------------------------------------------------------------------------------------------------------

    public function test_a_load_above_the_limit_is_refused_under_every_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $this->logSets($user, 1, 'Goblet Squats', [3, 10, 20], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 20]));
            $before = $this->plansOf($user);
            $conversation = $this->conversationWith($user, self::PLAN);
            $heavy = $this->saturday();
            $heavy['exercises'][0]['planned_weight_kg'] = 40;

            $turn = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$heavy, $this->sunday()]), new AiResponse(text: 'Saved!'));

            return ['answer' => $turn['answer'], 'unchanged' => $before === $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertTrue($base['unchanged'], 'no day of the plan was written');
        $this->assertStringContainsString('Nothing was saved for any day.', $base['answer']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_no_starting_weight_is_invented_under_any_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            $conversation = $this->conversationWith($user, self::PLAN);
            $withWeight = $this->saturday();
            $withWeight['exercises'][0]['planned_weight_kg'] = 40;

            $turn = $this->say($user, 'add this plan to my dashboard', $conversation, $this->weekCall([$withWeight, $this->sunday()]), new AiResponse(text: 'Done.'));

            return ['answer' => $turn['answer'], 'plans' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertNull($base['plans'][0]['exercises'][0][3], 'no baseline, no load');
        $this->assertStringContainsString('left the starting weight for Goblet Squats unset', $base['answer']);
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_the_weekly_plan_page_cannot_raise_a_load_under_any_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            foreach ([9, 5, 2] as $ago) {
                $this->logSets($user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
            }
            $date = Carbon::parse(self::TODAY)->addDays(2)->toDateString();
            app(WorkoutPlanService::class)->createOrReplace($user, ['planned_date' => $date, 'activity_type' => 'strength', 'title' => 'Bench', 'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]]]);
            $before = $this->plansOf($user);

            $this->fake->queue(new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'w', name: 'propose_weekly_plan_changes', arguments: [
                'decision_summary' => 'Heavier.', 'reasoning_factors' => ['x'],
                'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Bench', 'reason' => 'x',
                    'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 80]]]],
            ])]), new AiResponse(text: 'Done.'));
            $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Make bench much heavier.'])->assertOk();

            return ['unchanged' => $before === $this->plansOf($user), 'plans' => $this->plansOf($user)];
        });

        $this->assertTrue($results[self::BASELINE]['unchanged'], 'the load stays at the approved 40 kg');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- performance is never made up ------------------------------------------------------------------------------------------

    public function test_a_status_the_user_never_reported_is_refused_and_no_performance_appears_under_any_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            app(WorkoutPlanService::class)->createOrReplace($user, [
                'planned_date' => self::TODAY, 'activity_type' => 'strength', 'title' => 'Today',
                'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 10]],
            ]);
            $call = new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'p', name: 'update_exercise_status', arguments: ['exercise_name' => 'Push-ups', 'status' => 'completed'])]);
            $conversation = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');

            $turn = $this->say($user, 'What should I do today?', $conversation, $call, new AiResponse(text: 'Do your push-ups.'));

            return ['blocked' => array_column($turn['meta']['blocked_tool_calls'] ?? [], 'tool'), 'plans' => $this->plansOf($user)];
        });

        $base = $results[self::BASELINE];
        $this->assertSame(['update_exercise_status'], $base['blocked']);
        $this->assertSame('pending', $base['plans'][0]['exercises'][0][5], 'a question does not complete anything');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    public function test_a_reported_completion_records_completion_only_never_made_up_sets_under_any_voice(): void
    {
        $results = $this->underEveryVoice(function (User $user): array {
            app(WorkoutPlanService::class)->createOrReplace($user, [
                'planned_date' => self::TODAY, 'activity_type' => 'strength', 'title' => 'Today',
                'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 10]],
            ]);
            $call = new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'p', name: 'update_exercise_status', arguments: ['exercise_name' => 'Push-ups', 'status' => 'completed'])]);
            $conversation = $this->actingAs($user, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');

            $this->say($user, 'I finished my push-ups today.', $conversation, $call, new AiResponse(text: 'Marked done.'));

            return ['plans' => $this->plansOf($user)];
        });

        $exercise = $results[self::BASELINE]['plans'][0]['exercises'][0];
        $this->assertSame('completed', $exercise[5]);
        $this->assertSame([null, null, null, null], array_slice($exercise, 6), 'completion is recorded; sets, reps and weight are not invented');
        $this->assertNoVoiceChangesTheOutcome($results);
    }

    // ---- the voice cannot reach what decides these things --------------------------------------------------------------

    public function test_only_the_prompt_and_settings_code_reads_the_voice_never_authorization_or_plan_writing(): void
    {
        $readers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/CoachingPreferences|CoachStyle|ResponseDetail|coach_style|response_detail/', file_get_contents($file->getPathname()))) {
                $readers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        sort($readers);

        $this->assertSame([
            'Http/Requests/UpdateFitnessProfileRequest.php',
            'Http/Resources/FitnessProfileResource.php',
            'Models/FitnessProfile.php',
            'Services/Ai/AiCoachService.php',
            'Services/Ai/CoachStyle.php',
            'Services/Ai/CoachingPreferences.php',
            'Services/Ai/ResponseDetail.php',
            'Services/Ai/SystemPromptBuilder.php',
            'Services/Ai/WeeklyPlanCoachService.php',
            'Services/Ai/WeeklySummaryService.php',
        ], $readers, 'A new reader of the voice must be a deliberate, reviewed change: tool authorization, load limits and plan writing must never depend on it.');
    }

    public function test_the_model_options_do_not_change_with_the_voice(): void
    {
        $this->underEveryVoice(function (User $user): array {
            $this->fake->queue(new AiResponse(text: 'Noted.'));
            $this->ask($user, 'What should I do today?');

            $call = $this->fake->calls[0];
            $this->assertArrayNotHasKey('temperature', $call['options'] ?? [], 'personality is wording, not sampling');

            return [];
        });
    }
}
