<?php

/*
 * What the application itself says under or instead of a coach reply (Phase 7 / M3b).
 *
 * English is the source wording and the fallback. Placeholders (":name") are filled by NoticeText from structured
 * values; the phrases a user is told to TYPE (":save_workout", ":yes", ...) come from EnglishCommands, because the
 * request parsers only read English until M4. ":english_note" is empty here and a short note in Spanish.
 *
 * Nothing here is read back to decide anything: it is presentation text only.
 */

return [
    'english_note' => '',

    'earlier_day' => 'an earlier day',

    // Questions the application asks before saving a workout.
    'save_question' => [
        'no_workout' => 'I haven\'t changed your plan: I don\'t see a workout in my last reply or in your message to add. Ask me for one (for example ":ask_workout"), or tell me what to add, like ":example". You can also just reply with the exercises and their sets and reps.',
        'which_workout' => 'I haven\'t changed your plan: more than one workout could be meant and I don\'t want to guess which. Say which one (for example ":option_command", or just ":option"), or send the exercises in one message, like ":example".',
        'incomplete' => 'I haven\'t changed your plan: that workout looks unfinished (it is cut off, trails off, or lists fewer exercises than it announces). Send me the full list, like ":example", or ask me to write it out in full first.',
        'quoted' => 'I haven\'t changed your plan: that workout is quoted from someone else\'s words, and I want to be sure it is the one you want. Write the exercises in your own message, like ":example".',
        'retry_new_day' => 'I haven\'t changed your plan: that request to add a workout was made on :made_on, and a new day has started, so I won\'t carry it over and guess which day you mean now. To add the workout, say ":save_workout" (and the day, if you want one other than today).',
        'retry_too_old' => 'I haven\'t changed your plan: that request to add a workout is more than :minutes minutes old, and I only pick up a request while it is fresh, so nothing was saved. To add the workout, say ":save_workout" (and the day, if you want one other than today).',
        'which_day_past' => 'I haven\'t changed your plan: I\'m not sure which day you mean, because I can only add a workout to today or a later day (up to two months ahead). Which day should I use? Reply with just the day, for example ":friday" or ":tomorrow".',
        'which_day' => 'I haven\'t changed your plan: I\'m not sure which day you mean. I can add a workout to today or to a day in the next two months. Which day should I use? Reply with just the day, for example ":friday" or ":tomorrow".',
        'choice_one' => 'I haven\'t changed your plan, and nothing is wrong with saving it: one thing in the workout is a choice. It lists :lines. Which should I add? Reply with just the name, for example ":first".',
        'choice_many' => 'I haven\'t changed your plan, and nothing is wrong with saving it: one thing in the workout is a choice. It lists :lines. Which should I add? Reply with just the names, for example ":first".',
        'choice_or' => 'or',
        'choice_first' => 'the first one',
    ],

    // Why a plan of several days cannot be saved yet.
    'week_question' => [
        'unclear_day' => 'I haven\'t changed your plan: I can\'t tell exactly what to save for :days (:detail), and I don\'t want to guess or invent a workout. :nothing_saved Tell me what that day holds (for example ":sunday_rest", or list its exercises with sets and reps), or ask me to write the plan out in full, then say ":save_plan" again.',
        'past_days' => 'I haven\'t changed your plan: this plan includes days that have already passed (:days), and I can only add today or a later day. :nothing_saved Reply with just the days to add, for example ":saturday_sunday".',
        'no_such_day' => 'I haven\'t changed your plan: that plan has no :days. :nothing_saved Reply with just the days to add from the plan, for example ":saturday_sunday".',
        'dates' => 'I haven\'t changed your plan: I can\'t tell which dates this plan is for:detail. :nothing_saved Ask me to write the plan with a date on each day (for example ":dated_day"), then say ":save_plan" again.',
        'too_many' => 'I haven\'t changed your plan: that plan has more than :max days, which is more than I can add at once. :nothing_saved Ask me to add it a week at a time.',
        'unreadable' => 'I haven\'t changed your plan: I couldn\'t read that plan day by day. :nothing_saved Ask me to write it out with each day\'s exercises, then say ":save_plan".',
    ],

    // What happened to a requested save.
    'save' => [
        'failed' => 'I haven\'t changed your plan: the workout was not saved. Ask me again with ":save_workout", or send the exercises in one message.',
        'day_performed' => 'I haven\'t changed your plan: :date already has recorded workout performance, so its plan can\'t be replaced. Pick another day, like ":save_workout_tomorrow".',
        'replaced_as_asked' => 'As you asked, I replaced :was on :date with this workout.',
        'cannot_ask_confirmation' => 'I haven\'t changed your plan: that would replace a day that already has a plan, and I couldn\'t ask you to confirm it just now. Please try again.',
    ],

    // The answers to a "yes" to a held replacement.
    'confirmation' => [
        'unclear' => 'I haven\'t changed your plan: I wasn\'t sure that was a yes. To confirm exactly what I showed you, reply ":yes"; to leave your plan as it is, reply "no". The question is still open.',
        'invalid' => 'I haven\'t changed your plan: that confirmation is no longer valid (it expired, was already used, or is not for this conversation). If you still want the workout on your plan, ask me again.',
        'refused' => 'I haven\'t replaced it: :reason Nothing was changed. If you still want the workout on your plan, ask me again.',
        'done' => 'Done.',
        'checked' => 'I checked that it is saved.',
        'added' => 'I added :replacement to :date.',
        'marked_rest' => 'I marked :date as a rest day.',
        'turned_rest' => 'I turned :date (:existing) into a rest day.',
        'replaced' => 'I replaced :existing on :date with :replacement.',
    ],

    // What the application says after a change was proposed, or none can be made.
    'follow_up' => [
        'explicit' => 'To change your plan, send an explicit request that names the exercise, the load in kg and the day, for example ":update_example".',
        'proposal_no_longer_valid' => 'Your plan changed since I asked, so I didn\'t make that change. :explicit',
        'nothing_pending' => 'There is nothing waiting for your confirmation (it may have expired).',
        'nothing_pending_discussed' => 'To put the workout we just discussed on your plan, say ":save_workout".',
        'explicit_request_needed' => 'I haven\'t changed your plan. :explicit',
        'confirmed_change_failed' => 'I couldn\'t make the change exactly as you confirmed it, so nothing was changed. If you still want it, ask again.',
        'change_proposed' => 'I haven\'t changed your plan. Do you want me to :describe? Reply ":yes" within :minutes minutes to confirm exactly that, and nothing else. The limit on how much one change may add only caps its size; it does not mean the load suits you.',
        'above_maximum_change' => 'I haven\'t changed your plan, and I can\'t offer :requested kg: one change may add at most 10% to your heaviest recorded load (:recorded kg), which is :maximum kg. That is a maximum, not a recommendation, and it is not a judgment that any load up to it suits you.',
        'change_not_specific' => 'I haven\'t changed your plan, and I couldn\'t turn that into one specific change to confirm. :explicit',
    ],

    // Corrections of what the model claimed (grounded in what the turn's tools actually did).
    'correction' => [
        'no_access_intro' => 'To be clear, that is not a limit of the app: I can add and change workouts on your plan whenever you ask.',
        'nothing_changed' => 'I haven\'t changed anything this time.',
        'no_access_how' => 'To add a workout I have just shown you, say ":save_workout" (or ":try_again" if you have already asked).',
        'not_saved' => 'I haven\'t changed your plan: nothing was saved this time, whatever the message above says. To add a workout I have just shown you, say ":save_workout".',
        'partial' => 'To be careful: not everything was changed. At least one plan change was refused, skipped or could not be completed, so check your plan before relying on what the message above says was done.',
    ],

    // Notes about the load, and about ranges stored as one number.
    'load_left_unset' => 'I left the starting weight for :names unset: there is no recorded or planned weight to base one on. Choose a weight you can lift comfortably for the target reps, and record it when you log your sets.',
    'range_note' => 'Your plan keeps one number for each of these, so I saved the low end of every range: :parts. You can change them in the plan.',

    // The weekly-plan page and the dashboard.
    'weekly' => [
        'no_change' => 'No change needed.',
        'not_determined' => 'Could not determine a plan change from that — try rephrasing.',
    ],
    'dashboard_fallback' => 'Log today\'s activity and I\'ll tailor a recommendation.',
];
