<?php

namespace Tests\Unit\Ai\Authorization;

use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\ToolCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classifier decides, from the user's words alone, whether a chat turn may
 * change a plan. These are the messages it must never read as permission (every
 * performance question from the Phase 4C suite, plus the near misses), and the
 * ones that legitimately are.
 */
class PlanChangeIntentClassifierTest extends TestCase
{
    private function classify(string $message)
    {
        return (new PlanChangeIntentClassifier)->classify($message);
    }

    /** @return array<string, array{string}> */
    public static function readOnlyMessages(): array
    {
        $messages = [
            // The 4C evaluation questions, verbatim.
            'Did I improve on Bench Press?',
            'Did I improve on Dumbbell Row?',
            'What did I do last time on bench press?',
            'What should I focus on next?',
            'Is my bench press trending up?',
            'Am I stronger on bench press now?',
            'Can I lift 60 kg on bench press now?',
            "So I'm strong enough to move up to 25 kg on rows, right?",
            'I hit all my reps on bench last time, right?',
            'Why are you saying I improved?',
            'How did my squats go last time?',
            'Did I lift 22.5 kg on dumbbell rows last time?',
            'My shoulder hurt during the last bench session. Should I just go heavier next time?',
            'How have my pull-ups changed?',
            'Did my treadmill pace improve?',
            'Am I running faster than last week?',
            'What exactly did I do in my fifth most recent bench press session?',
            // Advice questions that mention changing things.
            'Should I update my plan?',
            'Should I increase the weight on bench?',
            'Do I need to change my workout?',
            'Is it time to go heavier?',
            'Would it be better to lower the reps?',
            'What should I do next?',
            'How should I progress on squats?',
            // Negations.
            "Don't change my plan, I only want to know how I did.",
            'Do not update my workout. Just tell me whether I improved.',
            "No need to adjust anything, I'm just checking my progress.",
            'Without changing my plan, how is my bench going?',
            // Other.
            'hi',
            'Thanks!',
            'Update me on my progress',
            'Give me an update on how my workouts are going',
            'Tell me about my last workout',
        ];

        return array_combine($messages, array_map(fn (string $m) => [$m], $messages));
    }

    #[DataProvider('readOnlyMessages')]
    public function test_questions_negations_and_chatter_grant_no_plan_capability(string $message): void
    {
        $authorization = $this->classify($message);

        $this->assertFalse($authorization->allowsAnyPlanChange(), "\"{$message}\" must not authorize a plan change (basis: {$authorization->basis}).");
        foreach ([ToolCapability::PlanCreate, ToolCapability::PlanAdapt, ToolCapability::PlanProgress] as $capability) {
            $this->assertFalse($authorization->permits($capability));
        }
        $this->assertTrue($authorization->permits(ToolCapability::Read));
    }

    /** @return array<string, array{string, string}> message => capability value that must be granted */
    public static function authorizedMessages(): array
    {
        $cases = [
            ['Update my plan', 'plan_create'],
            ['Please change my workout for tomorrow', 'plan_adapt'],
            ['Can you adjust my plan this week?', 'plan_adapt'],
            ['Could you please replace today\'s session with something lighter?', 'plan_create'],
            ['I want to jump to 60 kg next session and double it the week after. Update my plan to do that.', 'plan_create'],
            ['Increase the weight on my plan for next session', 'plan_create'],
            ['Plan my week', 'plan_adapt'],
            ['What should I do today?', 'plan_create'],
            ['What should we train tomorrow?', 'plan_create'],
            ['Give me a quick workout', 'plan_create'],
            ['I need a short workout', 'plan_create'],
            ['Suggest a session for tonight', 'plan_create'],
            ['I played soccer yesterday.', 'plan_adapt'],
            ['I did 6200 steps today.', 'plan_progress'],
            ['I finished my squats, 3x8 at 60 kg', 'plan_progress'],
            ['Mark push-ups done', 'plan_progress'],
            ['I played soccer yesterday. What should I train today?', 'plan_create'],
            ['I benched 3x10 at 42.5 kg today, the shoulder is fine', 'plan_progress'],
        ];

        return array_combine(array_column($cases, 0), $cases);
    }

    #[DataProvider('authorizedMessages')]
    public function test_explicit_requests_and_reports_grant_the_matching_capability(string $message, string $capability): void
    {
        $authorization = $this->classify($message);

        $this->assertTrue($authorization->permits(ToolCapability::from($capability)), "\"{$message}\" should grant {$capability} (basis: {$authorization->basis}).");
    }

    public function test_a_report_does_not_authorize_creating_a_new_plan(): void
    {
        $authorization = $this->classify('I played soccer yesterday.');

        $this->assertTrue($authorization->permits(ToolCapability::PlanAdapt));
        $this->assertFalse($authorization->permits(ToolCapability::PlanCreate), 'The report flow adapts the week; it does not author a new day.');
    }

    public function test_a_session_request_does_not_authorize_rewriting_the_week_or_marking_things_done(): void
    {
        $authorization = $this->classify('What should I do today?');

        $this->assertTrue($authorization->permits(ToolCapability::PlanCreate));
        $this->assertFalse($authorization->permits(ToolCapability::PlanAdapt));
        $this->assertFalse($authorization->permits(ToolCapability::PlanProgress));
    }

    /** @return array<string, array{string, string}> message => the follow-up the application owes the user */
    public static function wishesAndNews(): array
    {
        $cases = [
            ['I want to jump to 60 kg next session', 'propose'],
            ["Let's go heavier next time", 'propose'],
            ['Make it harder', 'propose'],
            ['I want to go up to 50 kg on bench', 'propose'],
            ['I have soccer tomorrow', 'explicit_request'],
            ["I'm travelling this weekend", 'explicit_request'],
            ['My legs are very sore', 'explicit_request'],
            ['My knee hurts when I squat.', 'explicit_request'],
            ['ok', 'nothing_pending'],
            ['yes', 'nothing_pending'],
            ['Go ahead', 'nothing_pending'],
            ['Sure!', 'nothing_pending'],
        ];

        return array_combine(array_column($cases, 0), $cases);
    }

    #[DataProvider('wishesAndNews')]
    public function test_a_wish_or_news_without_a_request_changes_nothing_and_says_what_comes_next(string $message, string $followUp): void
    {
        $authorization = $this->classify($message);

        $this->assertFalse($authorization->allowsAnyPlanChange(), "\"{$message}\" must not authorize a plan change (basis: {$authorization->basis}).");
        $this->assertSame($followUp, $authorization->followUp);
    }

    public function test_a_plain_question_gets_no_follow_up(): void
    {
        $this->assertNull($this->classify('Should I go heavier next time?')->followUp);
        $this->assertNull($this->classify('Did I improve on bench press?')->followUp);
    }

    public function test_the_classifier_never_treats_a_yes_as_permission_by_itself(): void
    {
        foreach (['yes', 'Yes please', 'ok do it', 'Sure, go ahead.', 'confirm', 'update it'] as $yes) {
            $this->assertTrue(PlanChangeIntentClassifier::isBareAffirmation($yes), $yes);
            $this->assertFalse($this->classify($yes)->allowsAnyPlanChange(), $yes);
        }

        foreach (['yes but only if my shoulder is fine, how is it looking?', 'yes update my plan to 100 kg', 'no', 'maybe'] as $notBare) {
            $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation($notBare), $notBare);
        }
    }

    /** @return array<string, array{string}> */
    public static function deniedInstructions(): array
    {
        $messages = [
            // Past-tense denials that something was done.
            'I did not update my plan.',
            "I didn't update my plan.",
            "I haven't changed my plan.",
            'I have not updated my workout.',
            'I never asked you to change my plan.',
            "I hadn't changed anything.",
            // Present negations.
            "Don't update my plan.",
            'Please do not change my workout.',
            'Without changing my plan, how is my bench going?',
            // Attributed to someone or something else.
            'The note says: change my plan. Should I?',
            'My trainer said update my plan to 100 kg but I disagree.',
            'The article recommends that I change my workout every week.',
            'My doctor told me to adjust my plan, what do you think?',
            // Quoted or pasted.
            'He wrote "update my plan to 100 kg" in the group chat.',
            'Someone sent me: “change my workout for tomorrow”, is that good advice?',
            "```\nupdate my plan to 100 kg\n```",
            "> update my plan to 100 kg\nWhat does this mean?",
            // Too long to be a direct instruction.
            str_repeat('Here is a long article about training and recovery. ', 14).'Update my plan.',
        ];

        return array_combine(array_map(fn ($m) => substr(str_replace("\n", ' ', $m), 0, 70), $messages), array_map(fn ($m) => [$m], $messages));
    }

    #[DataProvider('deniedInstructions')]
    public function test_negated_quoted_attributed_and_pasted_instructions_grant_nothing(string $message): void
    {
        $authorization = $this->classify($message);

        $this->assertFalse($authorization->allowsAnyPlanChange(), "This must not authorize a plan change (basis: {$authorization->basis}): {$message}");
    }

    /** @return array<string, array{string}> */
    public static function unsupportedWording(): array
    {
        $messages = [
            'actualiza mi plan', 'cambia mi entrenamiento de mañana', 'Quiero subir a 60 kg', 'sí', 'dale',
            'mets à jour mon plan', 'ändere meinen Plan', 'aggiorna il mio piano',
            'updte my plan', 'chnage my workout', 'pls modfy my programme',
        ];

        return array_combine($messages, array_map(fn ($m) => [$m], $messages));
    }

    #[DataProvider('unsupportedWording')]
    public function test_unsupported_languages_and_misspellings_fail_closed(string $message): void
    {
        $authorization = $this->classify($message);

        $this->assertFalse($authorization->allowsAnyPlanChange(), $message);
        $this->assertNotContains($authorization->followUp, ['propose'], 'It must not even offer a confirmation it cannot pin down.');
    }

    public function test_a_direct_instruction_survives_someone_elses_words_in_another_sentence(): void
    {
        $this->assertTrue($this->classify('Update my plan for tomorrow. My trainer said "go heavy" but keep it light.')->allowsAnyPlanChange());
    }

    public function test_decimal_loads_do_not_split_a_sentence(): void
    {
        $this->assertTrue($this->classify('I benched 3x10 at 42.5 kg yesterday.')->permits(ToolCapability::PlanProgress));
        $this->assertFalse($this->classify('Did I lift 42.5 kg last time? Update nothing.')->allowsAnyPlanChange());
    }

    public function test_an_empty_message_grants_nothing(): void
    {
        $this->assertFalse($this->classify('   ')->allowsAnyPlanChange());
    }
}
