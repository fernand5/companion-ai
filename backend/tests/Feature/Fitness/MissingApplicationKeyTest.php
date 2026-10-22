<?php

namespace Tests\Feature\Fitness;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlanExercise;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTOs\AiResponse;
use App\Services\Fitness\Progression\ProgressionApprovalToken;
use App\Services\Fitness\Progression\ProgressionUnavailableException;
use App\Support\ApplicationKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * If APP_KEY is missing or malformed, nothing that depends on it may be created insecurely and nothing
 * may fail with an unexplained 500: the progression endpoints answer 503 with a plain message, the log
 * says what to fix, and the rest of the application keeps working. A valid key behaves as before.
 * (This is a safety net, not a substitute for checking the key in each environment.)
 */
class MissingApplicationKeyTest extends PerformanceEvalCase
{
    private const VALID = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => self::VALID]);
        ApplicationKey::resetReporting();
        $this->user = $this->newUser();

        // A user with an actionable recommendation: the case that used to end in a 500.
        foreach ([9, 5, 2] as $ago) {
            $this->logSets($this->user, $ago, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }
        $this->upcoming($this->user, 2, 'Bench Press', [3, 10, 40]);
    }

    /** @return array<string, array{mixed}> */
    public static function unusableKeys(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'only the prefix' => ['base64:'],
            'not base64' => ['base64:!!!not*base64!!!'],
            'too short' => ['base64:'.'c2hvcnQ='],
            'wrong length for the cipher' => ['base64:'.'a2tra2tra2tra2tra2tra2tra2tra2s='],
            'a plain string of the wrong length' => ['plainstringofthewronglength'],
        ];
    }

    private function breakTheKey(mixed $key): void
    {
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function restoreTheKey(): void
    {
        $this->breakTheKey(self::VALID);
    }

    private function loadsAndDismissals(): array
    {
        return [
            md5(DB::table('workout_plan_exercises')->orderBy('id')->get()->toJson()),
            DB::table('progression_dismissals')->count(),
            $this->snapshot(),
        ];
    }

    // ---- the endpoints -------------------------------------------------------------------------------

    #[DataProvider('unusableKeys')]
    public function test_every_progression_endpoint_answers_503_with_a_safe_message(mixed $key): void
    {
        $this->breakTheKey($key);
        $before = $this->loadsAndDismissals();

        $responses = [
            $this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations'),
            $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => 'anything']),
            $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/rejections', ['proposal' => 'anything']),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(503)->assertJson(['code' => 'progression_unavailable', 'message' => 'Progression suggestions are temporarily unavailable. Please try again later.']);

            $body = strtolower($response->getContent());
            foreach (['app_key', 'key', 'cipher', 'encrypt', 'base64', 'exception', 'config', 'trace'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "The response must not reveal anything about the configuration ({$leak}).");
            }
            if (is_string($key) && $key !== '') {
                $this->assertStringNotContainsString($key, $response->getContent());
            }
        }

        $this->assertSame($before, $this->loadsAndDismissals(), 'Nothing was written.');
    }

    public function test_the_answer_does_not_depend_on_whether_the_user_has_anything_to_approve(): void
    {
        $newcomer = $this->newUser(); // no history: nothing actionable, so nothing would need sealing
        $this->breakTheKey(null);

        // Uniform on purpose: a misconfigured environment shows up for everyone, in the first smoke test.
        $this->actingAs($newcomer, 'sanctum')->getJson('/api/progression/recommendations')->assertStatus(503)->assertJson(['code' => 'progression_unavailable']);
        $this->restoreTheKey();
        $this->actingAs($newcomer, 'sanctum')->getJson('/api/progression/recommendations')->assertOk();
    }

    public function test_the_failure_is_logged_for_the_operator_with_the_fix_and_without_the_key(): void
    {
        Log::spy();
        $this->breakTheKey('plainstringofthewronglength');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertStatus(503);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $text = json_encode($context);

            return $message === 'app_key.unusable'
                && str_contains($text, 'key:generate')
                && str_contains($text, 'APP_KEY')
                && ! str_contains($text, 'plainstringofthewronglength');
        })->once();
    }

    public function test_the_log_is_not_flooded_by_repeated_requests(): void
    {
        Log::spy();
        $this->breakTheKey(null);

        foreach (range(1, 5) as $i) {
            $this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertStatus(503);
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'app_key.unusable')->once();
    }

    public function test_the_rest_of_the_application_keeps_working(): void
    {
        $this->breakTheKey(null);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')->assertOk();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/workout-plans?date='.$this->d(-2))->assertOk();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/profile')->assertSuccessful();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/recovery-checkins', ['checkin_date' => $this->d(0), 'energy' => 3, 'soreness' => 2, 'motivation' => 3])->assertSuccessful();
    }

    public function test_a_valid_key_behaves_as_before_and_recovery_needs_no_other_action(): void
    {
        $this->breakTheKey(null);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertStatus(503);

        $this->restoreTheKey();
        $items = $this->actingAs($this->user, 'sanctum')->getJson('/api/progression/recommendations')->assertOk()->json('data');
        $bench = collect($items)->firstWhere('exercise.name', 'Bench Press');

        $this->assertSame('suggested', $bench['state']);
        $this->assertIsString($bench['proposal']['id']);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/progression/approvals', ['proposal' => $bench['proposal']['id']])->assertOk();
        $this->assertSame('42.50', (string) WorkoutPlanExercise::where('exercise_slug', 'bench-press')->orderByDesc('id')->value('planned_weight_kg'));
    }

    // ---- nothing insecure is ever created ---------------------------------------------------------------------

    public function test_a_proposal_cannot_be_sealed_or_opened_without_a_usable_key(): void
    {
        $sealed = ProgressionApprovalToken::issue($this->user->id, 1, 1, 'rev', '2026-10-20', 'bench-press', 'Bench Press', 'INCREASE', 40, 42.5, 'evidence')->seal();

        $this->breakTheKey(null);

        $this->expectException(ProgressionUnavailableException::class);
        ProgressionApprovalToken::open($sealed);
    }

    public function test_sealing_is_refused_rather_than_done_weakly(): void
    {
        $this->breakTheKey('');

        $this->expectException(ProgressionUnavailableException::class);
        ProgressionApprovalToken::issue($this->user->id, 1, 1, 'rev', '2026-10-20', 'bench-press', 'Bench Press', 'INCREASE', 40, 42.5, 'evidence')->seal();
    }

    public function test_a_chat_proposal_is_never_created_or_trusted_without_a_usable_key(): void
    {
        $conversation = $this->user->conversations()->create();
        $row = WorkoutPlanExercise::where('exercise_slug', 'bench-press')->orderByDesc('id')->first();
        $proposal = PlanChangeProposal::create($this->user->id, $conversation->id, $row->workout_plan_id, $row->id, $this->d(-2), 'bench-press', 'Bench Press', 45, null, null);
        $this->assertTrue($proposal->isAuthentic());

        $this->breakTheKey(null);

        $this->assertFalse($proposal->isAuthentic(), 'A proposal is not trusted when the key cannot vouch for it.');
        $this->expectException(\LogicException::class);
        PlanChangeProposal::create($this->user->id, $conversation->id, $row->workout_plan_id, $row->id, $this->d(-2), 'bench-press', 'Bench Press', 45, null, null);
    }

    public function test_a_proposal_signed_with_an_empty_key_is_not_trusted_even_when_the_key_is_still_empty(): void
    {
        $conversation = $this->user->conversations()->create();
        $row = WorkoutPlanExercise::where('exercise_slug', 'bench-press')->orderByDesc('id')->first();
        $meta = PlanChangeProposal::create($this->user->id, $conversation->id, $row->workout_plan_id, $row->id, $this->d(-2), 'bench-press', 'Bench Press', 44, null, null)->toMeta();

        // What someone could forge if an empty key were accepted: the same fields, signed with ''.
        $fields = $meta;
        unset($fields['signature']);
        $meta['signature'] = hash_hmac('sha256', (string) json_encode($fields, JSON_PRESERVE_ZERO_FRACTION), '');
        $forged = PlanChangeProposal::fromMeta($meta);

        $this->breakTheKey('');

        $this->assertNotNull($forged);
        $this->assertFalse($forged->isAuthentic());
    }

    public function test_chat_offers_no_confirmation_it_could_not_honour_and_still_answers(): void
    {
        $this->app->instance(AiProvider::class, (new FakeAiProvider)->queue(new AiResponse(text: 'That is a modest step.')));
        $this->breakTheKey(null);
        Log::spy();
        $before = $this->loadsAndDismissals();

        $result = $this->ask($this->user, 'I want to go up to 44 kg on bench next session');
        $this->assertNull($result['error'], (string) $result['error']);

        $message = Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertStringContainsString('That is a modest step.', $message->content, 'The coach still answers.');
        $this->assertStringNotContainsString('Reply "yes"', $message->content, 'No confirmation is offered that could never be redeemed.');
        $this->assertStringContainsString('explicit request', $message->content);
        $this->assertArrayNotHasKey('pending_plan_proposal', $message->meta ?? []);
        $this->assertSame($before, $this->loadsAndDismissals());
        Log::shouldHaveReceived('error')->withArgs(fn (string $m) => $m === 'app_key.unusable')->once();
    }

    public function test_with_a_valid_key_the_same_chat_message_does_offer_the_confirmation(): void
    {
        $this->app->instance(AiProvider::class, (new FakeAiProvider)->queue(new AiResponse(text: 'That is a modest step.')));

        $result = $this->ask($this->user, 'I want to go up to 44 kg on bench next session');
        $message = Message::where('conversation_id', $result['conversation'])->where('role', 'assistant')->latest('id')->firstOrFail();

        $this->assertStringContainsString('Reply "yes"', $message->content);
        $this->assertArrayHasKey('pending_plan_proposal', $message->meta);
    }
}
