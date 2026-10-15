<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Support\ExerciseSlug;
use Illuminate\Support\Facades\DB;

/**
 * Even when the user has asked for a plan change, the model does not get to pick
 * any load it likes. A plan the model writes may not carry a load more than the
 * policy's maximum permitted change above the heaviest load the user has recorded
 * for that exact exercise.
 *
 * This is a MAXIMUM, not a recommendation and not a judgment of safety: staying
 * under it does not show a load suits the person. Injury, technique, fatigue and
 * equipment are not in the record, and the progression engine applies its own,
 * much stricter evidence requirements before it proposes anything. The guard only
 * stops a single plan write from being a jump the policy never permits.
 *
 * Only exercises with recorded history are checked (an exercise with none has no
 * demonstrated load to compare against, and the plan tools' own validation still
 * applies). Loads that are not above the ceiling, lighter loads, and exercises
 * without a planned load are untouched. One query, whatever the plan contains.
 */
class PlanLoadGuard
{
    /**
     * @param  array<string, mixed>  $arguments  the tool call's arguments, untrusted
     * @return array<int, string> one message per exercise over its ceiling; empty when the call is fine
     */
    public function violations(User $user, string $toolName, array $arguments): array
    {
        $planned = $this->plannedLoads($toolName, $arguments);

        if ($planned === []) {
            return [];
        }

        $demonstrated = $this->demonstratedLoads($user, array_keys($planned));
        $messages = [];

        foreach ($planned as $slug => $entries) {
            if (! isset($demonstrated[$slug])) {
                continue;
            }

            $ceiling = ProgressionPolicy::ceilingKg($demonstrated[$slug]);

            foreach ($entries as [$name, $load]) {
                if ($load > $ceiling) {
                    $messages[] = sprintf(
                        'planned_weight_kg %s for "%s" exceeds the maximum permitted change: the heaviest load recorded for it in the last %d days is %s kg, and one plan change may raise it by at most %d%% (%s kg). That limit caps how large a change may be; it does not mean a load up to it is appropriate. Use %s kg or less, and tell the user why.',
                        $this->kg($load), $name, ProgressionPolicy::DEMONSTRATED_LOAD_DAYS, $this->kg($demonstrated[$slug]),
                        (int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100), $this->kg($ceiling), $this->kg($ceiling),
                    );
                }
            }
        }

        return $messages;
    }

    /**
     * The heaviest recorded load for one exact exercise and the maximum a plan may carry for it,
     * or null when nothing is recorded (there is then nothing to compare with).
     *
     * @return array{recorded_kg: float, maximum_kg: float}|null
     */
    public function limitFor(User $user, string $slug): ?array
    {
        $recorded = $this->demonstratedLoads($user, [$slug])[$slug] ?? null;

        return $recorded === null ? null : ['recorded_kg' => $recorded, 'maximum_kg' => ProgressionPolicy::ceilingKg($recorded)];
    }

    /** @return array<string, array<int, array{0: string, 1: float}>> slug => [[name, planned load], ...] */
    private function plannedLoads(string $toolName, array $arguments): array
    {
        $exerciseLists = match ($toolName) {
            'create_workout_plan' => [$arguments['exercises'] ?? []],
            'propose_weekly_plan_changes' => array_map(
                fn ($change) => is_array($change) ? ($change['exercises'] ?? []) : [],
                is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [],
            ),
            default => [],
        };

        $planned = [];

        foreach ($exerciseLists as $exercises) {
            foreach (is_array($exercises) ? $exercises : [] as $exercise) {
                if (! is_array($exercise) || ! isset($exercise['planned_weight_kg']) || ! is_numeric($exercise['planned_weight_kg'])) {
                    continue;
                }

                $slug = ExerciseSlug::from(is_string($exercise['exercise_name'] ?? null) ? $exercise['exercise_name'] : null);

                if ($slug !== null) {
                    $planned[$slug][] = [(string) $exercise['exercise_name'], (float) $exercise['planned_weight_kg']];
                }
            }
        }

        return $planned;
    }

    /**
     * The heaviest completed set per exercise, from records that are real evidence
     * (entered or migrated); an assumed one-tap completion is never a demonstrated load.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, float>
     */
    private function demonstratedLoads(User $user, array $slugs): array
    {
        return DB::table('workout_sets')
            ->join('workout_exercises', 'workout_exercises.id', '=', 'workout_sets.workout_exercise_id')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->whereIn('workout_exercises.exercise_slug', $slugs)
            ->where('workout_exercises.recorded_as', '!=', WorkoutExercise::RECORDED_AS_PLANNED)
            ->where('workout_sets.completed', true)
            ->whereNotNull('workout_sets.weight_kg')
            ->whereDate('workout_sessions.logged_date', '>=', $user->localNow()->subDays(ProgressionPolicy::DEMONSTRATED_LOAD_DAYS)->toDateString())
            ->groupBy('workout_exercises.exercise_slug')
            ->selectRaw('workout_exercises.exercise_slug as slug, max(workout_sets.weight_kg) as top')
            ->pluck('top', 'slug')
            ->map(fn ($top) => (float) $top)
            ->all();
    }

    private function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
