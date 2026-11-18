<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Services\Fitness\Progression\ProgressionApprovalException;
use App\Services\Fitness\Progression\ProgressionApprovalToken;
use App\Services\Fitness\Progression\ReasonCode;
use App\Services\Fitness\WorkoutPlanService;
use App\Support\ProgressionMessages;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PerformanceEval\PerformanceEvalCase;

/**
 * Phase 7 / M3c: the progression endpoints in English and Spanish.
 *
 * The same user, the same history and the same plan are read in both languages: reason_codes, targets, decisions,
 * proposals and every number are identical; only the sentences differ, and a reason is always the sentence for the
 * code beside it. Safety caveats get a stable code (additive); approval refusals keep their `code` and status.
 */
class ProgressionLocalizationTest extends PerformanceEvalCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->user = $this->newUser();

        foreach ([9, 5, 2] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Bench Press', [3, 10, 40], array_fill(0, 3, ['reps' => 10, 'weight_kg' => 40]));
        }

        foreach ([$this->d(-2), $this->d(-5)] as $date) {
            app(WorkoutPlanService::class)->createOrReplace($this->user, [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => "Strength {$date}", 'duration_minutes' => 50,
                'reasoning' => 'Because.', 'reasoning_factors' => ['Fresh'],
                'exercises' => [['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10, 'planned_weight_kg' => 40]],
            ]);
        }

        // Further exercises so the list holds different decisions and reasons.
        $this->logSets($this->user, 3, 'Pull-up', [3, 8, 10], array_fill(0, 3, ['reps' => 8, 'weight_kg' => 10]));
        foreach ([4, 7, 11] as $daysAgo) {
            $this->logSets($this->user, $daysAgo, 'Plank', [2, null, null, 45], [['duration_seconds' => 45], ['duration_seconds' => 45]]);
        }
    }

    private function read(string $language): array
    {
        $this->user->update(['locale' => $language]);
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->user->fresh(), 'sanctum')->getJson('/api/progression/recommendations')->assertOk()->json();
    }

    /** The response without the words and without the one-time proposal seal. */
    private function withoutWords(array $payload): array
    {
        foreach ($payload['data'] as &$item) {
            unset($item['reasons'], $item['safety_caveats']);
            if ($item['proposal'] !== null) {
                unset($item['proposal']['id']);
            }
        }

        return $payload;
    }

    // ---- the list ---------------------------------------------------------------------------------------

    public function test_the_two_languages_describe_the_same_recommendations_and_differ_only_in_words(): void
    {
        $en = $this->read('en');
        $es = $this->read('es');

        $this->assertNotEmpty($en['data']);
        $this->assertSame($this->withoutWords($en), $this->withoutWords($es), 'Decisions, codes, targets, evidence, states and proposals do not depend on the language.');
        $this->assertSame(array_keys($en['data'][0]), array_keys($es['data'][0]), 'Same keys in the same order.');
    }

    public function test_english_is_what_it_always_was_plus_the_additive_caveat_codes(): void
    {
        $en = $this->read('en');

        foreach ($en['data'] as $item) {
            $this->assertSame(array_map(fn (string $c) => ReasonCode::from($c)->meaning(), $item['reason_codes']), $item['reasons'], 'English reasons are exactly ReasonCode::meaning().');
            $this->assertArrayHasKey('safety_caveat_codes', $item);
            $this->assertCount(count($item['safety_caveats']), $item['safety_caveat_codes'], 'One code per caveat, in the same order.');
        }

        $increase = collect($en['data'])->firstWhere('exercise.name', 'Bench Press');
        $this->assertSame('INCREASE', $increase['decision']);
        $this->assertSame(['effort_not_recorded', 'not_medical_clearance', 'recovery_unknown'], $increase['safety_caveat_codes']);
        $this->assertSame(ProgressionMessages::caveats('en')['effort_not_recorded'], $increase['safety_caveats'][0]);
    }

    public function test_a_spanish_reason_is_always_the_sentence_for_the_code_beside_it(): void
    {
        $es = $this->read('es');

        foreach ($es['data'] as $item) {
            $this->assertCount(count($item['reason_codes']), $item['reasons'], 'reasons stay aligned with reason_codes.');

            foreach ($item['reason_codes'] as $i => $code) {
                $this->assertSame(ProgressionMessages::reason(ReasonCode::from($code), 'es'), $item['reasons'][$i]);
                $this->assertNotSame(ReasonCode::from($code)->meaning(), $item['reasons'][$i], "{$code} is not left in English.");
            }
        }
    }

    public function test_spanish_caveats_are_worded_and_keep_the_same_codes_in_the_same_order(): void
    {
        $en = collect($this->read('en')['data'])->firstWhere('exercise.name', 'Bench Press');
        $es = collect($this->read('es')['data'])->firstWhere('exercise.name', 'Bench Press');

        $this->assertSame($en['safety_caveat_codes'], $es['safety_caveat_codes']);
        $this->assertSame(
            array_map(fn (string $code) => ProgressionMessages::caveats('es')[$code], $en['safety_caveat_codes']),
            $es['safety_caveats'],
        );
        $this->assertStringContainsString('no una autorización médica', $es['safety_caveats'][1]);
    }

    public function test_an_unknown_caveat_keeps_its_text_and_has_no_code_never_a_guess(): void
    {
        app()->setLocale('es');
        $out = ProgressionMessages::present(['data' => [[
            'reason_codes' => ['NO_MEASURED_HISTORY'],
            'reasons' => ['x'],
            'safety_caveats' => ['A caveat this release has never seen.', ProgressionMessages::caveats('en')['high_soreness']],
            'other' => 1,
        ]], 'meta' => ['a' => 1]]);
        app()->setLocale('en');

        $item = $out['data'][0];
        $this->assertSame(['safety_caveats', 'safety_caveat_codes', 'other'], array_slice(array_keys($item), 2), 'The new key sits beside its text; established keys keep their order.');
        $this->assertSame([null, 'high_soreness'], $item['safety_caveat_codes']);
        $this->assertSame('A caveat this release has never seen.', $item['safety_caveats'][0]);
        $this->assertSame(ProgressionMessages::caveats('es')['high_soreness'], $item['safety_caveats'][1]);
        $this->assertSame(['a' => 1], $out['meta']);
    }

    public function test_an_unknown_reason_code_keeps_the_whole_english_list_rather_than_misalign_it(): void
    {
        app()->setLocale('es');
        $out = ProgressionMessages::present(['data' => [['reason_codes' => ['NO_MEASURED_HISTORY', 'FROM_THE_FUTURE'], 'reasons' => ['one', 'two'], 'safety_caveats' => []]]]);
        app()->setLocale('en');

        $this->assertSame(['one', 'two'], $out['data'][0]['reasons']);
    }

    public function test_reading_in_either_language_writes_nothing_and_calls_no_model(): void
    {
        $before = md5(DB::table('workout_plan_exercises')->orderBy('id')->get()->toJson()).DB::table('progression_dismissals')->count();
        $this->read('es');
        $this->read('en');

        $this->assertSame($before, md5(DB::table('workout_plan_exercises')->orderBy('id')->get()->toJson()).DB::table('progression_dismissals')->count());
    }

    public function test_a_proposal_issued_to_a_spanish_user_is_the_same_sealed_proposal(): void
    {
        $open = function (string $language) {
            $item = collect($this->read($language)['data'])->firstWhere('exercise.name', 'Bench Press');

            return ProgressionApprovalToken::open($item['proposal']['id']);
        };

        $en = $open('en');
        $es = $open('es');

        foreach (['userId', 'planId', 'planExerciseId', 'exerciseSlug', 'exerciseName', 'decision', 'fromKg', 'toKg', 'date', 'rowRevision', 'policyVersion', 'evidence'] as $field) {
            $this->assertSame($en->{$field}, $es->{$field}, "{$field} is not affected by the language.");
        }
    }

    public function test_both_languages_still_satisfy_the_frontends_progression_item_type(): void
    {
        // The client's own declaration of what a recommendation carries (frontend/src/types/index.ts). The new
        // `safety_caveat_codes` is additive: nothing it expects is removed, renamed or retyped.
        $types = (string) file_get_contents(base_path('../frontend/src/types/index.ts'));
        $this->assertSame(1, preg_match('/export interface ProgressionItem \{(.*?)\n\}/s', $types, $m), 'The frontend type was not found.');
        preg_match_all('/^  ([a-z_]+)\??:/m', $m[1], $keys);
        $expected = $keys[1];
        $this->assertContains('reasons', $expected);
        $this->assertContains('safety_caveats', $expected);

        foreach (['en', 'es'] as $language) {
            foreach ($this->read($language)['data'] as $item) {
                $this->assertSame([], array_values(array_diff($expected, array_keys($item))), "{$language}: a key the client reads is missing.");
                $this->assertContainsOnly('string', $item['reasons']);
                $this->assertContainsOnly('string', $item['safety_caveats']);
                $this->assertContainsOnly('string', $item['reason_codes']);
            }
        }
    }

    // ---- approval refusals --------------------------------------------------------------------------------

    public static function refusals(): array
    {
        return [
            'invalid' => [fn () => ProgressionApprovalException::invalid(), 'invalid_proposal', 422, 'Esa propuesta no es válida. Actualiza tus recomendaciones e inténtalo de nuevo.'],
            'not yours' => [fn () => ProgressionApprovalException::notYours(), 'forbidden', 403, 'Esa propuesta no pertenece a esta cuenta.'],
            'expired' => [fn () => ProgressionApprovalException::expired(), 'expired', 409, 'Esa recomendación venció. Actualiza para obtener una nueva.'],
            'stale' => [fn () => ProgressionApprovalException::stale(), 'stale', 409, 'Tu plan o tu historial reciente cambió desde que se hizo esta recomendación. Actualiza para obtener una nueva.'],
            'already used' => [fn () => ProgressionApprovalException::alreadyUsed(), 'already_used', 409, 'Esa recomendación ya se usó. Actualiza para ver tu plan actual.'],
            'dismissed' => [fn () => ProgressionApprovalException::dismissed(), 'dismissed', 409, 'Elegiste mantener este objetivo. Aparecerá una nueva recomendación si registras series nuevas o tu plan cambia.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_each_refusal_keeps_its_code_and_status_and_is_worded_by_the_code(\Closure $make, string $code, int $status, string $spanish): void
    {
        $e = $make();

        $this->assertSame($code, $e->reason);
        $this->assertSame($status, $e->status);

        $this->assertSame($e->getMessage(), ProgressionMessages::approval($e), 'English is exactly the service\'s message.');

        app()->setLocale('es');
        $this->assertSame($spanish, ProgressionMessages::approval($e));
        app()->setLocale('en');
    }

    public function test_an_ineligible_refusal_names_its_closed_reason_in_spanish_and_an_unknown_one_safely(): void
    {
        app()->setLocale('es');

        foreach (ProgressionMessages::INELIGIBLE_DETAILS as $detail) {
            $message = ProgressionMessages::approval(ProgressionApprovalException::ineligible($detail));
            $this->assertStringStartsWith('Esta recomendación ya no se puede aplicar (', $message);
            $this->assertStringNotContainsString($detail, $message, 'The code is said in words.');
        }

        $this->assertSame('Esta recomendación ya no se puede aplicar. Actualiza para obtener una nueva.', ProgressionMessages::approval(ProgressionApprovalException::ineligible('something_new')));
        $this->assertSame('whatever', ProgressionMessages::approval(new ProgressionApprovalException('a_new_code', 'whatever', 409)), 'An unknown refusal keeps its own message.');

        app()->setLocale('en');
    }

    public function test_over_http_a_spanish_user_gets_the_code_and_status_unchanged(): void
    {
        $this->user->update(['locale' => 'es']);

        $response = $this->actingAs($this->user->fresh(), 'sanctum')->postJson('/api/progression/approvals', ['proposal' => 'not-a-proposal']);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_proposal')
            ->assertJsonPath('message', 'Esa propuesta no es válida. Actualiza tus recomendaciones e inténtalo de nuevo.');

        $this->app['auth']->forgetGuards();
        $this->user->update(['locale' => 'en']);
        $this->actingAs($this->user->fresh(), 'sanctum')->postJson('/api/progression/rejections', ['proposal' => 'not-a-proposal'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_proposal')
            ->assertJsonPath('message', 'That proposal is not valid. Refresh your recommendations and try again.');
    }
}
