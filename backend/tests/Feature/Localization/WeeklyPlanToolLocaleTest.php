<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Tools\AiTool;
use App\Services\Tools\ToolRegistry;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3d: weekly-plan tool execution runs in English and everything around it does not.
 *
 * A spy stands in for one of the flow's read tools and records the application's language at the moment the tool runs;
 * a spy provider records it at every model call. For a Spanish user, the tool (what the model reads, including a
 * failure's message) is English, every model call and the response to the person are in the user's language, and the
 * language is back afterwards, on success and on failure. English users are the control.
 */
class WeeklyPlanToolLocaleTest extends PerformanceEvalCase
{
    /** @var array<int, string> */
    private array $toolSaw = [];

    private LocaleSpyProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'America/Bogota'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->provider = new LocaleSpyProvider;
        $this->app->instance(AiProvider::class, $this->provider);
    }

    private function spyTool(bool $fails): void
    {
        $saw = &$this->toolSaw;

        $this->app->make(ToolRegistry::class)->register(new class($saw, $fails) implements AiTool
        {
            public function __construct(private array &$saw, private bool $fails) {}

            public function name(): string
            {
                return 'get_todays_plan';
            }

            public function description(): string
            {
                return 'spy';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function execute(array $arguments, User $user): mixed
            {
                $this->saw[] = app()->getLocale();

                if ($this->fails) {
                    // A message built from the application's own translations: its language is the tool's language.
                    throw new \RuntimeException(__('errors.coach.busy'));
                }

                return ['plan' => null];
            }
        });
    }

    private function user(string $lang): User
    {
        return User::factory()->create(['timezone' => 'America/Bogota', 'locale' => $lang]);
    }

    private function toolCall(string $name, array $arguments): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: $name, arguments: $arguments)]);
    }

    private function decision(string $date, string $action = 'replace'): AiResponse
    {
        return $this->toolCall('propose_weekly_plan_changes', [
            'decision_summary' => 'A lighter Saturday.', 'reasoning_factors' => ['You asked'],
            'changes' => [['date' => $date, 'action' => $action, 'activity_type' => 'recovery', 'title' => 'Easy walk', 'reason' => 'You asked', 'duration_minutes' => 30]],
        ]);
    }

    private function saturday(): string
    {
        return now()->startOfWeek()->addDays(5)->toDateString();
    }

    public static function languages(): array
    {
        return ['english control' => ['en'], 'spanish' => ['es']];
    }

    #[DataProvider('languages')]
    public function test_on_success_the_tool_runs_in_english_and_everything_else_in_the_users_language(string $lang): void
    {
        $user = $this->user($lang);
        $this->spyTool(fails: false);
        $this->provider->queue($this->toolCall('get_todays_plan', []), $this->decision($this->saturday()));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Make Saturday easy.'])->assertOk()->json();

        $this->assertSame(['en'], $this->toolSaw, 'The tool ran in English.');
        $this->assertSame([$lang, $lang], $this->provider->saw, 'Both model calls were made in the user\'s language.');
        $this->assertTrue($response['applied']);
        $this->assertSame([$this->saturday()], $response['changes']);
        $this->assertSame('Easy walk', WorkoutPlan::where('user_id', $user->id)->whereDate('planned_date', $this->saturday())->value('title'), 'The plan was written the same way.');
        $this->assertSame('en', app()->getLocale(), 'The application\'s language is back to what it was.');
    }

    #[DataProvider('languages')]
    public function test_on_failure_the_model_reads_an_english_error_and_the_language_is_restored(string $lang): void
    {
        $user = $this->user($lang);
        $this->spyTool(fails: true);
        $this->provider->queue($this->toolCall('get_todays_plan', []), new AiResponse(text: 'Nothing to change.'));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'Make Saturday easy.'])->assertOk()->json();

        $this->assertSame(['en'], $this->toolSaw);
        $this->assertSame([$lang, $lang], $this->provider->saw, 'The model call after the failure is back in the user\'s language.');

        // What the model was given for the failed call.
        $toolMessage = collect($this->provider->calls[1]['messages'])->firstWhere('role', 'tool');
        $this->assertSame('Your coach is still working on your previous message — please wait for the reply first.', $toolMessage['content']['error'], 'The failure message the model reads is English.');

        $this->assertFalse($response['applied']);
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('Nothing to change.', $response['explanation'], 'The model\'s own closing words are returned as written.');
        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count());
    }

    #[DataProvider('languages')]
    public function test_a_refused_decision_is_english_to_the_model_flow_and_localized_to_the_person(string $lang): void
    {
        $user = $this->user($lang);
        $this->provider->queue($this->decision($this->saturday(), action: 'obliterate'));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'x'])->assertOk()->json();

        $this->assertFalse($response['applied']);
        $this->assertSame(['applied', 'explanation', 'error'], array_keys($response));
        $this->assertSame(
            $lang === 'es' ? 'No se pudo actualizar el plan semanal. Revisa los cambios e inténtalo de nuevo.' : 'The selected action is invalid.',
            $response['error'],
            'English: the model-facing text as it always was. Spanish: the conservative fallback, since an unknown message is never guessed at.',
        );
        $this->assertSame(0, WorkoutPlan::where('user_id', $user->id)->count(), 'Nothing is written either way.');
        $this->assertSame('en', app()->getLocale());
    }

    public function test_two_users_in_a_row_never_share_a_language_through_the_tool_pin(): void
    {
        $this->spyTool(fails: false);

        foreach (['es', 'en', 'es'] as $lang) {
            $this->app['auth']->forgetGuards();
            $this->provider->queue($this->toolCall('get_todays_plan', []), new AiResponse(text: 'ok'));
            $this->provider->saw = [];
            $this->toolSaw = [];

            $this->actingAs($this->user($lang), 'sanctum')->postJson('/api/weekly-plan/adapt', ['input' => 'x'])->assertOk();

            $this->assertSame(['en'], $this->toolSaw);
            $this->assertSame([$lang, $lang], $this->provider->saw);
        }
    }
}

/** A fake provider that records the application's language at every call. */
class LocaleSpyProvider extends FakeAiProvider
{
    /** @var array<int, string> */
    public array $saw = [];

    public function chat(array $messages, array $tools = [], array $options = []): AiResponse
    {
        $this->saw[] = app()->getLocale();

        return parent::chat($messages, $tools, $options);
    }
}
