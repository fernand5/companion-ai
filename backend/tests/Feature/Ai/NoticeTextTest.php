<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\Authorization\PlanChangeIntentClassifier;
use App\Services\Ai\Authorization\PlanChangeProposal;
use App\Services\Ai\Authorization\PlanReplacementProposal;
use App\Services\Ai\Authorization\SaveWorkoutScope;
use App\Services\Ai\Notices\EnglishCommands;
use App\Services\Ai\Notices\NoticeText;
use App\Support\AppLocale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 / M3b: the words of the application's own notices, in English and Spanish.
 *
 * What is pinned: the two catalogs say the same things (same keys, same placeholders); English is the wording the
 * application has always used (golden strings, and the text the protected modules build themselves); Spanish carries
 * every date, quantity, load and exercise name unchanged; a Spanish message that tells the user to type something tells
 * them the exact English phrase the parsers understand today, and says so; nothing a user wrote is ever read as a
 * translation key; and the language is presentation only.
 */
class NoticeTextTest extends TestCase
{
    use RefreshDatabase;

    private function en(): NoticeText
    {
        return new NoticeText(AppLocale::English);
    }

    private function es(): NoticeText
    {
        return new NoticeText(AppLocale::Spanish);
    }

    /** @return array<string, string> dotted key => sentence */
    private function catalog(string $locale, string $group): array
    {
        $lines = require base_path("lang/{$locale}/{$group}.php");
        $flat = [];

        $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $flat[$prefix.$key] = $value;
            }
        };
        $walk($lines, '');

        return $flat;
    }

    /** @return array<int, string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/(?<!:):([a-z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    // ---- the two catalogs say the same things --------------------------------------------------------------------------------

    #[DataProvider('catalogs')]
    public function test_both_languages_have_exactly_the_same_keys(string $group): void
    {
        $en = array_keys($this->catalog('en', $group));
        $es = array_keys($this->catalog('es', $group));

        $this->assertSame([], array_values(array_diff($en, $es)), "lang/es/{$group}.php lacks keys that English has.");
        $this->assertSame([], array_values(array_diff($es, $en)), "lang/en/{$group}.php lacks keys that Spanish has.");
    }

    #[DataProvider('catalogs')]
    public function test_every_message_uses_the_same_named_placeholders_in_both_languages(string $group): void
    {
        $en = $this->catalog('en', $group);

        foreach ($this->catalog('es', $group) as $key => $spanish) {
            // ":english_note" is the one placeholder that exists only in Spanish (English has nothing to add).
            $this->assertSame(
                array_values(array_diff($this->placeholders($en[$key]), ['english_note'])),
                array_values(array_diff($this->placeholders($spanish), ['english_note'])),
                "{$group}.{$key}: the placeholders differ between the languages.",
            );
        }
    }

    #[DataProvider('catalogs')]
    public function test_no_message_is_empty_and_none_uses_vos_or_usted(string $group): void
    {
        foreach (['en', 'es'] as $locale) {
            foreach ($this->catalog($locale, $group) as $key => $text) {
                if (! ($key === 'english_note' && $locale === 'en')) {
                    $this->assertNotSame('', trim($text), "{$locale} {$group}.{$key} is empty.");
                }

                if ($locale === 'es') {
                    $this->assertDoesNotMatchRegularExpression('/\b(?:usted|ustedes|vos|tenés|querés|podés|decime|mirá)\b/iu', $text, "es {$group}.{$key} must address the user as tú.");
                }
            }
        }
    }

    public static function catalogs(): array
    {
        return ['coach' => ['coach'], 'plan' => ['plan']];
    }

    public function test_a_placeholder_is_filled_for_every_message_in_both_languages(): void
    {
        // Rendered through the real class: nothing may be left as ":something" for the user to read.
        foreach ([$this->en(), $this->es()] as $words) {
            foreach ($this->everyMessage($words) as $name => $text) {
                $this->assertDoesNotMatchRegularExpression('/(?<!:):[a-z_]{3,}/', $text, "{$words->locale()->value}: {$name} left a placeholder unfilled: {$text}");
                $this->assertNotSame('', trim($text), "{$words->locale()->value}: {$name} is empty.");
            }
        }
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function change(string $action, string $date = '2026-11-12', int $existingId = 5, string $existing = '“Old plan” (Squats 3×10)'): array
    {
        return [
            'date' => $date, 'existing_plan_id' => $existingId, 'existing_revision' => 'r', 'existing_summary' => $existing,
            'action' => $action, 'reason' => '',
            'replacement' => $action === PlanReplacementProposal::CLEAR ? null : [
                'planned_date' => $date, 'activity_type' => 'strength', 'title' => 'Upper body',
                'exercises' => [['exercise_name' => 'Push-ups', 'planned_sets' => 3, 'planned_reps' => 12], ['exercise_name' => 'Plank', 'planned_duration_seconds' => 30]],
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function proposal(array $changes): PlanReplacementProposal
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        return PlanReplacementProposal::create(1, 1, $changes);
    }

    private function exerciseProposal(): PlanChangeProposal
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        return PlanChangeProposal::create(1, 1, 10, 20, '2026-11-12', 'bench-press', 'Bench Press', 45.0, 3, 10);
    }

    /** @return array<string, string> */
    private function everyMessage(NoticeText $w): array
    {
        $single = $this->proposal([$this->change('replace')]);
        $clear = $this->proposal([$this->change('clear')]);
        $several = $this->proposal([$this->change('replace'), $this->change('clear', '2026-11-13')]);
        $withNew = $this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned'), $this->change('replace', '2026-11-13')]);
        $saved = [['date' => '2026-11-13', 'action' => 'replace', 'replacement' => $this->change('replace')['replacement']], ['date' => '2026-11-14', 'action' => 'clear']];
        $choices = [['item' => 'x', 'options' => ['Romanian Deadlift', 'Goblet Squat'], 'day' => 'Saturday: Legs']];

        return [
            'noWorkout' => $w->noWorkoutToSave(), 'whichWorkout' => $w->whichWorkout(), 'incomplete' => $w->incompleteWorkout(), 'quoted' => $w->quotedWorkout(),
            'retryNewDay' => $w->retryExpiredNewDay('2026-11-10'), 'retryNewDayUnknown' => $w->retryExpiredNewDay(null), 'retryOld' => $w->retryExpiredTooOld(),
            'whichDayPast' => $w->whichDay(true), 'whichDay' => $w->whichDay(false),
            'choiceOne' => $w->exerciseChoice($choices), 'choiceMany' => $w->exerciseChoice([...$choices, ['item' => 'y', 'options' => ['A', 'B']]]),
            'weekUnclear' => $w->weekQuestion('unclear_day', ['Sunday'], 'it looks cut off or unfinished', 7),
            'weekPast' => $w->weekQuestion('past_days', ['Monday'], '', 7), 'weekNoSuchDay' => $w->weekQuestion('no_such_day', ['Friday'], 'the plan has no such day', 7),
            'weekDates' => $w->weekQuestion('dates', ['Sunday'], 'that day appears twice', 7), 'weekDatesNone' => $w->weekQuestion('dates', [], 'its days are more than a week apart', 7),
            'weekTooMany' => $w->weekQuestion('too_many', [], '', 7), 'weekUnreadable' => $w->weekQuestion('unreadable', [], '', 7),
            'cannotAsk' => $w->cannotAskConfirmation(), 'saveFailed' => $w->saveFailed(), 'dayPerformed' => $w->saveDayPerformed('2026-11-12'),
            'replacedAsAsked' => $w->replacedAsAsked('“Old plan” (Squats 3×10)', '2026-11-12'),
            'replSingle' => $w->replacementQuestion($single), 'replClear' => $w->replacementQuestion($clear), 'replSeveral' => $w->replacementQuestion($several), 'replNew' => $w->replacementQuestion($withNew),
            'confInvalid' => $w->confirmationInvalid(), 'confRefused' => $w->confirmationRefused('a plan appeared on 2026-11-12 after I asked.'),
            'confApplied' => $w->confirmationApplied($withNew), 'confClear' => $w->confirmationApplied($this->proposal([$this->change('clear', '2026-11-12', 0, 'nothing planned'), $this->change('clear', '2026-11-13')])),
            'weekSaved' => $w->weekSaved($saved, ['2026-11-15'], ['Sunday']), 'weekSavedMany' => $w->weekSaved($saved, ['2026-11-15', '2026-11-16'], []),
            'weekBlocked' => $w->weekBlocked([['date' => '2026-11-12', 'label' => 'Thursday', 'reason' => 'started'], ['date' => '2026-11-13', 'label' => 'Friday', 'reason' => 'performed']]),
            'weekAlready' => $w->weekAlready(['2026-11-12']), 'weekNotSaved' => $w->weekNotSaved(null), 'weekNotSavedBecause' => $w->weekNotSaved('2026-11-12 was not saved.'),
            'confirmedFailed' => $w->confirmedChangeFailed(), 'noLongerValid' => $w->proposalNoLongerValid(), 'nothingPending' => $w->nothingPending(false), 'nothingPendingDiscussed' => $w->nothingPending(true),
            'explicitNeeded' => $w->explicitRequestNeeded(), 'changeProposed' => $w->changeProposed($this->exerciseProposal()),
            'aboveMax' => $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]), 'notSpecific' => $w->changeNotSpecific(),
            'noAccess' => $w->noAccessCorrection(false), 'noAccessNothing' => $w->noAccessCorrection(true), 'notSaved' => $w->notSavedCorrection(), 'partial' => $w->partialCorrection(),
            'weeklyNoChange' => $w->weeklyNoChange(), 'weeklyNot' => $w->weeklyNotDetermined(), 'dashboard' => $w->dashboardFallback(),
        ];
    }

    // ---- English is the wording the application has always used -----------------------------------------------------------

    public function test_the_english_notices_are_word_for_word_what_the_application_has_always_said(): void
    {
        $w = $this->en();

        $this->assertSame('I haven\'t changed your plan: I don\'t see a workout in my last reply or in your message to add. Ask me for one (for example "give me a 30-minute workout"), or tell me what to add, like "add this workout to my dashboard: squats 3x10, push-ups 3x12". You can also just reply with the exercises and their sets and reps.', $w->noWorkoutToSave());
        $this->assertSame('I haven\'t changed your plan: more than one workout could be meant and I don\'t want to guess which. Say which one (for example "add option 2 to my dashboard", or just "option 2"), or send the exercises in one message, like "add this workout to my dashboard: squats 3x10, push-ups 3x12".', $w->whichWorkout());
        $this->assertSame('I haven\'t changed your plan: that request to add a workout is more than 15 minutes old, and I only pick up a request while it is fresh, so nothing was saved. To add the workout, say "add this workout to my dashboard" (and the day, if you want one other than today).', $w->retryExpiredTooOld());
        $this->assertSame('I haven\'t changed your plan: that request to add a workout was made on 2026-11-10, and a new day has started, so I won\'t carry it over and guess which day you mean now. To add the workout, say "add this workout to my dashboard" (and the day, if you want one other than today).', $w->retryExpiredNewDay('2026-11-10'));
        $this->assertSame('I haven\'t changed your plan: I\'m not sure which day you mean. I can add a workout to today or to a day in the next two months. Which day should I use? Reply with just the day, for example "Friday" or "tomorrow".', $w->whichDay(false));
        $this->assertSame('I haven\'t changed your plan, and nothing is wrong with saving it: one thing in the workout is a choice. It lists "Romanian Deadlift" or "Goblet Squat" (Saturday: Legs). Which should I add? Reply with just the name, for example "Romanian Deadlift".', $w->exerciseChoice([['item' => 'x', 'options' => ['Romanian Deadlift', 'Goblet Squat'], 'day' => 'Saturday: Legs']]));
        $this->assertSame('I haven\'t changed your plan: the workout was not saved. Ask me again with "add this workout to my dashboard", or send the exercises in one message.', $w->saveFailed());
        $this->assertSame('I haven\'t changed your plan: 2026-11-12 already has recorded workout performance, so its plan can\'t be replaced. Pick another day, like "add this workout to my dashboard for tomorrow".', $w->saveDayPerformed('2026-11-12'));
        $this->assertSame('As you asked, I replaced “Old plan” (Squats 3×10) on 2026-11-12 with this workout.', $w->replacedAsAsked('“Old plan” (Squats 3×10)', '2026-11-12'));
        $this->assertSame('I haven\'t changed your plan: that would replace a day that already has a plan, and I couldn\'t ask you to confirm it just now. Please try again.', $w->cannotAskConfirmation());
        $this->assertSame('I haven\'t changed your plan: that confirmation is no longer valid (it expired, was already used, or is not for this conversation). If you still want the workout on your plan, ask me again.', $w->confirmationInvalid());
        $this->assertSame('I haven\'t replaced it: a plan appeared on 2026-11-12 after I asked. Nothing was changed. If you still want the workout on your plan, ask me again.', $w->confirmationRefused('a plan appeared on 2026-11-12 after I asked.'));
        $this->assertSame('To change your plan, send an explicit request that names the exercise, the load in kg and the day, for example "update my bench press to 45 kg on 2026-10-17".', (fn () => $this->explicit())->call($w));
        $this->assertSame('There is nothing waiting for your confirmation (it may have expired). To put the workout we just discussed on your plan, say "add this workout to my dashboard". To change your plan, send an explicit request that names the exercise, the load in kg and the day, for example "update my bench press to 45 kg on 2026-10-17".', $w->nothingPending(true));
        $this->assertSame('I haven\'t changed your plan. Do you want me to set Bench Press to 45 kg (3×10) on 2026-11-12? Reply "yes" within 15 minutes to confirm exactly that, and nothing else. The limit on how much one change may add only caps its size; it does not mean the load suits you.', $w->changeProposed($this->exerciseProposal()));
        $this->assertSame('I haven\'t changed your plan, and I can\'t offer 60 kg: one change may add at most 10% to your heaviest recorded load (40 kg), which is 44 kg. That is a maximum, not a recommendation, and it is not a judgment that any load up to it suits you.', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]));
        $this->assertSame('To be clear, that is not a limit of the app: I can add and change workouts on your plan whenever you ask. I haven\'t changed anything this time. To add a workout I have just shown you, say "add this workout to my dashboard" (or "try again" if you have already asked).', $w->noAccessCorrection(true));
        $this->assertSame('I haven\'t changed your plan: nothing was saved this time, whatever the message above says. To add a workout I have just shown you, say "add this workout to my dashboard".', $w->notSavedCorrection());
        $this->assertSame('No change needed.', $w->weeklyNoChange());
        $this->assertSame('Could not determine a plan change from that — try rephrasing.', $w->weeklyNotDetermined());
        $this->assertSame('Log today\'s activity and I\'ll tailor a recommendation.', $w->dashboardFallback());
    }

    public function test_the_english_plan_descriptions_are_word_for_word_what_the_protected_proposal_code_says(): void
    {
        $w = $this->en();

        // The proposal classes word these in English themselves; the catalog must not drift from them.
        foreach ([
            $this->change('create', '2026-11-12', 0, 'nothing planned'),
            $this->change('clear', '2026-11-12', 0, 'nothing planned'),
            $this->change('clear'),
            $this->change('replace'),
        ] as $change) {
            $this->assertSame(PlanReplacementProposal::describeChange($change), $w->describeChange($change));
        }

        $proposal = $this->exerciseProposal();
        $this->assertSame($proposal->describe(), $w->describeProposal($proposal));

        $noAmount = PlanChangeProposal::create(1, 1, 10, 20, '2026-11-12', 'plank', 'Plank', 12.5, null, null);
        $this->assertSame($noAmount->describe(), $w->describeProposal($noAmount));
    }

    public function test_the_english_replacement_questions_keep_their_exact_shape(): void
    {
        $w = $this->en();

        $this->assertSame('I haven\'t changed your plan. 2026-11-12 already has “Old plan” (Squats 3×10). Do you want me to replace it with “Upper body” (Push-ups 3×12, Plank 30 s)? Reply "yes" within 15 minutes to replace it with exactly that; anything else leaves it as it is.', $w->replacementQuestion($this->proposal([$this->change('replace')])));
        $this->assertSame('I haven\'t changed your plan. 2026-11-12 already has a rest day. Do you want me to turn it into a rest day? Reply "yes" within 15 minutes to do exactly that; anything else leaves it as it is.', $w->replacementQuestion($this->proposal([$this->change('clear', '2026-11-12', 5, 'a rest day')])));
        $this->assertSame(
            "I haven't changed your plan yet. These days already have a plan:\n- 2026-11-12: “Old plan” (Squats 3×10) would be replaced with “Upper body” (Push-ups 3×12, Plank 30 s)\n- 2026-11-13: “Old plan” (Squats 3×10) would become a rest day\nDo you want me to make all of these changes? Reply \"yes\" within 15 minutes to make exactly these changes and nothing else; anything else leaves your plan as it is.",
            $w->replacementQuestion($this->proposal([$this->change('replace'), $this->change('clear', '2026-11-13')])),
        );
        $this->assertStringStartsWith("I haven't changed your plan yet. Saving this plan would make these changes, and some of these days already have a plan that would be replaced:\n- 2026-11-12: “Upper body” (Push-ups 3×12, Plank 30 s) would be added (nothing is planned yet)", $w->replacementQuestion($this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned'), $this->change('replace', '2026-11-13')])));
    }

    public function test_a_plan_of_several_days_is_worded_in_english_as_it_always_was(): void
    {
        $w = $this->en();
        $saved = [['date' => '2026-11-13', 'action' => 'replace', 'replacement' => $this->change('replace')['replacement']], ['date' => '2026-11-14', 'action' => 'clear']];

        $this->assertSame(
            "Done. I added this to your plan, all together, and checked that it is saved:\n- Friday, Nov 13: “Upper body” (Push-ups 3×12, Plank 30 s)\n- Saturday, Nov 14: rest day 2026-11-15 was already on your plan exactly like this, so I left it as it is. I left out Sunday because you named only the days above; say \"add this plan to my dashboard\" again if you want the rest.",
            $w->weekSaved($saved, ['2026-11-15'], ['Sunday']),
        );
        $this->assertSame('2026-11-15, 2026-11-16 were already on your plan exactly like this, so I left them as they are.', substr($w->weekSaved($saved, ['2026-11-15', '2026-11-16'], []), strpos($w->weekSaved($saved, ['2026-11-15', '2026-11-16'], []), '2026-11-15')));
        $this->assertSame('I haven\'t changed your plan, and I didn\'t save any day of it: 2026-11-12 (Thursday: already started), 2026-11-13 (Friday: already has recorded workout performance) can\'t be replaced, and I save a plan whole or not at all so your week is never half done. To add the other days, reply with just those days, for example "Sunday" (or "add this plan to my dashboard for Sunday").', $w->weekBlocked([['date' => '2026-11-12', 'label' => 'Thursday', 'reason' => 'started'], ['date' => '2026-11-13', 'label' => 'Friday', 'reason' => 'performed']]));
        $this->assertSame('Everything in that plan (2026-11-12, 2026-11-13) is already on your plan exactly as shown, so there was nothing to change.', $w->weekAlready(['2026-11-12', '2026-11-13']));
        $this->assertSame('I haven\'t changed your plan: the plan was not saved, and nothing was saved for any day. Ask me again with "add this plan to my dashboard".', $w->weekNotSaved(null));
        $this->assertSame('I haven\'t changed your plan: 2026-11-12 was not saved. Nothing was saved for any day. Ask me again with "add this plan to my dashboard".', $w->weekNotSaved('2026-11-12 was not saved.'));
        $this->assertSame('I haven\'t changed your plan: I can\'t tell which dates this plan is for ("Sunday": that day appears twice). Nothing was saved for any day. Ask me to write the plan with a date on each day (for example "Saturday, Oct 31"), then say "add this plan to my dashboard" again.', $w->weekQuestion('dates', ['Sunday'], 'that day appears twice', 7));
        $this->assertSame('I haven\'t changed your plan: that plan has more than 7 days, which is more than I can add at once. Nothing was saved for any day. Ask me to add it a week at a time.', $w->weekQuestion('too_many', [], '', 7));
    }

    // ---- Spanish: the same facts, in Spanish ------------------------------------------------------------------------------------------

    public function test_spanish_carries_every_date_quantity_load_and_name_unchanged(): void
    {
        $w = $this->es();

        $text = $w->replacementQuestion($this->proposal([$this->change('replace')]));
        $this->assertStringContainsString('El 2026-11-12 ya tiene “Old plan” (Squats 3×10)', $text);
        $this->assertStringContainsString('“Upper body” (Push-ups 3×12, Plank 30 s)', $text);
        $this->assertStringContainsString('15 minutos', $text);

        $this->assertStringContainsString('Bench Press en 45 kg (3×10) el 2026-11-12', $w->changeProposed($this->exerciseProposal()));
        $this->assertStringContainsString('60 kg', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]));
        $this->assertStringContainsString('(40 kg)', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]));
        $this->assertStringContainsString('44 kg', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]));
        $this->assertStringContainsString('10%', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]), 'The 10% ceiling is stated, not rounded away.');
        $this->assertStringContainsString('Eso es un máximo, no una recomendación', $w->aboveMaximumChange(['requested_kg' => 60.0, 'recorded_kg' => 40.0, 'maximum_kg' => 44.0]));
        $this->assertStringContainsString('no significa que la carga sea adecuada para ti', $w->changeProposed($this->exerciseProposal()), 'The safety caveat is kept.');
    }

    public function test_the_spanish_replacement_questions_and_refusals_say_what_the_english_ones_say(): void
    {
        $w = $this->es();

        $this->assertSame(
            'No he cambiado tu plan. El 2026-11-12 ya tiene “Old plan” (Squats 3×10). ¿Quieres que lo reemplace por “Upper body” (Push-ups 3×12, Plank 30 s)? Responde "yes" en los próximos 15 minutos para reemplazarlo exactamente por eso; cualquier otra respuesta lo deja como está. Por ahora, las frases entre comillas deben escribirse en inglés.',
            $w->replacementQuestion($this->proposal([$this->change('replace')])),
        );
        $this->assertStringContainsString('El 2026-11-12 ya tiene un día de descanso. ¿Quieres que lo convierta en día de descanso?', $w->replacementQuestion($this->proposal([$this->change('clear', '2026-11-12', 5, 'a rest day')])));
        $several = $w->replacementQuestion($this->proposal([$this->change('replace'), $this->change('clear', '2026-11-13')]));
        $this->assertStringContainsString("Todavía no he cambiado tu plan. Estos días ya tienen un plan:\n- 2026-11-12: “Old plan” (Squats 3×10) se reemplazaría por “Upper body” (Push-ups 3×12, Plank 30 s)\n- 2026-11-13: “Old plan” (Squats 3×10) pasaría a ser un día de descanso\n¿Quieres que haga todos estos cambios?", $several);
        $this->assertStringContainsString('(todavía no hay nada planificado)', $w->replacementQuestion($this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned'), $this->change('replace', '2026-11-13')])));
        $this->assertStringContainsString('No lo reemplacé: apareció un plan el 2026-11-12 después de que te pregunté. No se cambió nada.', $w->confirmationRefused('a plan appeared on 2026-11-12 after I asked.'));
        $this->assertStringStartsWith('Listo. Añadí “Upper body” (Push-ups 3×12, Plank 30 s) el 2026-11-12. Comprobé que quedó guardado.', $w->confirmationApplied($this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned')])));
        $this->assertStringContainsString('Marqué el 2026-11-12 como día de descanso. Convertí el 2026-11-13 (“Old plan” (Squats 3×10)) en día de descanso.', $w->confirmationApplied($this->proposal([$this->change('clear', '2026-11-12', 0, 'nothing planned'), $this->change('clear', '2026-11-13')])));
    }

    public function test_a_plan_of_several_days_is_worded_in_spanish(): void
    {
        $w = $this->es();
        $saved = [['date' => '2026-11-13', 'action' => 'replace', 'replacement' => $this->change('replace')['replacement']], ['date' => '2026-11-14', 'action' => 'clear']];

        $text = $w->weekSaved($saved, ['2026-11-15'], ['Sunday']);

        $this->assertStringStartsWith("Listo. Añadí esto a tu plan, todo junto, y comprobé que quedó guardado:\n- viernes, 13 de noviembre: “Upper body” (Push-ups 3×12, Plank 30 s)\n- sábado, 14 de noviembre: día de descanso", $text);
        $this->assertStringContainsString('2026-11-15 ya estaba en tu plan exactamente así', $text);
        $this->assertStringContainsString('Dejé fuera Sunday', $text);
        $this->assertStringContainsString('2026-11-15, 2026-11-16 ya estaban en tu plan', $w->weekSaved($saved, ['2026-11-15', '2026-11-16'], []));
        $this->assertStringContainsString('2026-11-12 (Thursday: ya empezó), 2026-11-13 (Friday: ya tiene un entrenamiento realizado y registrado)', $w->weekBlocked([['date' => '2026-11-12', 'label' => 'Thursday', 'reason' => 'started'], ['date' => '2026-11-13', 'label' => 'Friday', 'reason' => 'performed']]));
        $this->assertStringContainsString('Todo lo de ese plan (2026-11-12, 2026-11-13) ya está en tu plan exactamente como se mostró', $w->weekAlready(['2026-11-12', '2026-11-13']));
        $this->assertStringContainsString('el plan no se guardó, y no se guardó nada de ningún día', $w->weekNotSaved(null));
        $this->assertStringContainsString('el 2026-11-12 no se guardó. No se guardó nada de ningún día.', $w->weekNotSaved('2026-11-12 was not saved.'));
        $this->assertStringContainsString('("Sunday": ese día aparece dos veces)', $w->weekQuestion('dates', ['Sunday'], 'that day appears twice', 7));
        $this->assertStringContainsString('(parece cortado o incompleto)', $w->weekQuestion('unclear_day', ['Sunday'], 'it looks cut off or unfinished', 7));
        $this->assertStringContainsString('la línea "Mystery 5" no indica series, repeticiones ni tiempo', $w->weekQuestion('unclear_day', ['Sunday'], 'the line "Mystery 5" gives no sets, reps or time, so I would have to leave it out', 7));
        $this->assertStringContainsString('(no pude leerlo con seguridad)', $w->weekQuestion('unclear_day', ['Sunday'], 'something new the code started saying', 7), 'An unknown reason is said generically, not left in English.');
    }

    // ---- what the user is told to type stays exactly what the parsers accept ---------------------------------------------------------------

    public function test_every_phrase_the_application_asks_the_user_to_type_is_understood_by_the_english_parsers(): void
    {
        $classifier = app(PlanChangeIntentClassifier::class);

        foreach (['save_workout', 'save_workout_tomorrow', 'save_plan', 'save_plan_sunday', 'example', 'option_command'] as $name) {
            $basis = explode('+', $classifier->classify(EnglishCommands::phrase($name))->basis);
            $this->assertNotSame([], array_intersect($basis, [PlanChangeIntentClassifier::BASIS_SAVE_WORKOUT, 'add_workout']), '"'.EnglishCommands::phrase($name)."\" must still be read as a request to save: {$name}");
        }

        $this->assertTrue(PlanChangeIntentClassifier::isBareAffirmation(EnglishCommands::phrase('yes')));
        $this->assertTrue(PlanChangeIntentClassifier::isBareAffirmation('Yes'));
        // ... and a Spanish "sí" is NOT understood, so no Spanish message may ever tell the user to say it.
        $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation('sí'));
        $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation('si'));
        $this->assertFalse(PlanChangeIntentClassifier::isBareAffirmation('dale'));
    }

    public function test_a_spanish_message_that_names_a_phrase_to_type_gives_the_english_phrase_and_says_it_is_english(): void
    {
        $note = trans('coach.english_note', [], 'es');
        $this->assertSame('Por ahora, las frases entre comillas deben escribirse en inglés.', $note);

        foreach ($this->everyMessage($this->es()) as $name => $text) {
            $quotesAnEnglishPhrase = collect(EnglishCommands::all())->contains(fn (string $phrase) => str_contains($text, '"'.$phrase.'"'));

            if ($quotesAnEnglishPhrase) {
                $this->assertStringContainsString($note, $text, "{$name} tells the user to type English and must say so.");
            }

            // No Spanish message tells the user to answer in Spanish: the parsers cannot read it yet.
            $this->assertDoesNotMatchRegularExpression('/["«](?:sí|si|dale|claro|vale|confirmo|de acuerdo|añade este entrenamiento|agrega este entrenamiento|guarda este entrenamiento)["»]/iu', $text, "{$name} invents a Spanish command.");
        }
    }

    public function test_english_messages_never_carry_the_spanish_note(): void
    {
        foreach ($this->everyMessage($this->en()) as $name => $text) {
            $this->assertStringNotContainsString('Por ahora', $text, $name);
        }
    }

    public function test_the_only_place_the_typed_phrases_are_written_is_one_class(): void
    {
        // The phrases the parsers accept live in EnglishCommands only; the catalog refers to them by placeholder.
        foreach (['es', 'en'] as $locale) {
            foreach (['coach', 'plan'] as $group) {
                foreach ($this->catalog($locale, $group) as $key => $text) {
                    $written = (string) preg_replace('/(?<!:):[a-z_]+/', '', $text); // the placeholders themselves are fine

                    foreach (EnglishCommands::all() as $phrase) {
                        $this->assertStringNotContainsString('"'.$phrase.'"', $written, "{$locale} {$group}.{$key} writes the phrase \"{$phrase}\" instead of using its placeholder, which makes it hard to change in M4.");
                    }
                }
            }
        }
    }

    // ---- the notes the protected modules word in English, shown in Spanish ------------------------------------------------------------------

    public function test_a_range_note_is_rebuilt_in_spanish_from_the_real_english_one(): void
    {
        $scope = new SaveWorkoutScope(1, '2026-11-12', 'x', ['Goblet Squat 3 x 8-12 reps', 'Plank 3 x 30-45 seconds'], false);
        $arguments = ['exercises' => [
            ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 8],
            ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_duration_seconds' => 30],
        ]];
        $english = $scope->rangeNote($arguments);
        $this->assertNotNull($english);

        $this->assertSame($english, $this->en()->rangeNote($english), 'English is shown exactly as the scope wrote it.');
        $this->assertNull($this->es()->rangeNote(null));

        $spanish = $this->es()->rangeNote($english);
        $this->assertStringStartsWith('Tu plan guarda un solo número para cada uno de estos, así que guardé el extremo inferior de cada rango: ', $spanish);
        $this->assertStringContainsString('Goblet Squat 8 repeticiones (escrito 8-12)', $spanish);
        $this->assertStringContainsString('Plank 30 segundos (escrito 30-45)', $spanish);
        $this->assertStringEndsWith('Puedes cambiarlos en el plan.', $spanish);
    }

    public function test_a_range_note_for_a_plan_of_several_days_keeps_its_dates(): void
    {
        $english = 'Your plan keeps one number for each of these, so I saved the low end of every range: 2026-11-13 Goblet Squat 3 sets (written 3-4), 8 reps (written 8-12); 2026-11-14 Plank 30 seconds (written 30-45). You can change them in the plan.';

        $this->assertSame('Tu plan guarda un solo número para cada uno de estos, así que guardé el extremo inferior de cada rango: 2026-11-13 Goblet Squat 3 series (escrito 3-4), 8 repeticiones (escrito 8-12); 2026-11-14 Plank 30 segundos (escrito 30-45). Puedes cambiarlos en el plan.', $this->es()->rangeNote($english));
    }

    public function test_a_range_note_that_is_not_understood_is_shown_as_it_was_written_never_guessed_at(): void
    {
        $odd = 'Your plan keeps one number for each of these, so I saved the low end of every range: something new. You can change them in the plan.';

        $this->assertSame($odd, $this->es()->rangeNote($odd));
        $this->assertSame('Something else entirely.', $this->es()->rangeNote('Something else entirely.'));
    }

    public function test_the_unset_load_note_names_the_same_exercises_in_spanish(): void
    {
        $english = 'I left the starting weight for Goblet Squat, Cable Row unset: there is no recorded or planned weight to base one on. Choose a weight you can lift comfortably for the target reps, and record it when you log your sets.';

        $this->assertSame($english, $this->en()->loadsLeftUnset($english));
        $this->assertNull($this->es()->loadsLeftUnset(null));
        $this->assertSame('Dejé sin definir el peso inicial de Goblet Squat, Cable Row: no hay un peso registrado o planificado en el que basarlo. Elige un peso que puedas levantar con comodidad para las repeticiones objetivo y regístralo cuando anotes tus series.', $this->es()->loadsLeftUnset($english));
        $this->assertSame('Not a note I know.', $this->es()->loadsLeftUnset('Not a note I know.'));
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->es()->loadsLeftUnset($english), 'It names no number and suggests no weight, in either language.');
    }

    public static function replacerReasons(): array
    {
        return [
            ['a plan appeared on 2026-11-12 after I asked.', 'apareció un plan el 2026-11-12 después de que te pregunté.'],
            ['the plan I asked about for 2026-11-12 is no longer there.', 'el plan por el que te pregunté para el 2026-11-12 ya no está.'],
            ['the plan for 2026-11-12 changed after I asked.', 'el plan del 2026-11-12 cambió después de que te pregunté.'],
            ['2026-11-12 already has recorded workout performance, so its plan can no longer be replaced.', 'el 2026-11-12 ya tiene un entrenamiento realizado y registrado, así que su plan ya no se puede reemplazar.'],
            ['2026-11-12 was not saved.', 'el 2026-11-12 no se guardó.'],
            ['2026-11-12 was not saved as a rest day.', 'el 2026-11-12 no se guardó como día de descanso.'],
            ['2026-11-12 does not hold what was shown.', 'el 2026-11-12 no contiene lo que se mostró.'],
            ['something went wrong while saving, so none of the days were saved.', 'algo salió mal al guardar, así que no se guardó ningún día.'],
            ['The exercises.0.planned_reps field must be an integer.', 'no se pudo completar el cambio.'],
            ['', 'no se pudo completar el cambio.'],
        ];
    }

    #[DataProvider('replacerReasons')]
    public function test_why_a_confirmed_change_could_not_be_made_is_said_in_spanish_or_generically(string $english, string $spanish): void
    {
        $this->assertSame("I haven't replaced it: {$english} Nothing was changed. If you still want the workout on your plan, ask me again.", $this->en()->confirmationRefused($english));
        $this->assertSame("No lo reemplacé: {$spanish} No se cambió nada. Si aún quieres el entrenamiento en tu plan, pídemelo de nuevo.", $this->es()->confirmationRefused($english));
    }

    public function test_the_load_limits_refusals_keep_every_number_and_the_safety_meaning_in_spanish(): void
    {
        $above = 'planned_weight_kg 50 for "Bench Press" exceeds the maximum permitted change: the heaviest load recorded for it in the last 56 days is 40 kg, and one plan change may raise it by at most 10% (44 kg). That limit caps how large a change may be; it does not mean a load up to it is appropriate. Use 44 kg or less, and tell the user why.';
        $baseline = 'planned_weight_kg 30 for "Goblet Squat" on 2026-11-12 would raise its load above the 24 kg the user last performed or already has planned for that day. Loads only go up when the user approves a calculated suggestion or explicitly asks for the change, so nothing was changed. Use 24 kg or less; if a heavier load looks right, say that a suggestion can be reviewed on the Weekly Plan screen.';

        $english = $this->en()->weekNotSaved($above);
        $this->assertStringStartsWith('I haven\'t changed your plan: planned_weight_kg 50 for "Bench Press" exceeds the maximum permitted change', $english, 'English shows the application\'s original text.');
        $this->assertStringEndsWith('tell the user why. Nothing was saved for any day. Ask me again with "add this plan to my dashboard".', $english);

        $spanish = $this->es()->weekNotSaved($above.' '.$baseline);

        foreach (['50 kg', '"Bench Press"', '56 días', '40 kg', '10%', '44 kg', 'no significa que una carga hasta ese valor sea adecuada', '30 kg', '"Goblet Squat"', '2026-11-12', '24 kg', 'Las cargas solo suben cuando apruebas una sugerencia calculada'] as $kept) {
            $this->assertStringContainsString($kept, $spanish);
        }

        $this->assertStringContainsString('no se cambió nada', $spanish);
        $this->assertStringNotContainsString('planned_weight_kg', $spanish, 'No internal field name reaches the user in Spanish.');
        $this->assertStringNotContainsString('Use 44 kg or less', $spanish, 'The instructions to the model are not shown to the user.');
    }

    public function test_an_unrecognised_load_refusal_is_said_generically_in_spanish_not_half_translated(): void
    {
        $this->assertStringContainsString('no se pudo completar el cambio', $this->es()->weekNotSaved('planned_weight_kg 50 for "Bench Press" is something new.'));
    }

    public function test_a_rest_day_and_nothing_planned_are_worded_in_the_users_language_and_a_plan_summary_is_left_alone(): void
    {
        $this->assertSame('a rest day', $this->en()->existing('a rest day'));
        $this->assertSame('un día de descanso', $this->es()->existing('a rest day'));
        $this->assertSame('nada planificado', $this->es()->existing(PlanReplacementProposal::NOTHING_PLANNED));
        $this->assertSame('“Old plan” (Squats 3×10)', $this->es()->existing('“Old plan” (Squats 3×10)'), 'The user\'s own title and exercises are shown as they are.');
        $this->assertSame('2026-11-13: “Old plan” (Squats 3×10) would become a rest day', $this->en()->describeChange($this->change('clear', '2026-11-13')));
    }

    // ---- nothing a user wrote is a translation key, and a missing Spanish line falls back to English -----------------------------------------

    public function test_text_the_user_or_the_model_wrote_is_only_ever_a_value_never_a_key_or_a_placeholder(): void
    {
        $evil = [
            'title' => ':yes :save_workout coach.save.failed plan.rest_day :english_note',
            'exercise' => ':date :minutes :existing __("coach.save.failed")',
        ];
        $change = ['date' => '2026-11-12', 'existing_plan_id' => 5, 'existing_revision' => 'r', 'existing_summary' => $evil['title'], 'action' => 'replace', 'reason' => '',
            'replacement' => ['planned_date' => '2026-11-12', 'activity_type' => 'strength', 'title' => $evil['title'], 'exercises' => [['exercise_name' => $evil['exercise'], 'planned_sets' => 3, 'planned_reps' => 10]]]];

        foreach ([$this->en(), $this->es()] as $w) {
            $text = $w->replacementQuestion($this->proposal([$change]));

            $this->assertStringContainsString($evil['title'], $text, 'The title is shown exactly as written.');
            $this->assertStringContainsString($evil['exercise'], $text, 'The exercise name is shown exactly as written.');
            $this->assertStringNotContainsString('I haven\'t changed your plan: the workout was not saved', $text);
            $this->assertStringNotContainsString('un día de descanso', $text);
        }

        // ... in a label, a day name or a list of choices too.
        $this->assertStringContainsString('":yes"', $this->es()->weekQuestion('past_days', [':yes'], '', 7));
        $this->assertStringContainsString(':date', $this->es()->exerciseChoice([['item' => 'x', 'options' => [':date', ':yes']]]));
    }

    public function test_every_translation_lookup_names_its_key_in_the_source_never_from_text(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Notices/NoticeText.php'));
        $dynamic = [];
        $offset = 0;

        while (($at = strpos($source, '$this->t(', $offset)) !== false) {
            $start = $at + strlen('$this->t(');
            $depth = 0;
            $end = $start;

            // The first argument: up to the first comma or closing parenthesis outside any brackets.
            for ($i = $start; $i < strlen($source); $i++) {
                $c = $source[$i];
                if (in_array($c, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($c, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($c === ',' && $depth === 0) {
                    break;
                }
            }

            $argument = trim(substr($source, $start, $i - $start));
            $offset = $start;

            if (! preg_match("/^'[a-z_.]+'$/", $argument)) {
                $dynamic[] = $argument;
            }
        }

        // The only computed keys: two closed lists, a three-word regex group, and choices between literals.
        $this->assertEqualsCanonicalizing([
            "'plan.week_detail.'.\$known[\$english]",
            "'plan.reason.'.\$key",
            "'plan.range.'.\$s[2]",
            "\$pastDay ? 'coach.save_question.which_day_past' : 'coach.save_question.which_day'",
            "count(\$lines) === 1 ? 'coach.save_question.choice_one' : 'coach.save_question.choice_many'",
            "\$b['reason'] === 'started' ? 'plan.week.blocked_started' : 'plan.week.blocked_performed'",
            "count(\$already) === 1 ? 'plan.week.already_one' : 'plan.week.already_many'",
            '$header',
        ], $dynamic);

        $this->assertDoesNotMatchRegularExpression('/__\(|trans_choice\(|Lang::get|app\(.translator.\)->get/', $source, 'Only the one wrapper looks messages up.');
    }

    public function test_every_key_the_class_uses_exists_in_both_catalogs(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Notices/NoticeText.php'));
        preg_match_all("/'((?:coach|plan)\\.[a-z_.]+)'/", $source, $m);

        $coach = array_combine(array_map(fn ($k) => 'coach.'.$k, array_keys($this->catalog('en', 'coach'))), $this->catalog('en', 'coach'));
        $plan = array_combine(array_map(fn ($k) => 'plan.'.$k, array_keys($this->catalog('en', 'plan'))), $this->catalog('en', 'plan'));
        $known = $coach + $plan;

        foreach (array_unique($m[1]) as $key) {
            if (str_ends_with($key, '.')) {
                continue; // the start of a computed key, checked below
            }

            $this->assertArrayHasKey($key, $known, "NoticeText uses {$key}, which lang/en does not define (a missing key would show as the key itself).");
        }

        foreach (['week_detail' => ['heading_not_that_day', 'twice', 'next_week_no_dates', 'no_such_day', 'too_far', 'apart', 'cut_off', 'alternatives', 'note_mentions_exercises', 'activity_no_numbers', 'rest_or_else', 'no_exercises_no_rest', 'rest_but_exercises', 'line_without_numbers', 'unknown'],
            'reason' => ['plan_appeared', 'plan_gone', 'plan_changed', 'performed', 'not_saved', 'not_saved_rest', 'not_as_shown', 'went_wrong', 'unknown'],
            'range' => ['part', 'said', 'sets', 'reps', 'seconds']] as $group => $names) {
            foreach ($names as $name) {
                $this->assertArrayHasKey("plan.{$group}.{$name}", $known);
            }
        }
    }

    public function test_a_line_missing_in_spanish_is_shown_in_english(): void
    {
        app('translator')->addLines(['coach.only_in_english_test' => 'Only English has this.'], 'en');

        $w = $this->es();
        $t = new \ReflectionMethod($w, 't');

        $this->assertSame('Only English has this.', $t->invoke($w, 'coach.only_in_english_test'));
    }

    // ---- the language is presentation only ---------------------------------------------------------------------------------------------

    public function test_the_notices_follow_the_users_saved_language_not_the_requests_or_the_applications(): void
    {
        $spanish = User::factory()->create(['locale' => 'es']);
        $english = User::factory()->create(['locale' => 'en']);

        foreach (['en', 'es', 'fr'] as $current) {
            app()->setLocale($current); // whatever the request or a previous request left behind

            $this->assertSame('Listo.', explode(' ', NoticeText::for($spanish)->confirmationApplied($this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned')])))[0], $current);
            $this->assertStringStartsWith('Done.', NoticeText::for($english)->confirmationApplied($this->proposal([$this->change('create', '2026-11-12', 0, 'nothing planned')])), $current);
        }

        // A stored value that is not a supported language reads as English.
        $this->assertSame(AppLocale::English, NoticeText::for(User::factory()->create(['locale' => 'xx']))->locale());
    }

    public function test_no_authorization_or_plan_writing_code_reads_the_notice_words_or_the_catalog(): void
    {
        foreach ([app_path('Services/Ai/Authorization'), app_path('Services/Fitness'), app_path('Services/Tools')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/NoticeText|EnglishCommands|AppLocale|appLocale|\btrans\(|__\(|Lang::/', (string) file_get_contents($file->getPathname()), $file->getPathname().' must not read the language or the catalog.');
            }
        }
    }

    public function test_the_notice_words_make_no_decision_and_no_write(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Notices/NoticeText.php'));

        $this->assertDoesNotMatchRegularExpression('/\b(?:DB::|Cache::|Log::|Http::|Auth::)|->(?:save|update|delete|create|call|apply|grant|permits|authorize|classify)\(|ToolTurn|ToolAuthorization|PlanReplacer|PlanLoadGuard|SaveWorkoutScope|WeekSaveScope|ReplacementGrant/', $source);
    }

    public function test_a_day_label_is_the_same_day_in_both_languages(): void
    {
        $saved = [['date' => '2026-11-15', 'action' => 'clear']];

        $this->assertStringContainsString('Sunday, Nov 15', $this->en()->weekSaved($saved, [], []));
        $this->assertStringContainsString('domingo, 15 de noviembre', $this->es()->weekSaved($saved, [], []));
        $this->assertSame('2026-11-15', CarbonImmutable::parse('2026-11-15')->toDateString(), 'The stored date is never the label.');
    }
}
