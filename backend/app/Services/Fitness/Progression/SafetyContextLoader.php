<?php

namespace App\Services\Fitness\Progression;

use App\Models\User;

/**
 * Reads the few facts about recovery and limits the engine acts on: at most three
 * small queries per user, however many exercises are evaluated. Read-only.
 *
 * What it can and cannot know:
 *  - Pain: a recovery check-in in the last PAIN_WINDOW_DAYS days whose pain note
 *    says something other than "none". It cannot tell which exercise hurts, so a
 *    pain note stops automatic increases for every exercise.
 *  - Restrictions: a saved memory in the "constraint" category, or whose text
 *    names an injury, pain, surgery or limitation. That is a keyword reading of
 *    free text and will miss things; it errs towards MANUAL_REVIEW, and a missed
 *    restriction is a known limit (see docs/progression-policy.md).
 *  - Recovery: the latest check-in's soreness and age. No check-in is "unknown".
 */
class SafetyContextLoader
{
    /** Pain notes that mean no pain. */
    private const NO_PAIN = ['', 'none', 'no', 'nope', 'n/a', 'na', 'nil', 'nothing', 'no pain', 'no pains', 'none.', 'no.', '-', '–', 'all good', 'fine'];

    private const RESTRICTION_PATTERN = '/\b(?:injur\w*|pain\w*|hurts?|surgery|surgical|rehab\w*|physio\w*|tendin\w*|herniat\w*|sprain\w*|strain\w*|fractur\w*|arthritis|avoid\w*|limitation\w*|restrict\w*|can(?:\'|’)?t|cannot|doctor|medical)\b/iu';

    public function for(User $user): SafetyContext
    {
        $today = $user->localNow()->startOfDay();
        $since = $today->copy()->subDays(ProgressionPolicy::PAIN_WINDOW_DAYS)->toDateString();

        $checkins = $user->recoveryCheckins()
            ->whereDate('checkin_date', '>=', $since)
            ->orderByDesc('checkin_date')
            ->get(['checkin_date', 'soreness', 'pain_notes']);

        $latest = $checkins->first();
        $pain = $checkins->contains(fn ($checkin) => ! in_array(mb_strtolower(trim((string) $checkin->pain_notes)), self::NO_PAIN, true));

        $memories = $user->fitnessMemories()->latest()->limit(50)->get(['content', 'category']);
        $restriction = $memories->contains(
            fn ($memory) => mb_strtolower((string) $memory->category) === 'constraint'
                || preg_match(self::RESTRICTION_PATTERN, (string) $memory->content) === 1,
        );

        return new SafetyContext(
            painReportedRecently: $pain,
            latestSoreness: $latest?->soreness,
            daysSinceLatestCheckin: $latest ? (int) abs($today->diffInDays($latest->checkin_date->copy()->startOfDay(), false)) : null,
            restrictionOnRecord: $restriction,
            fitnessLevel: $user->fitnessProfile()->value('fitness_level'),
        );
    }
}
