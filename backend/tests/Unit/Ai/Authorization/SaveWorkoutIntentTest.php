<?php

namespace Tests\Unit\Ai\Authorization;

use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Production: "Add this workout to my dashboard" was read as an unclear wish, so the plan tool was withheld and the
 * coach said it could not do it. A request to put a workout on the plan is an explicit write request, for that one
 * day's plan and nothing else: it may never raise a load, and it grants nothing when it is a question, a denial,
 * past tense, someone else's words or about something other than the plan.
 */
class SaveWorkoutIntentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function requests(): array
    {
        return array_map(fn (string $m) => [$m], [
            'the production phrase' => 'Add this workout to my dashboard',
            'with a full stop' => 'Add this workout to my dashboard.',
            'lower case, no noun' => 'add this to my dashboard',
            'it' => 'Add it to my plan',
            'save' => 'Save this workout',
            'save to the weekly plan' => 'Save this workout to my weekly plan',
            'push' => 'Push this to my dashboard',
            'polite question form' => 'Can you add this workout to my dashboard?',
            'could you please' => "Could you please add that workout to today's plan",
            'a named day' => 'Add that to my plan for Friday',
            'use' => 'Use this workout for today',
            'put it' => 'Put it on my dashboard',
            'the workout' => 'Add the workout to my plan',
            'a workout the user names themselves' => 'Add a 30 minute treadmill run to my plan for tomorrow',
            'a quick workout' => 'Add a quick workout to my dashboard',
            'after another sentence' => 'That looks good. Add this workout to my dashboard.',
            'put the workout (also reads as "change my plan")' => 'Put this workout on my dashboard',
            'apply' => 'Apply this workout to my plan',
            'schedule for a day' => 'Schedule this workout for tomorrow',
            'with the replacement said' => "Replace today's workout with this one",
        ]);
    }

    /** @return array<string, array{string}> */
    public static function notRequests(): array
    {
        return array_map(fn (string $m) => [$m], [
            'a question about whether to' => 'Should I add this workout to my plan?',
            'a how-to question' => 'How do I add this workout to my dashboard?',
            'where' => 'Where can I add a workout?',
            'a denial' => "Don't add this workout to my plan",
            'no need' => 'No need to add this workout to my dashboard',
            'past tense' => 'I added this workout to my plan yesterday',
            'something else entirely' => 'Add this to my shopping list',
            'attributed to a trainer' => 'My trainer said add this workout to my plan',
            'quoted' => 'The note says "add this workout to my plan"',
            'a bare yes' => 'yes',
            'a bare save' => 'Save it',
            'a performance question' => 'What should I focus on next?',
            'a plain statement about it' => 'This workout looks hard',
        ]);
    }

    #[DataProvider('requests')]
    public function test_a_request_to_put_a_workout_on_the_plan_grants_writing_that_plan_and_nothing_more(string $message): void
    {
        $authorization = (new PlanChangeIntentClassifier)->classify($message);

        $this->assertSame(['plan_create'], $authorization->grantedPlanCapabilities(), $message);
        $this->assertFalse($authorization->mayRaiseLoads, 'Putting a workout on the plan never asks for a heavier load.');
        $this->assertSame([], $authorization->statedLoadsKg, 'Weights are not taken from this message either.');
        $this->assertNull($authorization->followUp);
        $this->assertMatchesRegularExpression('/^(save|add)_workout$/', $authorization->basis);
    }

    #[DataProvider('notRequests')]
    public function test_anything_else_grants_no_plan_write(string $message): void
    {
        $authorization = (new PlanChangeIntentClassifier)->classify($message);

        $this->assertSame([], $authorization->grantedPlanCapabilities(), $message);
        $this->assertFalse($authorization->mayRaiseLoads);
    }

    public function test_a_save_request_pasted_inside_a_long_message_is_not_an_instruction(): void
    {
        $pasted = 'Add this workout to my plan. '.str_repeat('Here is the article I found about training. ', 20);

        $this->assertSame([], (new PlanChangeIntentClassifier)->classify($pasted)->grantedPlanCapabilities());
    }

    /** @return array<string, array{string, bool}> */
    public static function replacementWording(): array
    {
        return [
            'replace today\'s workout' => ["Replace today's workout with this one", true],
            'add and replace what is there' => ["Add this workout to my dashboard and replace what's there", true],
            'overwrite' => ['Add this workout to my plan and overwrite the current one', true],
            'plain add' => ['Add this workout to my dashboard', false],
            'a denial' => ["Add this workout to my dashboard, don't replace anything", false],
            'a question' => ['Should I replace today\'s workout with this?', false],
            'told not to' => ["Do not replace today's plan, add this workout to my dashboard", false],
            'told to keep it' => ["Keep today's plan and add this workout to my dashboard instead of replacing it", false],
            'replacing an exercise, not the day' => ['Add this workout to my dashboard, replace the bench with squats', false],
            'quoted' => ['The note says "replace today\'s workout"', false],
        ];
    }

    #[DataProvider('replacementWording')]
    public function test_a_replacement_is_only_stated_when_the_user_says_so_outright(string $message, bool $expected): void
    {
        $this->assertSame($expected, PlanChangeIntentClassifier::statesReplacement($message), $message);
    }

    public function test_a_save_request_never_also_counts_as_the_broad_change_my_plan_permission(): void
    {
        // "put this workout on my plan" matches the broad "change the plan" wording too; the narrower reading wins.
        foreach (['Put this workout on my plan', 'Apply this workout to my plan', 'Schedule this workout for Friday'] as $message) {
            $authorization = (new PlanChangeIntentClassifier)->classify($message);

            $this->assertSame(['plan_create'], $authorization->grantedPlanCapabilities(), $message);
            $this->assertFalse($authorization->mayRaiseLoads, $message);
        }

        // A genuine, separate change request in the same message is still a change request.
        $both = (new PlanChangeIntentClassifier)->classify('Add this workout to my dashboard. Also update my plan.');
        $this->assertContains('plan_adapt', $both->grantedPlanCapabilities());
    }
}
