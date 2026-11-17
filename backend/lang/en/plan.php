<?php

/*
 * How the application describes plan changes to the user (Phase 7 / M3b): held replacements, saved plans of several
 * days, and the reasons a change could not be made. (The two load reasons are the user-facing form of the load
 * limits' refusals: English shows the application's original text for them, other languages are built from these.) Presentation text only: the signed proposals and everything stored
 * keep their own fields untouched; these strings are built from them after they were verified.
 */

return [
    'rest_day' => 'a rest day',
    'rest_day_short' => 'rest day',
    'nothing_planned' => 'nothing planned',

    // A held replacement: the question the user answers.
    'replacement' => [
        'single_clear' => 'I haven\'t changed your plan. :date already has :existing. Do you want me to turn it into a rest day? Reply ":yes" within :minutes minutes to do exactly that; anything else leaves it as it is.',
        'single_replace' => 'I haven\'t changed your plan. :date already has :existing. Do you want me to replace it with :replacement? Reply ":yes" within :minutes minutes to replace it with exactly that; anything else leaves it as it is.',
        'several_with_new_days' => 'I haven\'t changed your plan yet. Saving this plan would make these changes, and some of these days already have a plan that would be replaced:',
        'several_existing' => 'I haven\'t changed your plan yet. These days already have a plan:',
        'confirm_all' => 'Do you want me to make all of these changes? Reply ":yes" within :minutes minutes to make exactly these changes and nothing else; anything else leaves your plan as it is.',
    ],

    // One line of a held change.
    'change' => [
        'create' => ':date: :replacement would be added (nothing is planned yet)',
        'clear_new' => ':date: would be marked as a rest day (nothing is planned yet)',
        'clear_existing' => ':date: :existing would become a rest day',
        'replace' => ':date: :existing would be replaced with :replacement',
    ],

    // A proposed change to one exercise's weight.
    'proposal' => [
        'set_target' => 'set :exercise to :kg kg:amount on :date',
    ],

    // A plan of several days saved, or not.
    'week' => [
        'nothing_saved' => 'Nothing was saved for any day.',
        'saved_header' => 'Done. I added this to your plan, all together, and checked that it is saved:',
        'day_line' => '- :day: :what',
        'already_one' => ':dates was already on your plan exactly like this, so I left it as it is.',
        'already_many' => ':dates were already on your plan exactly like this, so I left them as they are.',
        'omitted' => 'I left out :omitted because you named only the days above; say ":save_plan" again if you want the rest.',
        'blocked' => 'I haven\'t changed your plan, and I didn\'t save any day of it: :list can\'t be replaced, and I save a plan whole or not at all so your week is never half done. To add the other days, reply with just those days, for example ":sunday" (or ":save_plan_sunday").',
        'blocked_item' => ':date (:label: :why)',
        'blocked_started' => 'already started',
        'blocked_performed' => 'already has recorded workout performance',
        'already_everything' => 'Everything in that plan (:dates) is already on your plan exactly as shown, so there was nothing to change.',
        'not_saved_because' => 'I haven\'t changed your plan: :problem. Nothing was saved for any day. Ask me again with ":save_plan".',
        'not_saved' => 'I haven\'t changed your plan: the plan was not saved, and nothing was saved for any day. Ask me again with ":save_plan".',
        'detail_days' => ' (:days: :detail)',
        'detail_only' => ' (:detail)',
    ],

    // Why a plan of several days could not be read (said after "I can't tell exactly what to save for ...").
    'week_detail' => [
        'heading_not_that_day' => 'the date in that heading is not that day of the week',
        'twice' => 'that day appears twice',
        'next_week_no_dates' => 'the plan is for next week but its days have no dates',
        'no_such_day' => 'the plan has no such day',
        'too_far' => 'that is more than two months ahead',
        'apart' => 'its days are more than a week apart',
        'cut_off' => 'it looks cut off or unfinished',
        'alternatives' => 'it offers alternatives (options) and I can only save one workout per day',
        'note_mentions_exercises' => 'the text under its note mentions exercises that I would leave out',
        'activity_no_numbers' => 'it mentions an activity with no sets, reps or time',
        'rest_or_else' => 'it says rest or something else',
        'no_exercises_no_rest' => 'it lists no exercises and does not say it is a rest day',
        'rest_but_exercises' => 'it is called a rest day but also lists exercises',
        'unknown' => 'I could not read it with confidence',
        'line_without_numbers' => 'the line ":line" gives no sets, reps or time, so I would have to leave it out',
    ],

    // Why a confirmed change could not be made (each is a closed reason; the reason's text is presentation only).
    'reason' => [
        'plan_appeared' => 'a plan appeared on :date after I asked.',
        'plan_gone' => 'the plan I asked about for :date is no longer there.',
        'plan_changed' => 'the plan for :date changed after I asked.',
        'performed' => ':date already has recorded workout performance, so its plan can no longer be replaced.',
        'not_saved' => ':date was not saved.',
        'not_saved_rest' => ':date was not saved as a rest day.',
        'not_as_shown' => ':date does not hold what was shown.',
        'went_wrong' => 'something went wrong while saving, so none of the days were saved.',
        'above_limit' => 'The planned weight of :load kg for ":exercise" exceeds the maximum permitted change: the heaviest load recorded for it in the last :days days is :recorded kg, and one plan change may raise it by at most :percent% (:maximum kg). That limit only caps how large a change may be; it does not mean a load up to it is appropriate.',
        'above_baseline' => 'The planned weight of :load kg for ":exercise" on :date would raise its load above the :baseline kg you last performed or already have planned for that day. Loads only go up when you approve a calculated suggestion or explicitly ask for the change, so nothing was changed.',
        'unknown' => 'the change could not be completed.',
    ],

    // Ranges kept as one number.
    'range' => [
        'part' => ':exercise :said',
        'said' => ':amount :unit (written :low-:high)',
        'sets' => 'sets',
        'reps' => 'reps',
        'seconds' => 'seconds',
    ],
];
