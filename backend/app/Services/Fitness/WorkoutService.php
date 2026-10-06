<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use App\Support\PlanMirrorClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WorkoutService
{
    /**
     * @return Collection<int, WorkoutSession>
     */
    public function recent(User $user, int $days = 14): Collection
    {
        return $user->workoutSessions()
            ->with(['exercises.performedSets', 'exercises.planExercise'])
            ->whereDate('logged_date', '>=', $user->localNow()->subDays($days - 1)->toDateString())
            ->orderByDesc('logged_date')
            ->get();
    }

    /**
     * Freeform logging ("I did bench 3 x 10 at 40 kg"). Every exercise becomes
     * real set rows: either the per-set `set_details` the client sent, or the
     * classic sets/reps/weight tuple expanded into identical sets. These are
     * numbers the user entered, so they are recorded as such.
     */
    public function log(User $user, array $data): WorkoutSession
    {
        $validated = Validator::make($data, [
            'logged_date' => ['nullable', 'date', 'before_or_equal:'.$user->localToday()],
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'notes' => 'nullable|string|max:2000',
            'exercises' => 'nullable|array',
            'exercises.*.exercise_name' => 'required_with:exercises|string|max:255',
            'exercises.*.sets' => 'nullable|integer|min:0|max:50',
            'exercises.*.reps' => 'nullable|integer|min:0|max:200',
            'exercises.*.weight_kg' => 'nullable|numeric|min:0|max:500',
            'exercises.*.duration_seconds' => 'nullable|integer|min:0|max:7200',
            'exercises.*.notes' => 'nullable|string|max:1000',
            'exercises.*.set_details' => 'nullable|array|max:50',
            'exercises.*.set_details.*.reps' => 'nullable|integer|min:0|max:200',
            'exercises.*.set_details.*.weight_kg' => 'nullable|numeric|min:0|max:500',
            'exercises.*.set_details.*.duration_seconds' => 'nullable|integer|min:0|max:7200',
            'exercises.*.set_details.*.completed' => 'nullable|boolean',
        ], [
            'logged_date.before_or_equal' => 'logged_date cannot be in the future: this records something that already happened. Upcoming sessions belong in the schedule or the plan.',
        ])->validate();

        return DB::transaction(function () use ($user, $validated) {
            $session = $user->workoutSessions()->create([
                'logged_date' => $validated['logged_date'] ?? $user->localToday(),
                'duration_minutes' => $validated['duration_minutes'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $exercises = $validated['exercises'] ?? [];

            foreach ($exercises as $position => $exercise) {
                $this->writeExercise($session, $position, $exercise);
            }

            $this->ensureMirrorLog($user, $session, count($exercises));

            return $session->load('exercises.performedSets');
        });
    }

    /**
     * The mirrored activity_logs row that puts a session on the unified
     * activity timeline. Idempotent: updated in place, never duplicated. A
     * session that came from a plan is classified by that plan's activity type
     * (a recovery session is not a high-intensity strength session); a freeform
     * session has no plan and is strength.
     */
    public function ensureMirrorLog(User $user, WorkoutSession $session, ?int $exerciseCount = null, ?WorkoutPlan $plan = null): ActivityLog
    {
        $profile = PlanMirrorClassification::for($plan?->activity_type);

        $data = [
            'type' => $profile['type'],
            'logged_date' => $session->logged_date->toDateString(),
            'duration_minutes' => $session->duration_minutes,
            'intensity' => $profile['intensity'],
            'notes' => $session->notes,
            'metadata' => ['exercise_count' => $exerciseCount ?? $session->exercises()->count()],
            'workout_session_id' => $session->id,
        ];

        $log = ActivityLog::where('workout_session_id', $session->id)->first();

        if ($log) {
            $log->update($data);

            return $log;
        }

        return $user->activityLogs()->create($data);
    }

    /**
     * @param  array<string, mixed>  $exercise
     */
    private function writeExercise(WorkoutSession $session, int $position, array $exercise): void
    {
        $details = $exercise['set_details'] ?? [];

        $sets = $details !== []
            ? PerformanceSets::normalize($details)
            : PerformanceSets::expand(
                $exercise['sets'] ?? null,
                $exercise['reps'] ?? null,
                isset($exercise['weight_kg']) ? (float) $exercise['weight_kg'] : null,
                $exercise['duration_seconds'] ?? null,
                assumeOneSet: true,
            );

        // With real sets the summary is derived from them. Without any (e.g. just
        // a set count), the values the user gave are kept as they were typed.
        $summary = $sets !== [] ? PerformanceSets::summarize($sets) : [
            'sets' => $exercise['sets'] ?? null,
            'reps' => $exercise['reps'] ?? null,
            'weight_kg' => $exercise['weight_kg'] ?? null,
            'duration_seconds' => $exercise['duration_seconds'] ?? null,
        ];

        $workoutExercise = $session->exercises()->create([
            'exercise_name' => $exercise['exercise_name'],
            'recorded_as' => WorkoutExercise::RECORDED_ENTERED,
            'sets' => $summary['sets'],
            'reps' => $summary['reps'],
            'weight_kg' => $summary['weight_kg'],
            'duration_seconds' => $summary['duration_seconds'],
            'notes' => $exercise['notes'] ?? null,
            'position' => $position,
        ]);

        foreach ($sets as $i => $set) {
            $workoutExercise->performedSets()->create($set + ['set_number' => $i + 1]);
        }
    }
}
