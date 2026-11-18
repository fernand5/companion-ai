<?php

/*
 * The words of the API's own error and confirmation messages, keyed by a stable code or a known exception class.
 *
 * Only the TEXT of a response is translated. HTTP statuses, headers, JSON keys and machine-readable codes never come
 * from here. A message is chosen by a code or an exception class, never by what the user typed and never by the
 * wording of another message. Nothing in the application reads one of these back to decide anything.
 *
 * English is the source: its wording is exactly what the API has always returned.
 */
return [
    // Framework-rendered errors, chosen by exception class / HTTP status in App\Support\ApiErrorLocalizer.
    'http' => [
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'Forbidden',
        'not_found' => 'Not Found',
        'method_not_allowed' => 'Method Not Allowed',
        'too_many_attempts' => 'Too Many Attempts.',
        'server_error' => 'Server Error',
    ],

    'auth' => [
        'logged_out' => 'Logged out.',
    ],

    'coach' => [
        'busy' => 'Your coach is still working on your previous message — please wait for the reply first.',
    ],

    // AiProviderException: one entry per stable reason code (not_configured, rate_limited, unavailable).
    'provider' => [
        'not_configured' => 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.',
        'rate_limited' => 'Your coach is at its request limit for now — please wait a moment and try again.',
        'unavailable' => 'The AI coach is temporarily unavailable — please try again in a moment.',
    ],

    'exercise_demo' => [
        'unavailable' => 'No demonstration is available for this exercise.',
        'failed' => 'The exercise demonstration is unavailable right now.',
    ],

    'progression' => [
        'unavailable' => 'Progression suggestions are temporarily unavailable. Please try again later.',
    ],

    'weekly_plan' => [
        'already_covered' => 'This week already reflects your recurring schedule.',
        // Recognised failures of a weekly-plan request, and the safe line used for any other.
        'incomplete_decision' => 'The weekly plan could not be updated because the decision was incomplete. Please try again.',
        'nothing_changed' => 'Nothing was changed in your plan.',
        'failed' => 'The weekly plan could not be updated. Please review the changes and try again.',
    ],

    // Messages the request classes and the fitness services raise as validation errors.
    'validation' => [
        'only_proposal' => 'Only the proposal can be submitted.',
        'set_needs_value' => 'Each set needs reps, weight or duration.',
        'plan_has_performance' => 'This day already has recorded workout performance, so its plan can no longer be replaced.',
        'plan_started' => 'This day\'s workout has already been started or finished, so its plan can no longer be replaced.',
        'exercise_not_pending' => 'This exercise is no longer pending, so its target can no longer be changed.',
        'record_one_set' => 'Record at least one completed set, or skip the exercise instead.',
        'date_in_future' => 'logged_date cannot be in the future: this records something that already happened. Upcoming sessions belong in the schedule or the plan.',
        'date_outside_range' => 'Date :date is outside the days that can be planned (:start to :end).',
        'date_outside_week' => 'Date :date is outside the current week (:start to :end).',
    ],
];
