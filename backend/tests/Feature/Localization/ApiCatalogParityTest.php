<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Services\Fitness\Progression\ProgressionApprovalException;
use App\Services\Fitness\Progression\ProgressionDecision;
use App\Services\Fitness\Progression\ProgressionEngine;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Services\Fitness\Progression\ReasonCode;
use App\Services\Fitness\Progression\SafetyContext;
use App\Support\ProgressionMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 7 / M3c: the API translation catalogs.
 *
 * Every key exists in both languages, every sentence keeps the same :placeholders, every English entry is word for
 * word what the application says today (so English output cannot drift), and every field the API validates has a
 * Spanish label. A new key, a reworded English sentence or a new validated field fails here until it is handled.
 */
class ApiCatalogParityTest extends TestCase
{
    /** @return array<string, string> dotted key => string */
    private function flat(array $lines): array
    {
        return Arr::dot($lines);
    }

    /** @return array<int, string> */
    private function placeholders(string $sentence): array
    {
        preg_match_all('/:[A-Za-z_]+/', $sentence, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    private function catalog(string $locale, string $file): array
    {
        return $this->flat(require lang_path("{$locale}/{$file}.php"));
    }

    private function frameworkEnglish(string $file): array
    {
        return $this->flat(require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php"));
    }

    // ---- errors and progression: en <-> es ----------------------------------------------------------------

    public static function ownCatalogs(): array
    {
        return ['errors' => ['errors'], 'progression' => ['progression']];
    }

    #[DataProvider('ownCatalogs')]
    public function test_english_and_spanish_have_the_same_keys_and_placeholders(string $file): void
    {
        $en = $this->catalog('en', $file);
        $es = $this->catalog('es', $file);

        $this->assertSame(array_keys($en), array_keys($es), "lang/en/{$file}.php and lang/es/{$file}.php must have the same keys, in the same order.");

        foreach ($en as $key => $sentence) {
            $this->assertIsString($sentence);
            $this->assertNotSame('', $es[$key], "{$file}.{$key} is empty in Spanish.");
            $this->assertSame($this->placeholders($sentence), $this->placeholders($es[$key]), "{$file}.{$key}: Spanish must keep the placeholders {$sentence}");
        }
    }

    #[DataProvider('ownCatalogs')]
    public function test_no_spanish_sentence_is_an_untranslated_copy_of_the_english(string $file): void
    {
        $en = $this->catalog('en', $file);
        $es = $this->catalog('es', $file);

        foreach ($en as $key => $sentence) {
            // The deliberate exceptions: the raw code English keeps for an `ineligible` detail, and nothing else.
            if (str_starts_with($key, 'ineligible.')) {
                continue;
            }

            $this->assertNotSame($sentence, $es[$key], "{$file}.{$key} is the English sentence in the Spanish file.");
        }
    }

    // ---- validation: the framework's English is the reference ---------------------------------------------

    public function test_spanish_validation_covers_every_framework_rule_with_the_same_placeholders(): void
    {
        $en = $this->frameworkEnglish('validation');
        $es = $this->catalog('es', 'validation');

        foreach ($en as $key => $sentence) {
            if (str_starts_with($key, 'attributes') || str_starts_with($key, 'custom.')) {
                continue;
            }

            $this->assertArrayHasKey($key, $es, "lang/es/validation.php is missing {$key}.");
            $this->assertSame($this->placeholders($sentence), $this->placeholders($es[$key]), "validation.{$key} must keep the placeholders of: {$sentence}");
        }
    }

    public function test_the_english_validation_file_changes_nothing_for_english(): void
    {
        $this->assertSame(['attributes' => []], require lang_path('en/validation.php'));
        app()->setLocale('en');

        $this->assertSame('The weight kg field must be a number.', $this->makeValidator(['weight_kg' => 'x'], ['weight_kg' => 'numeric'])->errors()->first('weight_kg'));
    }

    private function makeValidator(array $data, array $rules): Validator
    {
        $validator = validator($data, $rules);
        $validator->passes();

        return $validator;
    }

    public function test_every_field_the_api_validates_has_a_spanish_label(): void
    {
        $labels = $this->catalog('es', 'validation');
        $fields = [];
        $inspected = 0;

        foreach (array_merge(glob(app_path('Http/Requests/*.php')), glob(app_path('Http/Requests/Auth/*.php'))) as $file) {
            $class = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path('Http/Requests/'))));

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            $request = $class::create('/x', 'POST');
            $request->setUserResolver(fn () => User::factory()->make(['timezone' => 'UTC']));

            foreach (array_keys($request->rules()) as $field) {
                $fields[$field] = $class;
            }

            $inspected++;
        }

        $this->assertGreaterThanOrEqual(15, $inspected, 'The request classes were not all inspected.');

        foreach ($fields as $field => $class) {
            $this->assertArrayHasKey("attributes.{$field}", $labels, "{$class}: field {$field} has no Spanish label in lang/es/validation.php.");
            $this->assertNotSame('', trim($labels["attributes.{$field}"]));
        }

        // The fitness services validate their own input too (weekly-plan changes, performance sets, schedules): the
        // field names in their Validator::make() rules are labelled as well.
        $serviceFields = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/Validator::make\((.*?)\)->(?:validate|fails|passes|errors)/s', (string) file_get_contents($file->getPathname()), $blocks);

            foreach ($blocks[0] as $block) {
                preg_match_all('/^\s*\'([a-z_.*]+)\'\s*=>/m', $block, $keys);
                array_push($serviceFields, ...$keys[1]);
            }
        }

        $serviceFields = array_values(array_filter(array_unique($serviceFields), fn (string $f) => ! str_contains($f, 'before_or_equal')));
        $this->assertGreaterThan(40, count($serviceFields), 'The service rules were not found.');

        foreach ($serviceFields as $field) {
            $this->assertArrayHasKey("attributes.{$field}", $labels, "A fitness service validates {$field}, which has no Spanish label.");
        }
    }

    public function test_spanish_auth_matches_the_framework_keys_and_placeholders(): void
    {
        $en = $this->frameworkEnglish('auth');
        $es = $this->catalog('es', 'auth');

        $this->assertSame(array_keys($en), array_keys($es));

        foreach ($en as $key => $sentence) {
            $this->assertSame($this->placeholders($sentence), $this->placeholders($es[$key]), "auth.{$key}");
        }
    }

    public function test_the_json_catalog_has_the_validation_summary_lines_with_their_placeholders(): void
    {
        $json = json_decode((string) file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['(and :count more error)', '(and :count more errors)', 'The given data was invalid.'], collect(array_keys($json))->sort()->values()->all());
        $this->assertSame([':count'], $this->placeholders($json['(and :count more error)']));
        $this->assertSame([':count'], $this->placeholders($json['(and :count more errors)']));
    }

    // ---- progression: English is word for word what the engine says today -----------------------------------

    public function test_every_reason_code_has_a_catalog_entry_and_english_equals_the_models_meaning(): void
    {
        $en = $this->catalog('en', 'progression');
        $es = $this->catalog('es', 'progression');

        $this->assertCount(count(ReasonCode::cases()), array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'reasons.')), 'No catalog entry without a ReasonCode, and none missing.');

        foreach (ReasonCode::cases() as $case) {
            $this->assertArrayHasKey("reasons.{$case->value}", $en);
            $this->assertArrayHasKey("reasons.{$case->value}", $es);
            $this->assertSame($case->meaning(), ProgressionMessages::reason($case, 'en'), "{$case->value}: the English catalog must equal ReasonCode::meaning() exactly.");
            $this->assertNotSame($case->meaning(), ProgressionMessages::reason($case, 'es'));
        }
    }

    public function test_the_numbers_in_a_spanish_reason_come_from_the_policy(): void
    {
        $this->assertStringContainsString((string) ProgressionPolicy::SESSIONS_REQUIRED, ProgressionMessages::reason(ReasonCode::NeedMoreSessions, 'es'));
        $this->assertStringContainsString((string) ProgressionPolicy::STALE_AFTER_DAYS, ProgressionMessages::reason(ReasonCode::StaleHistory, 'es'));
        $this->assertStringContainsString((string) (int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100).'%', ProgressionMessages::reason(ReasonCode::LoadStepExceedsLimit, 'es'));
        $this->assertStringContainsString((string) ProgressionPolicy::PAIN_WINDOW_DAYS, ProgressionMessages::reason(ReasonCode::PainReportedRecently, 'es'));

        foreach (ReasonCode::cases() as $case) {
            $this->assertStringNotContainsString(':', ProgressionMessages::reason($case, 'es'), "{$case->value}: an unfilled placeholder.");
        }
    }

    /** The six sentences the engine can write, produced by the engine itself. */
    private function engineCaveats(): array
    {
        $engine = app(ProgressionEngine::class);
        $standing = new ReflectionMethod($engine, 'standingCaveats');
        $condition = new ReflectionMethod($engine, 'conditionCaveats');

        $increase = ProgressionDecision::Increase;

        return [
            'standing, recovery current' => $standing->invoke($engine, $increase, new SafetyContext(daysSinceLatestCheckin: 1)),
            'standing, recovery unknown' => $standing->invoke($engine, $increase, new SafetyContext),
            'conditions' => $condition->invoke($engine, new SafetyContext(painReportedRecently: true, latestSoreness: ProgressionPolicy::HIGH_SORENESS, daysSinceLatestCheckin: 1, restrictionOnRecord: true)),
        ];
    }

    public function test_the_english_caveat_catalog_is_exactly_what_the_engine_writes(): void
    {
        $engine = collect($this->engineCaveats())->flatten()->unique()->sort()->values()->all();
        $catalog = collect(ProgressionMessages::caveats('en'))->values()->sort()->values()->all();

        $this->assertSame($catalog, $engine, 'Every caveat the engine can write has exactly one code, and no code is without a caveat.');
        $this->assertCount(6, $catalog);
    }

    public function test_every_approval_refusal_has_the_english_message_the_service_gives(): void
    {
        $factories = [
            'invalid_proposal' => ProgressionApprovalException::invalid(),
            'forbidden' => ProgressionApprovalException::notYours(),
            'expired' => ProgressionApprovalException::expired(),
            'stale' => ProgressionApprovalException::stale(),
            'already_used' => ProgressionApprovalException::alreadyUsed(),
            'dismissed' => ProgressionApprovalException::dismissed(),
        ];

        foreach ($factories as $reason => $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($e->getMessage(), trans("progression.approval.{$reason}", [], 'en'), "{$reason}: English catalog equals the service's message.");
        }

        foreach (ProgressionMessages::INELIGIBLE_DETAILS as $detail) {
            $e = ProgressionApprovalException::ineligible($detail);
            $this->assertSame($e->getMessage(), trans('progression.approval.ineligible', ['detail' => trans("progression.ineligible.{$detail}", [], 'en')], 'en'), $detail);
            $this->assertNotSame('', trans("progression.ineligible.{$detail}", [], 'es'));
        }

        $spanish = require lang_path('es/progression.php');
        $this->assertSame(
            collect(ProgressionMessages::INELIGIBLE_DETAILS)->sort()->values()->all(),
            collect(array_keys($spanish['ineligible']))->sort()->values()->all(),
            'The closed list of ineligible reasons is the list the catalog covers.',
        );
    }
}
