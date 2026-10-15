<?php

namespace Tests\Support\PerformanceEval;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\SystemPromptBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Builds scenario fixtures through the real endpoints and asks the real chat endpoint,
 * so an evaluation exercises the same code as a user's message: the real context
 * builder, prompt, tool loop and throttle. Only the model behind the provider differs
 * (a scripted fake for deterministic tests, the configured Gemini for real-model runs).
 *
 * Time is NOT frozen: dates are relative to today, so the application's rate limiter
 * keeps working as it does in production.
 */
abstract class PerformanceEvalCase extends TestCase
{
    use RefreshDatabase;

    /** Minimum seconds between chat messages (the endpoint allows 10 a minute). */
    protected float $paceSeconds = 0;

    private static float $lastMessageAt = 0;

    // ---- fixtures: real user actions --------------------------------------------------------

    public function d(int $daysAgo): string
    {
        return Carbon::now('America/Bogota')->subDays($daysAgo)->toDateString();
    }

    public function newUser(): User
    {
        return User::factory()->create(['timezone' => 'America/Bogota']);
    }

    /**
     * @param  array{0: ?int, 1: ?int, 2: float|int|null, 3?: ?int}  $target  sets, reps, kg, seconds
     */
    private function planWith(User $u, int $daysAgo, string $name, array $target, string $type = 'strength'): WorkoutPlan
    {
        $plan = WorkoutPlan::factory()->for($u)->create(['planned_date' => $this->d($daysAgo), 'activity_type' => $type, 'title' => 'Plan '.$this->d($daysAgo)]);
        WorkoutPlanExercise::factory()->for($plan, 'workoutPlan')->create([
            'exercise_name' => $name, 'position' => 0,
            'planned_sets' => $target[0], 'planned_reps' => $target[1], 'planned_weight_kg' => $target[2], 'planned_duration_seconds' => $target[3] ?? null,
        ]);

        return $plan->load('exercises');
    }

    /** The user logs the sets they did, through the performance endpoint. */
    public function logSets(User $u, int $daysAgo, string $name, array $target, array $sets, string $type = 'strength'): WorkoutPlan
    {
        $plan = $this->planWith($u, $daysAgo, $name, $target, $type);

        $this->actingAs($u, 'sanctum')
            ->putJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}/performance", ['sets' => $sets])
            ->assertOk();

        return $plan;
    }

    /** One-tap status on a planned exercise (no amounts). */
    public function tap(User $u, int $daysAgo, string $name, array $target, string $status = 'completed', string $type = 'strength'): WorkoutPlan
    {
        $plan = $this->planWith($u, $daysAgo, $name, $target, $type);

        $this->actingAs($u, 'sanctum')
            ->patchJson("/api/workout-plans/{$plan->id}/exercises/{$plan->exercises[0]->id}", ['status' => $status])
            ->assertOk();

        return $plan;
    }

    /** A pending exercise in a plan some days from now (the next planned target). */
    public function upcoming(User $u, int $daysAhead, string $name, array $target): WorkoutPlan
    {
        return $this->planWith($u, -$daysAhead, $name, $target);
    }

    /** History recovered from older rows: recorded_as = migrated. */
    public function migrated(User $u, int $daysAgo, string $name, array $sets): void
    {
        $session = WorkoutSession::factory()->for($u)->create(['logged_date' => $this->d($daysAgo)]);
        $exercise = WorkoutExercise::factory()->for($session, 'workoutSession')->create(['exercise_name' => $name, 'recorded_as' => 'migrated']);

        foreach (array_values($sets) as $i => $set) {
            WorkoutSet::factory()->for($exercise, 'exercise')->create(['set_number' => $i + 1, 'reps' => $set['reps'] ?? null, 'weight_kg' => $set['weight_kg'] ?? null]);
        }
    }

    public function logRun(User $u, int $daysAgo, int $minutes, ?float $km, array $extra = []): void
    {
        $this->actingAs($u, 'sanctum')->postJson('/api/activity', [
            'type' => 'treadmill', 'duration_minutes' => $minutes, 'logged_date' => $this->d($daysAgo),
        ] + ($km !== null ? ['distance_km' => $km] : []) + $extra)->assertCreated();
    }

    // ---- asking ---------------------------------------------------------------------------------

    /**
     * Send one message through the real endpoint, pacing requests and retrying transient
     * provider/limit errors without ever bypassing the throttle.
     *
     * @return array{conversation: int, answer: string, tools: array<int, string>, tool_calls: array, error: ?string}
     */
    public function ask(User $u, string $question, ?int $conversationId = null): array
    {
        $conversationId ??= $this->actingAs($u, 'sanctum')->postJson('/api/conversations', [])->assertCreated()->json('id');
        $error = null;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $wait = $this->paceSeconds - (microtime(true) - self::$lastMessageAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            self::$lastMessageAt = microtime(true);

            $response = $this->actingAs($u, 'sanctum')->postJson("/api/conversations/{$conversationId}/messages", ['content' => $question]);

            if ($response->getStatusCode() === 201) {
                $message = Message::find($response->json('id'));
                $calls = $message?->meta['tool_calls'] ?? [];

                return [
                    'conversation' => $conversationId,
                    'answer' => (string) $response->json('content'),
                    'tools' => array_map(fn ($c) => $c['name'], $calls),
                    'tool_calls' => $calls,
                    'error' => null,
                ];
            }

            $error = 'HTTP '.$response->getStatusCode().': '.substr((string) ($response->json('message') ?? ''), 0, 120);
            $retryAfter = (int) ($response->headers->get('Retry-After') ?? 0);
            $wait = $response->getStatusCode() === 429 ? max($retryAfter, 5) : 15 * $attempt;

            if ($this->paceSeconds > 0) {
                sleep($wait);
            } else {
                break;
            }
        }

        return ['conversation' => $conversationId, 'answer' => '', 'tools' => [], 'tool_calls' => [], 'error' => $error];
    }

    // ---- running a scenario ------------------------------------------------------------------------------

    /**
     * @param  ?string  $rules  null = the application's current reading rules; otherwise this text replaces them
     * @param  ?\Closure(Question, int): void  $before  called before each question (the deterministic test queues its scripted answer here)
     * @return array{scenario: string, category: string, readonly_ok: bool, questions: array<int, array<string, mixed>>}
     */
    public function runScenario(Scenario $scenario, AiProvider $inner, ?string $rules = null, ?\Closure $before = null): array
    {
        // Routes cache their controller instance, which would keep serving the previous run's
        // provider and prompt builder (and so silently ignore this run's variant).
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }

        $provider = new CapturingProvider($inner);
        $this->app->instance(AiProvider::class, $provider);

        if ($rules !== null) {
            $this->app->bind(SystemPromptBuilder::class, fn () => PromptVariants::builderFor($rules));
        }

        $user = $this->newUser();
        ($scenario->setup)($this, $user);

        $before_state = $this->snapshot();
        $conversation = null;
        $out = [];

        foreach ($scenario->questions as $i => $question) {
            if ($before) {
                $before($question, $i);
            }

            $firstCall = count($provider->calls);
            $reply = $this->ask($user, $question->text, $conversation);
            $conversation = $reply['conversation'];

            $calls = array_slice($provider->calls, $firstCall);
            $prompt = $calls[0]['messages'][0]['content'] ?? '';
            $facts = ($this->section($prompt, 'Recent performance') ?? '')."\n".($this->section($prompt, 'Recent activity') ?? '')."\n".($this->section($prompt, "Today's activity so far") ?? '')."\n".$question->text;
            foreach ($calls as $call) {
                foreach ($call['messages'] as $message) {
                    if (($message['role'] ?? '') === 'tool') {
                        $facts .= "\n".json_encode($message['content']);
                    }
                }
            }

            $results = $this->evaluate($question, $reply['answer'], $reply['tools'], $facts, $reply['error']);

            $out[] = [
                'question' => $question->text,
                'expected' => $question->expected,
                'answer' => $reply['answer'],
                'tools' => $reply['tools'],
                'error' => $reply['error'],
                'checks' => $results,
                'prompt' => $prompt,
                'facts_text' => $facts,
                'prompt_chars' => strlen($prompt),
                'latency_ms' => array_sum(array_column($calls, 'latency_ms')),
                'provider_calls' => count($calls),
            ];
        }

        return ['scenario' => $scenario->id, 'category' => $scenario->category, 'readonly_ok' => $before_state === $this->snapshot(), 'questions' => $out];
    }

    /**
     * Score one answer against a question's checks. `$facts` is the text the model was shown
     * (its performance section, recent activity, the question and any tool results): the
     * only numbers a correct answer may state.
     *
     * @param  array<int, string>  $tools
     * @return array<int, array{dimension: string, label: string, pass: bool}>
     */
    public function evaluate(Question $question, string $answer, array $tools, string $facts, ?string $error = null): array
    {
        $context = ['answer' => $answer, 'tools' => $tools, 'allowed' => Check::numbersIn($facts)];

        return array_map(
            fn (Check $c) => ['dimension' => $c->dimension, 'label' => $c->label, 'pass' => $error === null && $c->passes($context)],
            $question->checks,
        );
    }

    /** A fingerprint of everything a coach reply could change about plans, logs and the profile. */
    public function snapshot(): array
    {
        return array_map(
            fn (string $table) => md5(DB::table($table)->orderBy('id')->get()->toJson()),
            ['workout_plans', 'workout_plan_exercises', 'workout_sessions', 'workout_exercises', 'workout_sets', 'activity_logs', 'fitness_memories', 'training_schedules', 'fitness_profiles'],
        );
    }

    public function section(string $prompt, string $heading): ?string
    {
        return preg_match('/## '.preg_quote($heading, '/').'[^\n]*\n(.*?)(?=\n\n## |\z)/s', $prompt, $m) ? $m[1] : null;
    }
}
