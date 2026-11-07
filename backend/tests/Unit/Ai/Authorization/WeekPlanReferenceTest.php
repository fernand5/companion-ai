<?php

namespace Tests\Unit\Ai\Authorization;

use App\Models\Message;
use App\Services\Ai\Authorization\WeekPlan;
use App\Services\Ai\Authorization\WeekPlanReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Which multi-day plan "add this plan to my dashboard" means: the exact days and dates, or why it cannot be said. */
class WeekPlanReferenceTest extends TestCase
{
    /** A Thursday. The week is Mon 26 Oct - Sun 1 Nov 2026. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-29', 'America/Bogota');
    }

    private function resolve(string $reply, string $user = 'add this plan to my dashboard', ?CarbonImmutable $today = null): WeekPlan
    {
        $history = new Collection([
            new Message(['role' => 'user', 'content' => 'create a plan for the rest of the week']),
            new Message(['role' => 'assistant', 'content' => $reply]),
            new Message(['role' => 'user', 'content' => $user]),
        ]);

        return (new WeekPlanReference)->resolve($user, $history, $today ?? $this->today());
    }

    private const PRODUCTION = "Here is a plan for the rest of the week:\n\n**Saturday: Full Body Strength**\n* Goblet Squats: 3 sets of 10-12 reps\n* Push-ups: 3 sets of 8-12 reps\n* Plank: 3 sets of 30 seconds\n\n**Sunday: Active Recovery**\n* Easy walk: 30 minutes\n* Light stretching: 10 minutes\n\nIf this plan looks good to you, just say \"add this plan to my dashboard\" and I'll update it for Saturday and Sunday!";

    public function test_the_production_plan_is_two_days_with_their_exercises(): void
    {
        $plan = $this->resolve(self::PRODUCTION);

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));
        $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_WORKOUT], array_column($plan->days, 'kind'));
        $this->assertCount(3, $plan->days[0]['items']);
        $this->assertCount(2, $plan->days[1]['items']);
    }

    public function test_a_rest_day_is_a_rest_day_and_not_a_workout(): void
    {
        $plan = $this->resolve("Plan:\n\n**Saturday – Lower Body**\n1. Goblet Squats 3x10\n2. Plank 3 x 30 seconds\n\n**Sunday – Rest day**\nTake it easy and focus on recovery.\n\nJust say \"add this plan to my dashboard\"!");

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_REST], array_column($plan->days, 'kind'));
        $this->assertSame([], $plan->days[1]['items']);
    }

    public function test_an_explicit_date_is_used_and_must_fit_its_weekday(): void
    {
        $ok = $this->resolve("**Saturday, Oct 31: Legs**\nSquats 3x10\n\n**Sunday, November 1: Walk**\nEasy walk 30 minutes");
        $this->assertTrue($ok->isFound());
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($ok->days, 'date'));
    }

    public function test_a_date_that_is_not_that_weekday_is_asked_about(): void
    {
        $plan = $this->resolve("**Saturday, Nov 1: Legs**\nSquats 3x10\n\n**Sunday, Nov 2: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::DATES, $plan->status);
        $this->assertSame(['Saturday, Nov 1: Legs'], $plan->problems);
    }

    public function test_days_that_have_passed_are_not_silently_dropped_or_moved_to_next_week(): void
    {
        $plan = $this->resolve("**Wednesday: Legs**\nSquats 3x10\n\n**Thursday: Push**\nPush-ups 3x12\n\n**Saturday: Pull**\nRows 3x10");

        $this->assertSame(WeekPlan::PAST_DAYS, $plan->status);
        $this->assertSame(['Wednesday: Legs'], $plan->problems);
    }

    public function test_naming_the_days_picks_only_those(): void
    {
        $reply = "**Wednesday: Legs**\nSquats 3x10\n\n**Saturday: Pull**\nRows 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes";
        $plan = $this->resolve($reply, 'add this plan to my dashboard for Saturday and Sunday');

        $this->assertTrue($plan->isFound(), $plan->status);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));

        $none = $this->resolve($reply, 'add this plan to my dashboard for Friday');
        $this->assertSame(WeekPlan::DATES, $none->status, 'Friday is not in the plan.');
    }

    public function test_a_day_with_nothing_in_it_is_asked_about_not_invented(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Mobility**\nSome light movement of your choice.");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
        $this->assertSame(['Sunday: Mobility'], $plan->problems);
    }

    public function test_a_rest_day_that_lists_work_is_asked_about(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Rest day**\nLight walk 20 minutes");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
    }

    public function test_an_unfinished_day_is_asked_about(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10, lunges 3x10, etc.\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
        $this->assertSame(['Saturday: Legs'], $plan->problems);
    }

    public function test_an_alternative_has_to_be_chosen_per_the_users_words(): void
    {
        $reply = "**Saturday: Legs**\n1. Romanian Deadlift or Goblet Squat 3x10\n2. Plank 3 x 30 seconds\n\n**Sunday: Walk**\nEasy walk 30 minutes";

        $ask = $this->resolve($reply);
        $this->assertSame(WeekPlan::CHOICE, $ask->status);
        $this->assertSame([['Romanian Deadlift', 'Goblet Squat']], array_column($ask->choices, 'options'));
        $this->assertSame('Saturday: Legs', $ask->choices[0]['day']);

        $chosen = $this->resolve($reply, "add this plan to my dashboard\nGoblet Squat");
        $this->assertTrue($chosen->isFound());
        $this->assertStringContainsString('Goblet Squat', $chosen->days[0]['items'][0]);
        $this->assertStringNotContainsString('Romanian', $chosen->days[0]['items'][0]);
    }

    public function test_next_week_without_dates_is_asked_about(): void
    {
        $plan = $this->resolve("Here is your plan for next week:\n\n**Monday: Legs**\nSquats 3x10\n\n**Tuesday: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::DATES, $plan->status);
    }

    public function test_the_rest_of_the_week_is_not_an_unfinished_plan(): void
    {
        $this->assertTrue($this->resolve(self::PRODUCTION)->isFound());
    }

    public function test_one_day_or_no_days_is_not_a_week(): void
    {
        $this->assertSame(WeekPlan::NOT_A_WEEK, $this->resolve("Here you go:\n\n**Saturday: Legs**\nSquats 3x10\nLunges 3x10")->status);
        $this->assertSame(WeekPlan::NOT_A_WEEK, $this->resolve('Do 3 sets of 10 squats and 3x12 push-ups.')->status);
        $this->assertSame(WeekPlan::NOT_A_WEEK, $this->resolve('Saturday and Sunday are good days for a long walk.')->status);
    }

    public function test_a_note_between_days_does_not_drop_the_days_after_it(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\nNote: keep your back straight.\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertTrue($plan->isFound(), $plan->status);
        $this->assertCount(2, $plan->days);
        $this->assertSame(['Squats 3x10'], $plan->days[0]['items']);
    }

    public function test_a_plan_the_user_wrote_themselves_is_used(): void
    {
        $history = new Collection([new Message(['role' => 'user', 'content' => "Add to my dashboard:\nSaturday: squats 3x10\nSunday: rest"])]);
        $plan = (new WeekPlanReference)->resolve("Add to my dashboard:\nSaturday: squats 3x10\nSunday: rest", $history, $this->today());

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_REST], array_column($plan->days, 'kind'));
    }

    public function test_more_than_seven_days_is_refused(): void
    {
        $lines = [];
        foreach (range(0, 8) as $i) {
            $d = CarbonImmutable::parse('2026-10-31')->addDays($i);
            $lines[] = '**'.$d->format('l, M j').": Walk**\nEasy walk 30 minutes";
        }

        $this->assertSame(WeekPlan::TOO_MANY, $this->resolve(implode("\n\n", $lines))->status);
    }

    // ---- what counts as a day of the plan ------------------------------------------------------------------------------

    public function test_a_day_heading_that_appears_twice_is_refused_not_merged(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes\n\n**Saturday: Arms**\nCurls 3x10");

        $this->assertSame(WeekPlan::DATES, $plan->status);
        $this->assertSame(['Saturday: Arms'], $plan->problems);
        $this->assertStringContainsString('twice', $plan->detail);
    }

    public function test_a_block_quoted_example_day_is_never_a_day_and_stops_the_save(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes\n\nSomeone else suggested:\n> Monday: Rest\n");

        $this->assertSame(WeekPlan::QUOTED, $plan->status);
    }

    public function test_a_quoted_phrase_inside_a_sentence_is_not_a_day(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes\n\nYou could also write \"Friday: rest\" in your own notes.");

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertCount(2, $plan->days);
    }

    public function test_a_sentence_that_lists_days_is_not_a_day(): void
    {
        $plan = $this->resolve("Saturday, Sunday and Monday are your training days. Saturday - Sunday is the weekend.\n\n**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_column($plan->days, 'date'));
    }

    // ---- nothing is silently left out or merged -------------------------------------------------------------------------

    public function test_options_inside_a_day_are_not_saved_together_as_one_workout(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nOption A: Squats 3x10\nOption B: Lunges 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
        $this->assertStringContainsString('alternatives', $plan->detail);
        $this->assertSame(['Saturday: Legs'], $plan->problems);
    }

    public function test_an_exercise_line_with_no_amount_is_asked_about_and_not_dropped(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\n* Squats 3x10\n* Burpees\n* Lunges 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
        $this->assertStringContainsString('Burpees', $plan->detail);
    }

    public function test_warm_ups_notes_and_sub_headings_are_not_exercises_that_would_be_dropped(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\n* Warm-up: easy movement\n* Squats 3x10\n* Lunges 3x10\n* Cool down: light stretching\n* Focus on form\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['Squats 3x10', 'Lunges 3x10'], $plan->days[0]['items']);
    }

    public function test_exercises_hidden_under_a_note_are_asked_about(): void
    {
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\nNote: keep your back straight.\nAlso add calf raises 3x15 at the end.\n\n**Sunday: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status);
        $this->assertStringContainsString('note', $plan->detail);
    }

    public function test_a_rest_day_that_hints_at_an_activity_or_says_rest_or_something_else_is_asked_about(): void
    {
        foreach (['**Sunday: Rest or light walk**', "**Sunday: Rest day**\nGentle walking is fine if you feel like it.", '**Sunday: Rest / yoga**', '**Sunday: Rest or active recovery**'] as $sunday) {
            $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n{$sunday}");

            $this->assertSame(WeekPlan::UNCLEAR_DAY, $plan->status, $sunday);
        }

        $clean = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Rest day**\nLet your body recover.");
        $this->assertSame([WeekPlan::KIND_WORKOUT, WeekPlan::KIND_REST], array_column($clean->days, 'kind'));
    }

    public function test_the_days_the_user_left_out_are_recorded_so_they_can_be_said_back(): void
    {
        $plan = $this->resolve("**Saturday: Pull**\nRows 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes", 'add this plan to my dashboard for Saturday');

        $this->assertTrue($plan->isFound());
        $this->assertSame(['2026-10-31'], array_column($plan->days, 'date'));
        $this->assertSame(['Sunday: Walk'], $plan->omitted);

        $this->assertSame([], $this->resolve("**Saturday: Pull**\nRows 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes")->omitted);
    }

    // ---- dates ------------------------------------------------------------------------------------------------------------

    public function test_days_scattered_over_several_weeks_are_not_one_plan(): void
    {
        $plan = $this->resolve("**Saturday, Oct 31: Legs**\nSquats 3x10\n\n**Saturday, Nov 14: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::DATES, $plan->status);
        $this->assertStringContainsString('more than a week apart', $plan->detail);
    }

    public function test_a_day_more_than_two_months_ahead_is_refused(): void
    {
        $plan = $this->resolve("**Friday, Jan 15: Legs**\nSquats 3x10\n\n**Saturday, Jan 16: Walk**\nEasy walk 30 minutes");

        $this->assertSame(WeekPlan::DATES, $plan->status);
        $this->assertStringContainsString('two months', $plan->detail);
    }

    public function test_explicit_dates_run_across_the_new_year_and_keep_their_exact_day(): void
    {
        $plan = $this->resolve("**Thursday, Dec 31: Legs**\nSquats 3x10\n\n**Friday, Jan 1: Walk**\nEasy walk 30 minutes", 'add this plan to my dashboard', CarbonImmutable::parse('2026-12-29', 'America/Bogota'));

        $this->assertTrue($plan->isFound(), $plan->status.' '.$plan->detail);
        $this->assertSame(['2026-12-31', '2027-01-01'], array_column($plan->days, 'date'));
    }

    public function test_a_weekday_with_no_date_is_always_a_day_of_the_users_current_week(): void
    {
        // A Sunday: "Saturday" is six days ago in this Monday-Sunday week, never next Saturday.
        $plan = $this->resolve("**Saturday: Legs**\nSquats 3x10\n\n**Sunday: Walk**\nEasy walk 30 minutes", 'add this plan to my dashboard', CarbonImmutable::parse('2026-11-01', 'America/Bogota'));

        $this->assertSame(WeekPlan::PAST_DAYS, $plan->status);
        $this->assertSame(['Saturday: Legs'], $plan->problems);
    }
}
