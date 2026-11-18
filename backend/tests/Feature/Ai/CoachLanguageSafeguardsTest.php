<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use App\Support\ExerciseSlug;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M2: the language is presentation only. It must not reach what decides tool access,
 * authorization, workout persistence, load limits, progression or confirmations, and the same scripted
 * model output must lead to the same stored result whichever language the user chose.
 */
class CoachLanguageSafeguardsTest extends PerformanceEvalCase
{
    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'America/Bogota'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
    }

    private function userWho(string $language): User
    {
        $user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $language]);

        foreach ([9, 5, 2] as $ago) {
            $this->logSets($user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }

        foreach ([5, 6] as $offset) {
            app(WorkoutPlanService::class)->createOrReplace($user, [
                'planned_date' => now()->startOfWeek()->addDays($offset)->toDateString(), 'activity_type' => 'strength', 'title' => "Bench {$offset}",
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
            ]);
        }

        return $user;
    }

    /** Everything stored about a user's plans, without ids or dates that differ between two users. */
    private function plans(User $user): array
    {
        return WorkoutPlan::where('user_id', $user->id)->orderBy('planned_date')->get()->map(fn (WorkoutPlan $p) => [
            $p->planned_date->toDateString(), $p->title, $p->activity_type,
            $p->exercises()->orderBy('id')->get()->map(fn (WorkoutPlanExercise $e) => [$e->exercise_name, $e->exercise_slug, $e->planned_sets, $e->planned_reps, $e->planned_weight_kg])->all(),
        ])->all();
    }

    private function toolCall(string $name, array $arguments): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: $name, arguments: $arguments)]);
    }

    /** One scripted session of model output that tries things the application must refuse or hold. */
    private function script(User $user): array
    {
        $date = now()->startOfWeek()->addDays(5)->toDateString();
        $out = [];

        // A weekly adaptation that raises a load: refused.
        $this->fake->queue($this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'Adjusting.', 'reasoning_factors' => ['test'],
            'changes' => [['date' => $date, 'action' => 'replace', 'activity_type' => 'strength', 'title' => 'Rewritten', 'reason' => 'test',
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 50]]]],
        ]));
        $out['adapt'] = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Rework my week.'])->assertOk()->json();

        // A chat turn that wants a plan nobody asked to be written: the tool is withheld, nothing is written.
        $this->fake->queue(
            $this->toolCall('create_workout_plan', ['activity_type' => 'strength', 'title' => 'Unasked', 'decision_summary' => 'x', 'reasoning_factors' => ['x'], 'planned_date' => $this->d(-3),
                'exercises' => [['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 24]]]),
            new AiResponse(text: 'Let us go!'),
        );
        $out['chat'] = $this->ask($user, 'What should I focus on next?');

        return $out;
    }

    // ---- what decides things never reads the language ----------------------------------------------

    public function test_only_the_prompt_settings_and_account_code_read_the_language_never_authorization_or_plan_writing(): void
    {
        $readers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/AppLocale|appLocale|->locale\b|[\'"]locale[\'"]|setLocale|getLocale/', file_get_contents($file->getPathname()))) {
                $readers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        sort($readers);

        $this->assertSame([
            'Http/Controllers/Api/AuthController.php',
            'Http/Middleware/SetRequestLocale.php',
            'Http/Requests/Auth/RegisterRequest.php',
            'Http/Requests/UpdateLocaleRequest.php',
            'Http/Resources/UserResource.php',
            'Models/User.php',
            'Services/Ai/AiCoachService.php',
            'Services/Ai/CoachingPreferences.php',
            'Services/Ai/Notices/Commands.php', // which phrases the application's notices quote (presentation only)
            'Services/Ai/Notices/NoticeText.php', // the words of the application's own notices (presentation only)
            'Services/Ai/SystemPromptBuilder.php',
            'Services/Ai/WeeklySummaryService.php',
            'Support/ApiErrorLocalizer.php', // M3c: the wording of an error response, by exception kind (presentation only)
            'Support/AppLocale.php',
            'Support/ModelFacingLocale.php', // M3c: pins English around a tool call (the one place that sets a language)
            'Support/ProgressionMessages.php', // M3c: the wording of progression reasons and refusals
            'Support/WeeklyPlanMessages.php', // M3c: the wording of a weekly-plan error
        ], $readers, 'A new reader of the language must be a deliberate, reviewed change: tool access, authorization, load limits and plan writing must never depend on it.');
    }

    public function test_the_chat_service_uses_the_language_only_to_word_the_dashboard_request(): void
    {
        $lines = [];
        foreach (preg_split('/\R/', file_get_contents(app_path('Services/Ai/AiCoachService.php'))) as $line) {
            if (preg_match('/AppLocale|appLocale|->locale\b/', $line)) {
                $lines[] = trim($line);
            }
        }

        $this->assertSame([
            'use App\\Support\\AppLocale;',
            'return match ($preferences->locale) {',
            'AppLocale::Spanish => \'En \'.self::DASHBOARD_LENGTH_ES[$preferences->detail->value].\', ¿qué debo hacer hoy y por qué?\',',
            'AppLocale::English => \'In \'.self::DASHBOARD_LENGTH[$preferences->detail->value].\', what should I do today and why?\',',
        ], $lines, 'authorize(), the tool turn, saving and confirmations never read the language; it only words the dashboard request.');
    }

    // ---- the same output gives the same stored result -------------------------------------------------

    public function test_the_same_scripted_model_output_leads_to_the_same_stored_result_in_both_languages(): void
    {
        $english = $this->userWho('en');
        $spanish = $this->userWho('es');

        $before = [$this->plans($english), $this->plans($spanish)];
        $this->assertSame($before[0], $before[1]);

        $outEnglish = $this->script($english);
        $outSpanish = $this->script($spanish);

        $this->assertSame($this->plans($english), $this->plans($spanish), 'Same rows, same slugs, same loads.');
        $this->assertSame($before[0], $this->plans($english), 'And nothing was written: a raised load and an unasked plan are both refused.');
        // The outcome is identical; only the words of the refusal are the user's language (a Spanish user is not shown English).
        $this->assertSame(Arr::except($outEnglish['adapt'], ['error']), Arr::except($outSpanish['adapt'], ['error']), 'The application\'s own answer is identical.');
        $this->assertArrayHasKey('error', $outEnglish['adapt']);
        $this->assertArrayHasKey('error', $outSpanish['adapt']);
        $this->assertStringContainsString('would raise its load', $outEnglish['adapt']['error']);
        $this->assertStringContainsString('superaría', $outSpanish['adapt']['error']);
        $this->assertFalse($outEnglish['adapt']['applied']);
        $this->assertSame($outEnglish['chat']['tools'], $outSpanish['chat']['tools']);
        $this->assertSame($outEnglish['chat']['error'], $outSpanish['chat']['error']);
        $this->assertSame($outEnglish['chat']['answer'], $outSpanish['chat']['answer']);
    }

    public function test_saving_the_language_changes_nothing_but_the_language(): void
    {
        $user = $this->userWho('en');
        $before = $this->snapshot();
        $userBefore = User::findOrFail($user->id)->only(['name', 'email', 'timezone']);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/locale', ['locale' => 'es'])->assertOk();

        $this->assertSame($before, $this->snapshot(), 'Plans, performance, memories, schedule and profile are untouched.');
        $this->assertSame($userBefore, User::findOrFail($user->id)->only(['name', 'email', 'timezone']));
        $this->assertSame('es', User::findOrFail($user->id)->locale);
    }

    public function test_exercise_slugs_do_not_depend_on_the_language_and_nothing_is_translated_or_aliased(): void
    {
        $this->assertSame('bench-press', ExerciseSlug::from('Bench Press'));

        $user = $this->userWho('es');
        $this->assertSame(['bench-press'], WorkoutPlanExercise::query()->whereHas('workoutPlan', fn ($q) => $q->where('user_id', $user->id))->pluck('exercise_slug')->unique()->values()->all());

        $this->assertNotSame(ExerciseSlug::from('Squat'), ExerciseSlug::from('Sentadilla'), 'No automatic translation or aliasing of exercise names in M2.');
    }

    public function test_a_spanish_user_keeps_the_exercise_names_the_model_writes_exactly(): void
    {
        $user = $this->userWho('es');
        $this->fake->queue(
            $this->toolCall('log_workout', ['logged_date' => now()->toDateString(),
                'exercises' => [['exercise_name' => 'Press de banca', 'sets' => 3, 'reps' => 8, 'weight_kg' => 40]]]),
            new AiResponse(text: 'Registrado.'),
        );

        $this->ask($user, 'Hoy hice press de banca, 8 repeticiones con 40 kg.');

        $names = WorkoutExercise::query()->whereHas('workoutSession', fn ($q) => $q->where('user_id', $user->id))->pluck('exercise_name', 'exercise_slug')->all();
        ksort($names);

        $this->assertSame(
            ['bench-press' => 'Bench Press', 'press-de-banca' => 'Press de banca'],
            $names,
            'The name is stored exactly as the model wrote it: not translated, not matched to Bench Press, and the existing history is untouched.',
        );
    }
}
