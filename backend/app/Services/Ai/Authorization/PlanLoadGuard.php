<?php

namespace App\Services\Ai\Authorization;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Support\ExerciseSlug;
use Illuminate\Support\Facades\DB;

/**
 * The model does not get to pick the loads in a plan. Two limits, by what authorized the write:
 *
 *  - BASELINE (the default; weekly generation and adaptation, "what should I do today?", reports
 *    of past activity): a plan the model writes may not RAISE an exercise's load. For each exercise
 *    and day it may carry at most what the user last performed for that exact exercise, or what
 *    that day already carries if that is higher (so an approved increase is carried forward, never
 *    copied onto another day). Loads only go up when the user approves a calculated suggestion
 *    (Phase 5B) or explicitly asks for the change.
 *  - MAXIMUM PERMITTED CHANGE (when the user explicitly asked for the change, or confirmed one): a
 *    plan the model writes may not carry a load more than the policy's maximum permitted change
 *    above the heaviest load the user has recorded for that exact exercise.
 *
 * This is a MAXIMUM, not a recommendation and not a judgment of safety: staying
 * under it does not show a load suits the person. Injury, technique, fatigue and
 * equipment are not in the record, and the progression engine applies its own,
 * much stricter evidence requirements before it proposes anything. The guard only
 * stops a single plan write from being a jump the policy never permits.
 *
 * NO BASELINE, NO LOAD. An exercise with nothing to compare against (no recorded load for that exact
 * exercise and none already planned for that day) has no reliable starting weight, and the model
 * cannot supply one: nothing here is a personalized recommendation, and there is no universal "safe
 * starting weight" to fall back on. So a load the model writes for it is LEFT UNSET (the plan keeps
 * the exercise with its sets and reps, and the user is told to choose a weight they can lift
 * comfortably). The one exception is a load the user wrote in their own explicit instruction ("add
 * goblet squats at 16 kg"), which is theirs to choose. Bodyweight and duration-based exercises have
 * no load and are untouched. Lighter loads are never touched. A fixed handful of queries, whatever
 * the plan contains.
 */
class PlanLoadGuard
{
    /**
     * The tool call with every load that has no reliable baseline removed (left unset).
     *
     * @param  array<string, mixed>  $arguments  the tool call's arguments, untrusted
     * @param  array<int, float>  $statedKg  loads the USER wrote in their own instruction; only these may start an exercise
     * @return array{arguments: array<string, mixed>, unset: array<int, array{exercise: string, date: string, requested_kg: float}>}
     */
    public function withoutUnbaselinedLoads(User $user, string $toolName, array $arguments, bool $mayRaiseLoads, array $statedKg): array
    {
        $planned = $this->plannedLoads($user, $toolName, $arguments);

        if ($planned === []) {
            return ['arguments' => $arguments, 'unset' => []];
        }

        $performed = $this->latestPerformedLoads($user, array_keys($planned));
        $onThatDay = $this->loadsAlreadyPlanned($user, $planned);
        $unset = [];

        $arguments = $this->mapExercises($user, $toolName, $arguments, function (array $exercise, string $date) use ($performed, $onThatDay, $mayRaiseLoads, $statedKg, &$unset) {
            if (! isset($exercise['planned_weight_kg']) || ! is_numeric($exercise['planned_weight_kg']) || (float) $exercise['planned_weight_kg'] <= 0.0) {
                return $exercise; // no load (bodyweight, duration-based, or simply unspecified): nothing to protect
            }

            $slug = ExerciseSlug::from(is_string($exercise['exercise_name'] ?? null) ? $exercise['exercise_name'] : null);
            $load = (float) $exercise['planned_weight_kg'];

            if ($slug !== null && (isset($performed[$slug]) || isset($onThatDay[$slug][$date]))) {
                return $exercise; // there is something real to compare it with; violations() judges it
            }

            // The user chose this number themselves, in an instruction that asked for the change.
            if ($mayRaiseLoads && array_filter($statedKg, fn (float $kg) => abs($kg - $load) < 0.01) !== []) {
                return $exercise;
            }

            unset($exercise['planned_weight_kg']);
            $unset[] = ['exercise' => is_string($exercise['exercise_name'] ?? null) ? $exercise['exercise_name'] : 'an exercise', 'date' => $date, 'requested_kg' => $load];

            return $exercise;
        });

        return ['arguments' => $arguments, 'unset' => $unset];
    }

    /**
     * @param  array<string, mixed>  $arguments  the tool call's arguments, untrusted
     * @param  bool  $mayRaiseLoads  true only when the user explicitly asked for (or confirmed) the change
     * @return array<int, string> one message per exercise over its limit; empty when the call is fine
     */
    public function violations(User $user, string $toolName, array $arguments, bool $mayRaiseLoads): array
    {
        $planned = $this->plannedLoads($user, $toolName, $arguments);

        if ($planned === []) {
            return [];
        }

        $slugs = array_keys($planned);
        $messages = [];

        if ($mayRaiseLoads) {
            $demonstrated = $this->demonstratedLoads($user, $slugs);

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

        $performed = $this->latestPerformedLoads($user, $slugs);
        $onThatDay = $this->loadsAlreadyPlanned($user, $planned);

        foreach ($planned as $slug => $entries) {
            foreach ($entries as [$name, $load, $date]) {
                $baseline = max(array_filter([$performed[$slug] ?? null, $onThatDay[$slug][$date] ?? null], fn ($v) => $v !== null) ?: [null]);

                if ($baseline !== null && $load > $baseline + 1e-9) {
                    $messages[] = sprintf(
                        'planned_weight_kg %s for "%s" on %s would raise its load above the %s kg the user last performed or already has planned for that day. Loads only go up when the user approves a calculated suggestion or explicitly asks for the change, so nothing was changed. Use %s kg or less; if a heavier load looks right, say that a suggestion can be reviewed on the Weekly Plan screen.',
                        $this->kg($load), $name, $date, $this->kg($baseline), $this->kg($baseline),
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

    /** @return array<string, array<int, array{0: string, 1: float, 2: string}>> slug => [[name, planned load, date], ...] */
    private function plannedLoads(User $user, string $toolName, array $arguments): array
    {
        $today = $user->localToday();
        $lists = match ($toolName) {
            'create_workout_plan' => [[$this->dateOf($arguments['planned_date'] ?? null, $today), $arguments['exercises'] ?? []]],
            'propose_weekly_plan_changes' => array_map(
                fn ($change) => is_array($change) ? [$this->dateOf($change['date'] ?? null, $today), $change['exercises'] ?? []] : [$today, []],
                is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [],
            ),
            default => [],
        };

        $planned = [];

        foreach ($lists as [$date, $exercises]) {
            foreach (is_array($exercises) ? $exercises : [] as $exercise) {
                if (! is_array($exercise) || ! isset($exercise['planned_weight_kg']) || ! is_numeric($exercise['planned_weight_kg'])) {
                    continue;
                }

                $slug = ExerciseSlug::from(is_string($exercise['exercise_name'] ?? null) ? $exercise['exercise_name'] : null);

                if ($slug !== null) {
                    $planned[$slug][] = [(string) $exercise['exercise_name'], (float) $exercise['planned_weight_kg'], $date];
                }
            }
        }

        return $planned;
    }

    /**
     * Apply $fn to every exercise a plan tool call would write, with the date it would be written to.
     *
     * @param  callable(array<string, mixed>, string): array<string, mixed>  $fn
     * @return array<string, mixed>
     */
    private function mapExercises(User $user, string $toolName, array $arguments, callable $fn): array
    {
        $today = $user->localToday();
        $map = fn (mixed $exercises, string $date) => is_array($exercises)
            ? array_map(fn ($exercise) => is_array($exercise) ? $fn($exercise, $date) : $exercise, $exercises)
            : $exercises;

        if ($toolName === 'create_workout_plan' && isset($arguments['exercises'])) {
            $arguments['exercises'] = $map($arguments['exercises'], $this->dateOf($arguments['planned_date'] ?? null, $today));
        }

        if ($toolName === 'propose_weekly_plan_changes' && is_array($arguments['changes'] ?? null)) {
            $arguments['changes'] = array_map(function ($change) use ($map, $today) {
                if (is_array($change) && isset($change['exercises'])) {
                    $change['exercises'] = $map($change['exercises'], $this->dateOf($change['date'] ?? null, $today));
                }

                return $change;
            }, $arguments['changes']);
        }

        return $arguments;
    }

    private function dateOf(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : $fallback;
    }

    /**
     * The load the user last performed for each exact exercise (the top load of the most recent
     * entered or migrated session that has a recorded load). One query.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, float>
     */
    private function latestPerformedLoads(User $user, array $slugs): array
    {
        $rows = DB::table('workout_exercises')
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_exercises.workout_session_id')
            ->join('workout_sets', 'workout_sets.workout_exercise_id', '=', 'workout_exercises.id')
            ->where('workout_sessions.user_id', $user->id)
            ->whereIn('workout_exercises.exercise_slug', $slugs)
            ->where('workout_exercises.recorded_as', '!=', WorkoutExercise::RECORDED_AS_PLANNED)
            ->where('workout_sets.completed', true)
            ->whereNotNull('workout_sets.weight_kg')
            ->whereDate('workout_sessions.logged_date', '>=', $user->localNow()->subDays(ProgressionPolicy::DEMONSTRATED_LOAD_DAYS)->toDateString())
            ->groupBy('workout_exercises.id', 'workout_exercises.exercise_slug', 'workout_sessions.logged_date')
            ->orderByDesc('workout_sessions.logged_date')
            ->orderByDesc('workout_exercises.id')
            ->selectRaw('workout_exercises.exercise_slug as slug, max(workout_sets.weight_kg) as top')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->slug] ??= (float) $row->top;
        }

        return $latest;
    }

    /**
     * What each exercise already carries on the days being written, so a day's existing (for
     * example user-approved) load can be kept but never copied onto another day. One query.
     *
     * @param  array<string, array<int, array{0: string, 1: float, 2: string}>>  $planned
     * @return array<string, array<string, float>> slug => date => load
     */
    private function loadsAlreadyPlanned(User $user, array $planned): array
    {
        $dates = array_values(array_unique(array_merge(...array_map(fn (array $entries) => array_column($entries, 2), array_values($planned)))));

        $rows = DB::table('workout_plan_exercises')
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $user->id)
            ->whereIn('workout_plan_exercises.exercise_slug', array_keys($planned))
            ->whereIn(DB::raw('date(workout_plans.planned_date)'), $dates)
            ->whereNotNull('workout_plan_exercises.planned_weight_kg')
            ->selectRaw('workout_plan_exercises.exercise_slug as slug, date(workout_plans.planned_date) as day, max(workout_plan_exercises.planned_weight_kg) as load_kg')
            ->groupBy('workout_plan_exercises.exercise_slug', DB::raw('date(workout_plans.planned_date)'))
            ->get();

        $loads = [];

        foreach ($rows as $row) {
            $loads[$row->slug][substr((string) $row->day, 0, 10)] = (float) $row->load_kg;
        }

        return $loads;
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
