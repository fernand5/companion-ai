<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Authorization\PlanReplacementProposal;

/**
 * What a save or replacement actually did, as structured data for the client to show: the day, the workout's name as it is
 * now stored, and whether it was added, replaced, or the day was made a rest day.
 *
 * It is built only AFTER the application has persisted and read back the change, and from the database, never from the
 * model's words: a day that should hold a workout and does not makes the whole result null (the client then shows no
 * success at all). The text of the reply is unaffected; this is the machine-readable twin of what the application said.
 */
final class SaveResult
{
    public const META_KEY = 'save_result';

    public const ADDED = 'added';

    public const REPLACED = 'replaced';

    public const REST = 'rest';

    /**
     * @param  array<int, array{date: string, action: string}>  $changes  each day's action: PlanReplacementProposal::CREATE, REPLACE or CLEAR
     * @return array{save_result: array{version: int, days: array<int, array{date: string, outcome: string, title: ?string}>}}|array{}
     */
    public static function meta(User $user, array $changes): array
    {
        $days = [];

        foreach ($changes as $change) {
            $date = substr((string) ($change['date'] ?? ''), 0, 10);

            if ($date === '') {
                return [];
            }

            if (($change['action'] ?? null) === PlanReplacementProposal::CLEAR) {
                $days[] = ['date' => $date, 'outcome' => self::REST, 'title' => null];

                continue;
            }

            $plan = $user->workoutPlans()->whereDate('planned_date', $date)->first();

            if ($plan === null) {
                return [];
            }

            $days[] = [
                'date' => $date,
                'outcome' => ($change['action'] ?? null) === PlanReplacementProposal::REPLACE ? self::REPLACED : self::ADDED,
                'title' => PlanReplacementProposal::clean((string) $plan->title),
            ];
        }

        usort($days, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $days === [] ? [] : [self::META_KEY => ['version' => 1, 'days' => $days]];
    }
}
