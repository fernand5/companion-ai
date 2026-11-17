<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiCoachService;
use App\Services\Ai\Authorization\GuardedToolExecutor;
use App\Services\Ai\Authorization\ToolAuthorization;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\NoticeText;
use App\Services\Ai\Notices\PlanClaims;
use App\Services\Ai\Notices\ReplyText;
use App\Support\AppLocale;
use Tests\TestCase;

/**
 * Phase 7 / M3a: the notice and claim code can only narrow what an earlier reply is read as, and can only add a
 * correction under a reply. It is not part of authorization, plan writing or tool access, and nothing may start to
 * depend on it without a reviewed change to this list.
 */
class ApplicationNoticeSafeguardsTest extends TestCase
{
    /** @return array<int, string> app-relative paths of the files that mention the notices namespace */
    private function files(string $pattern): array
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
                $found[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    public function test_only_the_reply_builder_and_the_history_reader_use_the_notice_code(): void
    {
        $this->assertSame([
            'Services/Ai/AiCoachService.php',
            'Services/Ai/Authorization/SavedWorkoutReference.php',
            'Services/Ai/Notices/ApplicationNotices.php',
            'Services/Ai/Notices/EnglishCommands.php',
            'Services/Ai/Notices/NoticeCode.php',
            'Services/Ai/Notices/NoticeText.php',
            'Services/Ai/Notices/PlanClaims.php',
            'Services/Ai/Notices/ReplyText.php',
            'Services/Ai/WeeklyPlanCoachService.php',
        ], $this->files('/Services\\\\Ai\\\\Notices|namespace App\\\\Services\\\\Ai\\\\Notices/'));
    }

    public function test_the_notice_code_depends_on_nothing_that_decides_what_may_change(): void
    {
        foreach (glob(app_path('Services/Ai/Notices/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/^use ([^;]+);/m', $source, $uses);

            // NoticeText only reads the value types it describes (the user's language, a proposal's own fields, a date).
            $allowed = basename($file) === 'NoticeText.php'
                ? ['App\\Models\\User', 'App\\Services\\Ai\\Authorization\\PlanChangeProposal', 'App\\Services\\Ai\\Authorization\\PlanReplacementProposal', 'App\\Support\\AppLocale', 'Carbon\\CarbonImmutable']
                : ['App\\Models\\Message'];

            $this->assertSame([], array_values(array_diff($uses[1], $allowed)), basename($file).' imports only what it needs to read.');
            $this->assertDoesNotMatchRegularExpression('/\b(?:DB::|Cache::|Log::|->save\(|->update\(|->delete\(|->create\(|Http::|Auth::)/', $source, basename($file).' reads and writes nothing.');
        }
    }

    public function test_the_history_reader_uses_the_notice_code_only_to_filter_the_application_notices_out(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Authorization/SavedWorkoutReference.php'));

        preg_match_all('/Notices\\\\[A-Za-z]+|ApplicationNotices::\w+|NoticeCode|PlanClaims|ReplyText/', $source, $uses);

        $this->assertSame(['Notices\\ApplicationNotices', 'ApplicationNotices::modelText'], $uses[0]);
    }

    public function test_the_claim_detector_is_used_in_one_place_to_add_a_correction_and_never_to_write(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AiCoachService.php'));

        preg_match_all('/PlanClaims::[a-z]\w+\(/', $source, $uses);
        $this->assertSame(['PlanClaims::claimsNoAccess(', 'PlanClaims::claimsPlanWritten('], $uses[0]);

        // Both calls sit inside correctClaims(), which only appends notices to the reply.
        $body = substr($source, (int) strpos($source, 'private function correctClaims('), (int) strpos($source, 'private function writeOutcomes(') - (int) strpos($source, 'private function correctClaims('));
        $this->assertStringContainsString('PlanClaims::claimsNoAccess', $body);
        $this->assertStringContainsString('PlanClaims::claimsPlanWritten', $body);
        $this->assertDoesNotMatchRegularExpression('/->(?:call|apply|create|update|replace|grant)\(|ReplacementGrant|ToolAuthorization::/', $body, 'A claim can add words under a reply; it cannot do or permit anything.');
    }

    public function test_the_claim_detector_takes_only_text_and_returns_only_a_language(): void
    {
        $detector = new \ReflectionClass(PlanClaims::class);

        foreach (['claimsPlanWritten', 'claimsNoAccess'] as $method) {
            $m = $detector->getMethod($method);
            $this->assertSame(['text'], array_map(fn ($p) => $p->getName(), $m->getParameters()));
            $this->assertSame('?string', (string) $m->getReturnType());
        }
    }

    // ---- how a correction is decided (grounded in the turn, not in the application's own words) ------------------------

    /** Run the claim check on a reply as the service does at the end of a turn that wrote nothing. */
    private function corrected(ReplyText $reply, array $toolTrace = []): ReplyText
    {
        $authorization = ToolAuthorization::readOnly('test');
        $turn = app(GuardedToolExecutor::class)->begin($authorization, null);
        $service = app(AiCoachService::class);

        (new \ReflectionMethod($service, 'correctClaims'))->invoke($service, $reply, $authorization, $turn, $toolTrace, new NoticeText(AppLocale::English));

        return $reply;
    }

    public function test_the_applications_own_notice_is_never_read_as_the_models_claim(): void
    {
        // A notice that happens to sound like a claim ("I added ... to your plan") is the application's, and true.
        $reply = $this->corrected(ReplyText::fromModel('Nice work today.')->with(NoticeCode::REPLACED_AS_ASKED, "I've added the workout to your plan and saved it to your dashboard."));

        $this->assertSame([NoticeCode::REPLACED_AS_ASKED], $reply->codes(), 'Nothing of the model\'s is a claim, so nothing is corrected.');
    }

    public function test_a_claim_is_not_corrected_twice_when_the_application_already_said_nothing_changed(): void
    {
        $reply = $this->corrected(ReplyText::fromModel("I've saved it to your plan.")->with(NoticeCode::SAVE_FAILED, "I haven't changed your plan: the workout was not saved."));

        $this->assertSame([NoticeCode::SAVE_FAILED], $reply->codes());

        $access = $this->corrected(ReplyText::fromModel("I don't have access to the plan tool.")->with(NoticeCode::SAVE_FAILED, "I haven't changed your plan: the workout was not saved."));

        $this->assertSame([NoticeCode::SAVE_FAILED, NoticeCode::CLAIM_ACCESS_CORRECTED], $access->codes());
        $this->assertStringNotContainsString("I haven't changed anything this time.", $access->text(), 'It has been said once already.');
    }

    public function test_a_claim_with_a_real_write_behind_it_is_left_alone_and_without_one_it_is_corrected(): void
    {
        $wrote = [['name' => 'create_workout_plan', 'arguments' => [], 'result' => ['id' => 1, 'title' => 'Upper body']]];
        $failed = [['name' => 'create_workout_plan', 'arguments' => [], 'result' => ['error' => 'Not done: nothing was changed.', 'blocked' => true]]];

        $this->assertSame([], $this->corrected(ReplyText::fromModel("I've added it to your plan."), $wrote)->codes());
        $this->assertSame([], $this->corrected(ReplyText::fromModel('Ya lo agregué a tu plan.'), $wrote)->codes());
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->corrected(ReplyText::fromModel("I've added it to your plan."), $failed)->codes());
        $this->assertSame([NoticeCode::CLAIM_SAVED_CORRECTED], $this->corrected(ReplyText::fromModel('Ya lo agregué a tu plan.'), $failed)->codes());
    }
}
