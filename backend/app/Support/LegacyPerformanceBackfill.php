<?php

namespace App\Support;

use App\Services\Fitness\PerformanceSets;
use Illuminate\Support\Facades\DB;

/**
 * One-time conversion of pre-existing workout data into the set-level model.
 *
 * The rules are deliberately conservative — nothing is invented and nothing is
 * promoted to "measured" without evidence:
 *
 *  - A row is `migrated` (genuine performance) only with positive evidence it was
 *    logged by the user: it belongs to a session no plan points at AND (it has no
 *    session notes — plan syncing always wrote the plan title — or no plan exists
 *    for that date), or its plan exercise carries real `actual_*` values.
 *  - Everything that came from plan syncing is `as_planned`: until now it was
 *    copied from the plan's targets, so it is weak evidence at best. Exercises
 *    their plan marked skipped/pending were never performed and get no sets.
 *  - "N sets of R at W" becomes N identical set rows — that is exactly what was
 *    claimed. If the set count is unknown, no sets are created (never guessed).
 *  - The summary columns on workout_exercises are left untouched.
 *
 * Idempotent: only rows with recorded_as IS NULL are processed.
 */
final class LegacyPerformanceBackfill
{
    /**
     * @return array<string, int>
     */
    public static function run(): array
    {
        $stats = [
            'exercises_processed' => 0,
            'migrated' => 0,
            'as_planned' => 0,
            'not_performed_no_sets' => 0,
            'sets_created' => 0,
            'no_recoverable_sets' => 0,
            'distance_backfilled' => 0,
        ];

        DB::table('workout_exercises')->whereNull('recorded_as')->orderBy('id')->chunkById(100, function ($rows) use (&$stats) {
            foreach ($rows as $row) {
                self::migrateExercise($row, $stats);
            }
        });

        self::backfillDistance($stats);

        return $stats;
    }

    /** @param  array<string, int>  $stats */
    private static function migrateExercise(object $row, array &$stats): void
    {
        $stats['exercises_processed']++;

        $session = DB::table('workout_sessions')->where('id', $row->workout_session_id)->first();
        $slug = ExerciseSlug::from($row->exercise_name);
        $plan = DB::table('workout_plans')->where('workout_session_id', $row->workout_session_id)->first();

        $planExercise = null;
        $recordedAs = 'as_planned';
        $performed = true;

        if ($plan) {
            $candidate = DB::table('workout_plan_exercises')
                ->where('workout_plan_id', $plan->id)->where('position', $row->position)->first();
            $planExercise = $candidate && ExerciseSlug::from($candidate->exercise_name) === $slug ? $candidate : null;

            if ($planExercise && in_array($planExercise->status, ['pending', 'skipped'], true)) {
                $performed = false;
            } elseif ($planExercise && self::hasActuals($planExercise)) {
                $recordedAs = 'migrated';
            }
        } elseif ($session) {
            $planOnDate = DB::table('workout_plans')
                ->where('user_id', $session->user_id)
                ->whereDate('planned_date', substr((string) $session->logged_date, 0, 10))
                ->exists();

            if ($session->notes === null || ! $planOnDate) {
                $recordedAs = 'migrated';
            }
        }

        $update = ['exercise_slug' => $slug, 'recorded_as' => $recordedAs];

        if ($planExercise) {
            $update += [
                'workout_plan_exercise_id' => $planExercise->id,
                'planned_sets' => $planExercise->planned_sets,
                'planned_reps' => $planExercise->planned_reps,
                'planned_weight_kg' => $planExercise->planned_weight_kg,
                'planned_duration_seconds' => $planExercise->planned_duration_seconds,
            ];
        }

        DB::table('workout_exercises')->where('id', $row->id)->update($update);

        if (! $performed) {
            $stats['not_performed_no_sets']++;

            return;
        }

        $stats[$recordedAs === 'migrated' ? 'migrated' : 'as_planned']++;

        if (DB::table('workout_sets')->where('workout_exercise_id', $row->id)->exists()) {
            return;
        }

        $sets = PerformanceSets::expand(
            $row->sets !== null ? (int) $row->sets : null,
            $row->reps !== null ? (int) $row->reps : null,
            $row->weight_kg !== null ? (float) $row->weight_kg : null,
            $row->duration_seconds !== null ? (int) $row->duration_seconds : null,
        );

        if ($sets === []) {
            $stats['no_recoverable_sets']++;

            return;
        }

        foreach ($sets as $i => $set) {
            DB::table('workout_sets')->insert([
                'workout_exercise_id' => $row->id,
                'set_number' => $i + 1,
                'reps' => $set['reps'],
                'weight_kg' => $set['weight_kg'],
                'duration_seconds' => $set['duration_seconds'],
                'completed' => true,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);
            $stats['sets_created']++;
        }
    }

    private static function hasActuals(object $planExercise): bool
    {
        return $planExercise->actual_sets !== null
            || $planExercise->actual_reps !== null
            || $planExercise->actual_weight_kg !== null
            || $planExercise->actual_duration_seconds !== null;
    }

    /** @param  array<string, int>  $stats */
    private static function backfillDistance(array &$stats): void
    {
        DB::table('activity_logs')
            ->whereIn('type', ['treadmill', 'sport'])
            ->whereNull('distance_km')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$stats) {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->metadata, true);
                    $distance = is_array($meta) ? ($meta['distance_km'] ?? null) : null;

                    if (is_numeric($distance) && (float) $distance > 0 && (float) $distance <= 1000) {
                        DB::table('activity_logs')->where('id', $row->id)->update(['distance_km' => round((float) $distance, 2)]);
                        $stats['distance_backfilled']++;
                    }
                }
            });
    }
}
