<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Ai\DTOs\ToolCallRequest;
use App\Services\Fitness\WorkoutPlanService;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * M4: the client is told, by structured server-authored fields, (1) whether a replacement question can be answered right
 * now (so buttons are only ever shown for a live one, never by reading the wording of a message) and (2) what a save
 * actually did, read back from the database after persistence.
 */
class ConfirmationStateAndSaveResultTest extends PerformanceEvalCase
{
    private User $user;

    private FakeAiProvider $fake;

    private const DESCRIBED = "Aquí tienes:\n1. Flexiones 3x12\n2. Plancha 3x30 segundos";

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);
        $this->user = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
    }

    private function say(string $message, ?int $conversation, AiResponse ...$responses): array
    {
        $this->fake->queue(...$responses);

        $result = $this->ask($this->user, $message, $conversation);
        $this->assertNull($result['error'], (string) $result['error']);

        return $result;
    }

    private function save(): AiResponse
    {
        return new AiResponse(text: null, toolCalls: [new ToolCallRequest(id: 'c', name: 'create_workout_plan', arguments: [
            'activity_type' => 'strength', 'title' => 'Torso', 'decision_summary' => 'Como pediste.', 'reasoning_factors' => ['Lo pediste'],
            'planned_date' => $this->user->localToday(), 'exercises' => [
                ['exercise_name' => 'Flexiones', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Plancha', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
            ],
        ])]);
    }

    private function held(bool $existing = true): int
    {
        if ($existing) {
            app(WorkoutPlanService::class)->createOrReplace($this->user, ['planned_date' => $this->user->localToday(), 'activity_type' => 'strength', 'title' => 'Plan anterior']);
        }

        $conversation = $this->say('dame un entrenamiento', null, new AiResponse(text: self::DESCRIBED))['conversation'];
        $this->say('Agrega este entrenamiento a mi plan', $conversation, $this->save(), new AiResponse(text: 'Listo.'));

        return $conversation;
    }

    private function messages(int $conversation, ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->user, 'sanctum')->getJson("/api/conversations/{$conversation}/messages")->assertOk();

        return $response->json('data') ?? $response->json();
    }

    private function question(int $conversation): array
    {
        return collect($this->messages($conversation))->last(fn ($m) => isset($m['confirmation']));
    }

    public function test_a_live_replacement_question_is_actionable_and_says_when_it_lapses(): void
    {
        $question = $this->question($this->held());

        $this->assertSame('replacement', $question['confirmation']['kind']);
        $this->assertTrue($question['confirmation']['actionable']);
        $this->assertNotNull($question['confirmation']['expires_at']);
    }

    public function test_ordinary_messages_carry_no_confirmation(): void
    {
        $conversation = $this->held(existing: false);

        foreach ($this->messages($conversation) as $message) {
            $this->assertArrayNotHasKey('confirmation', $message);
        }
    }

    public function test_it_stays_actionable_after_a_clarification_and_stops_once_used(): void
    {
        $conversation = $this->held();
        $this->say('tal vez', $conversation);

        $this->assertTrue($this->question($conversation)['confirmation']['actionable'], 'The clarification points back to the same, unspent question.');

        $this->say('Sí', $conversation);
        $this->assertFalse($this->question($conversation)['confirmation']['actionable'], 'Used: no button.');
    }

    public function test_an_expired_question_is_not_actionable(): void
    {
        $conversation = $this->held();
        $this->travel(20)->minutes();

        $this->assertFalse($this->question($conversation)['confirmation']['actionable']);
    }

    public function test_an_old_question_is_not_actionable_once_the_conversation_moved_on(): void
    {
        $conversation = $this->held();
        $this->say('No', $conversation, new AiResponse(text: 'De acuerdo.'));

        $this->assertFalse($this->question($conversation)['confirmation']['actionable'], 'A "yes" would no longer answer it.');
    }

    public function test_another_user_cannot_read_the_conversation(): void
    {
        $conversation = $this->held();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')->getJson("/api/conversations/{$conversation}/messages")->assertForbidden();
    }

    public function test_a_save_reports_the_stored_workout_name_and_exact_day(): void
    {
        $conversation = $this->held(existing: false);
        $result = $this->last($conversation)->meta['save_result'];

        $this->assertSame(1, $result['version']);
        $this->assertSame([['date' => $this->user->localToday(), 'outcome' => 'added', 'title' => 'Torso']], $result['days']);
        $this->assertSame('Torso', WorkoutPlan::where('user_id', $this->user->id)->value('title'), 'Read back from the database.');
    }

    public function test_a_confirmed_replacement_reports_replaced_with_the_new_name(): void
    {
        $conversation = $this->held();
        $this->say('Sí', $conversation);

        $result = $this->last($conversation)->meta['save_result'];

        $this->assertSame([['date' => $this->user->localToday(), 'outcome' => 'replaced', 'title' => 'Torso']], $result['days']);
    }

    public function test_nothing_is_reported_when_nothing_was_saved(): void
    {
        $conversation = $this->held();

        $this->assertArrayNotHasKey('save_result', $this->last($conversation)->meta ?? [], 'The question is not a success.');

        $this->say('No', $conversation, new AiResponse(text: 'De acuerdo.'));
        $this->assertArrayNotHasKey('save_result', $this->last($conversation)->meta ?? []);

        // A save the model claims but the application did not make.
        $other = User::factory()->create(['timezone' => 'America/Bogota', 'locale' => 'es']);
        $this->user = $other;
        $c = $this->say('dame un entrenamiento', null, new AiResponse(text: self::DESCRIBED))['conversation'];
        $this->say('Agrega este entrenamiento a mi plan', $c, new AiResponse(text: 'Ya lo agregué.'));
        $this->assertArrayNotHasKey('save_result', $this->last($c)->meta ?? []);
    }

    private function last(int $conversation): Message
    {
        return Message::where('conversation_id', $conversation)->where('role', 'assistant')->latest('id')->firstOrFail();
    }
}
