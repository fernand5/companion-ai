<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use Carbon\Carbon;
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
            ->with('exercises')
            ->whereDate('logged_date', '>=', Carbon::today()->subDays($days - 1)->toDateString())
            ->orderByDesc('logged_date')
            ->get();
    }

    public function log(User $user, array $data): WorkoutSession
    {
        $validated = Validator::make($data, [
            'logged_date' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'notes' => 'nullable|string|max:2000',
            'exercises' => 'nullable|array',
            'exercises.*.exercise_name' => 'required_with:exercises|string|max:255',
            'exercises.*.sets' => 'nullable|integer|min:0|max:50',
            'exercises.*.reps' => 'nullable|integer|min:0|max:200',
            'exercises.*.weight_kg' => 'nullable|numeric|min:0|max:500',
            'exercises.*.duration_seconds' => 'nullable|integer|min:0|max:7200',
            'exercises.*.notes' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(
            fn () => $this->writeSession($user, $validated, $validated['exercises'] ?? [])
        );
    }

    /**
     * Idempotently sync a WorkoutPlan's actual outcome into the "real activity"
     * infrastructure (workout_sessions/workout_exercises + the mirrored
     * activity_logs row) — the same tables freeform logging already writes to,
     * so the rest of the app (AI tools, progress, weekly summaries) never needs
     * to know whether a session came from a plan or a one-off log. Safe to call
     * repeatedly as the user edits exercise statuses: updates the same session
     * in place rather than creating duplicates.
     */
    public function syncFromPlan(User $user, WorkoutPlan $plan): WorkoutSession
    {
        $exercises = $plan->exercises->map(fn ($exercise) => [
            'exercise_name' => $exercise->exercise_name,
            'sets' => $exercise->actual_sets ?? $exercise->planned_sets,
            'reps' => $exercise->actual_reps ?? $exercise->planned_reps,
            'weight_kg' => $exercise->actual_weight_kg !== null
                ? (float) $exercise->actual_weight_kg
                : ($exercise->planned_weight_kg !== null ? (float) $exercise->planned_weight_kg : null),
            'duration_seconds' => $exercise->actual_duration_seconds ?? $exercise->planned_duration_seconds,
        ])->all();

        return DB::transaction(function () use ($user, $plan, $exercises) {
            $existing = $plan->workout_session_id
                ? WorkoutSession::find($plan->workout_session_id)
                : null;

            $session = $this->writeSession($user, [
                'logged_date' => $plan->planned_date->toDateString(),
                'duration_minutes' => $plan->duration_minutes,
                'notes' => $plan->title,
            ], $exercises, $existing);

            if ($plan->workout_session_id !== $session->id) {
                $plan->update(['workout_session_id' => $session->id]);
            }

            return $session;
        });
    }

    /**
     * Create or update (in place) a WorkoutSession + its exercises, and the
     * mirrored activity_logs row that puts it on the unified activity timeline.
     * Passing $existing makes this idempotent — the session/exercises/mirror
     * row are updated rather than duplicated.
     *
     * @param  array{logged_date?: ?string, duration_minutes?: ?int, notes?: ?string}  $sessionData
     * @param  array<int, array{exercise_name: string, sets?: ?int, reps?: ?int, weight_kg?: ?float, duration_seconds?: ?int, notes?: ?string}>  $exercises
     */
    private function writeSession(User $user, array $sessionData, array $exercises, ?WorkoutSession $existing = null): WorkoutSession
    {
        $loggedDate = $sessionData['logged_date'] ?? Carbon::today()->toDateString();
        $durationMinutes = $sessionData['duration_minutes'] ?? null;
        $notes = $sessionData['notes'] ?? null;

        if ($existing) {
            $existing->update([
                'logged_date' => $loggedDate,
                'duration_minutes' => $durationMinutes,
                'notes' => $notes,
            ]);
            $session = $existing;
            $session->exercises()->delete();
        } else {
            $session = $user->workoutSessions()->create([
                'logged_date' => $loggedDate,
                'duration_minutes' => $durationMinutes,
                'notes' => $notes,
            ]);
        }

        foreach ($exercises as $position => $exercise) {
            $session->exercises()->create([
                'exercise_name' => $exercise['exercise_name'],
                'sets' => $exercise['sets'] ?? null,
                'reps' => $exercise['reps'] ?? null,
                'weight_kg' => $exercise['weight_kg'] ?? null,
                'duration_seconds' => $exercise['duration_seconds'] ?? null,
                'notes' => $exercise['notes'] ?? null,
                'position' => $position,
            ]);
        }

        $activityData = [
            'type' => ActivityLog::TYPE_STRENGTH,
            'logged_date' => $loggedDate,
            'duration_minutes' => $durationMinutes,
            'intensity' => 'high',
            'notes' => $notes,
            'metadata' => ['exercise_count' => count($exercises)],
            'workout_session_id' => $session->id,
        ];

        $mirroredLog = ActivityLog::where('workout_session_id', $session->id)->first();

        if ($mirroredLog) {
            $mirroredLog->update($activityData);
        } else {
            $user->activityLogs()->create($activityData);
        }

        return $session->load('exercises');
    }
}
