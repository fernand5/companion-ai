<?php

namespace App\Services\Fitness\Progression;

use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Support\Facades\DB;

/**
 * Prepares the structured, read-only view of what could change in the user's UPCOMING workouts.
 *
 * It decides nothing. Every decision, number, reason and proposal comes from the progression
 * engine through ProgressionApprovalService (the same items the approval flow issues and
 * re-checks), so the Weekly Plan screen, the coach's tool and plan generation cannot disagree.
 * What this adds is plan context: which session each suggestion belongs to, which later
 * sessions with the same exercise are NOT being changed, and one plain state per exercise.
 *
 *  - One item per exact exercise, and at most one suggestion per plan row.
 *  - A suggestion is only ever for the exercise's NEXT upcoming session. Later sessions with the
 *    same exercise are listed as untouched; they are reviewed after that one, never changed with it.
 *  - Each suggestion is approved on its own through the existing approval endpoint. There is no
 *    way to approve several at once, and approving one never approves another.
 *  - Nothing is written, no model is called, and with nothing eligible the plan is simply left alone.
 */
class AdaptivePlanningService
{
    /** How far ahead later sessions are looked at. */
    public const HORIZON_DAYS = 14;

    public function __construct(private readonly ProgressionApprovalService $approvals) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, sessions: array<int, array<string, mixed>>, summary: array<string, int>, meta: array<string, mixed>}
     */
    public function prepare(User $user): array
    {
        $overview = $this->approvals->overview($user);
        $items = $overview['data'];

        $slugs = array_values(array_unique(array_filter(array_map(fn (array $item) => $item['exercise']['slug'], $items))));
        $upcoming = $this->upcomingRows($user, $slugs);

        $seenRows = [];
        $sessions = [];
        $summary = array_fill_keys(['suggested', 'kept', 'needs_review', 'insufficient_data', 'no_change', 'unavailable'], 0);

        foreach ($items as $i => $item) {
            $slug = $item['exercise']['slug'];
            $date = $item['applies_to']['date'] ?? null;
            $mine = $slug !== null ? ($upcoming[$slug] ?? []) : [];
            $next = $date !== null ? collect($mine)->first(fn (array $r) => $r['date'] === $date) : null;

            // Defensive: never two suggestions for one plan row.
            if ($item['actionable'] && $next !== null) {
                if (isset($seenRows[$next['row_id']])) {
                    $item = $this->withdraw($item, 'duplicate_suggestion');
                }
                $seenRows[$next['row_id']] = true;
            }

            $later = $date === null ? [] : collect($mine)
                ->filter(fn (array $r) => $r['date'] > $date && $r['open'])
                ->map(fn (array $r) => ['date' => $r['date'], 'title' => $r['title']])
                ->values()->all();

            $state = $this->stateOf($item);
            $summary[$state]++;

            $item['applies_to'] = $date !== null ? ['date' => $date, 'title' => $next['title'] ?? null, 'plan_status' => $next['plan_status'] ?? null] : null;
            $item['later_sessions'] = $later;
            $item['state'] = $state;
            $items[$i] = $item;

            if ($date !== null) {
                $sessions[$date] ??= ['date' => $date, 'title' => $next['title'] ?? null, 'plan_status' => $next['plan_status'] ?? null, 'exercises' => 0, 'suggested' => 0];
                $sessions[$date]['exercises']++;
                $sessions[$date]['suggested'] += $state === 'suggested' ? 1 : 0;
            }
        }

        ksort($sessions);

        return [
            'data' => $items,
            'sessions' => array_values($sessions),
            'summary' => $summary,
            'meta' => $overview['meta'] + ['horizon_days' => self::HORIZON_DAYS],
        ];
    }

    /** One plain state per exercise, so every screen says the same thing. */
    private function stateOf(array $item): string
    {
        return match (true) {
            $item['actionable'] => 'suggested',
            $item['dismissed'] !== null => 'kept',
            $item['decision'] === 'MANUAL_REVIEW' => 'needs_review',
            $item['decision'] === 'INSUFFICIENT_DATA' => 'insufficient_data',
            $item['decision'] === 'MAINTAIN' => 'no_change',
            default => 'unavailable',
        };
    }

    /** @return array<string, mixed> the item with its proposal withdrawn */
    private function withdraw(array $item, string $reason): array
    {
        return ['actionable' => false, 'not_actionable_reason' => $reason, 'proposal' => null] + $item;
    }

    /**
     * The user's upcoming sessions (inside the horizon) that contain these exercises, one query.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, array<int, array{row_id: int, date: string, title: string, plan_status: string, open: bool}>>
     */
    private function upcomingRows(User $user, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $today = $user->localToday();
        $until = $user->localNow()->addDays(self::HORIZON_DAYS)->toDateString();

        $rows = DB::table('workout_plan_exercises')
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $user->id)
            ->whereIn('workout_plan_exercises.exercise_slug', $slugs)
            ->whereDate('workout_plans.planned_date', '>=', $today)
            ->whereDate('workout_plans.planned_date', '<=', $until)
            ->orderBy('workout_plans.planned_date')
            ->orderBy('workout_plan_exercises.id')
            ->select('workout_plan_exercises.id as row_id', 'workout_plan_exercises.exercise_slug as slug', 'workout_plan_exercises.status as exercise_status', 'workout_plans.planned_date as day', 'workout_plans.title', 'workout_plans.status as plan_status')
            ->get();

        $byExercise = [];

        foreach ($rows as $row) {
            $byExercise[$row->slug][] = [
                'row_id' => (int) $row->row_id,
                'date' => substr((string) $row->day, 0, 10),
                'title' => (string) $row->title,
                'plan_status' => (string) $row->plan_status,
                // Still to be done: not started or finished, so it is a session a later review could cover.
                'open' => $row->exercise_status === WorkoutPlanExercise::STATUS_PENDING
                    && in_array($row->plan_status, [WorkoutPlan::STATUS_PLANNED, WorkoutPlan::STATUS_IN_PROGRESS], true),
            ];
        }

        return $byExercise;
    }
}
