<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkoutPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a *batch* of targeted changes to the current week's structured
 * plan — a distinct concern from WorkoutPlanService, which remains the
 * single-day authority and is reused here for every actual write. A "week"
 * is always Monday through Sunday, computed relative to the user's own local
 * "now" (User::localNow()) rather than the server's UTC clock — otherwise
 * evening hours in timezones behind UTC (most of the Americas) would see
 * "today" already rolled over to tomorrow on the server, misclassifying
 * which week/day an activity or plan belongs to.
 */
class WeeklyPlanService
{
    public function __construct(private readonly WorkoutPlanService $workoutPlanService) {}

    /**
     * @return array{start: string, end: string}
     */
    public function weekRange(User $user, ?Carbon $reference = null): array
    {
        $start = ($reference ?? $user->localNow())->copy()->startOfWeek(Carbon::MONDAY);

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addDays(6)->toDateString(),
        ];
    }

    /**
     * Dates within the current week that have a recurring TrainingSchedule
     * commitment (e.g. "Soccer every Tuesday") but no WorkoutPlan yet. This is
     * the one place "does the week reflect the recurring schedule" is decided
     * — used to trigger a *targeted* fill (never a full-week regeneration)
     * whenever the week isn't fully empty but still has schedule-driven gaps,
     * e.g. a plan created ad hoc via Coach chat for one day shouldn't block
     * the other recurring days from ever being generated.
     *
     * @return string[]
     */
    public function scheduledDaysMissingPlans(User $user): array
    {
        $scheduledDaysOfWeek = $user->trainingSchedules()->pluck('day_of_week')->unique();

        if ($scheduledDaysOfWeek->isEmpty()) {
            return [];
        }

        $weekRange = $this->weekRange($user);

        $existingDates = $this->workoutPlanService->forRange($user, $weekRange['start'], $weekRange['end'])
            ->map(fn (WorkoutPlan $plan) => $plan->planned_date->toDateString())
            ->all();

        $missing = [];
        $cursor = Carbon::parse($weekRange['start']);

        for ($i = 0; $i < 7; $i++) {
            $date = $cursor->copy()->addDays($i);

            if ($scheduledDaysOfWeek->contains($date->dayOfWeek) && ! in_array($date->toDateString(), $existingDates, true)) {
                $missing[] = $date->toDateString();
            }
        }

        return $missing;
    }

    /**
     * Validates every change up front (nothing is written if any change is
     * invalid — the whole batch fails atomically), then applies the valid
     * batch inside one transaction.
     *
     * A day that is started, partly done, completed or has recorded performance is skipped rather than overwritten,
     * since that would corrupt logged history the user has already confirmed. A day that already has an untouched plan
     * is only replaced or cleared if $mayReplace says the user authorized it for that day (`true` for any day, or the
     * list of days); otherwise it is skipped as "needs_confirmation" and the rest of the batch goes ahead. Days with
     * nothing planned are always written.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @param  string[]  $reasoningFactors
     * @param  true|array<int, string>  $mayReplace
     * @return array{applied: string[], skipped: array<int, array{date: string, reason: string}>, cleared: string[]}
     */
    public function applyChanges(User $user, array $changes, array $reasoningFactors = [], true|array $mayReplace = []): array
    {
        $validatedChanges = $this->validatedChanges($user, $changes);

        return DB::transaction(function () use ($user, $validatedChanges, $reasoningFactors, $mayReplace) {
            $applied = [];
            $skipped = [];
            $cleared = [];

            foreach ($validatedChanges as $change) {
                $date = $change['date'];
                $existing = $this->workoutPlanService->forDate($user, $date);

                if ($existing && ! $existing->isReplaceable()) {
                    $skipped[] = ['date' => $date, 'reason' => $existing->status === WorkoutPlan::STATUS_IN_PROGRESS && ! $existing->hasRecordedPerformance() ? 'already_started' : 'already_completed'];

                    continue;
                }

                $replaces = $existing !== null;

                if ($replaces && $mayReplace !== true && ! in_array($date, $mayReplace, true)) {
                    $skipped[] = ['date' => $date, 'reason' => 'needs_confirmation'];

                    continue;
                }

                if ($change['action'] === 'clear') {
                    $this->clearDay($user, $date, $change['reason'], $reasoningFactors);
                    $cleared[] = $date;

                    continue;
                }

                $this->workoutPlanService->createOrReplace($user, [
                    'planned_date' => $date,
                    'activity_type' => $change['activity_type'],
                    'title' => $change['title'],
                    'duration_minutes' => $change['duration_minutes'] ?? null,
                    'exercises' => $change['exercises'] ?? null,
                    'reasoning' => $change['reason'],
                    'reasoning_factors' => $reasoningFactors,
                    'source' => WorkoutPlan::SOURCE_AI,
                ], replaceExisting: $replaces);

                $applied[] = $date;
            }

            return compact('applied', 'skipped', 'cleared');
        });
    }

    /**
     * Mark a day as an explicit rest day. Marked skipped rather than deleted, for two reasons: (1) the day's
     * reasoning/reasoning_factors stay visible ("why is nothing planned here" shouldn't lose its explanation), and (2)
     * a deleted row would look identical to "never generated" to scheduledDaysMissingPlans() — the next schedule-gap
     * fill would silently recreate a plan the user explicitly cleared. Replaces whatever untouched plan is there, so the
     * caller must already hold the user's authorization for that.
     *
     * @param  string[]  $reasoningFactors
     */
    public function clearDay(User $user, string $date, string $reason, array $reasoningFactors = []): WorkoutPlan
    {
        $plan = $this->workoutPlanService->createOrReplace($user, [
            'planned_date' => $date,
            'activity_type' => ActivityLog::TYPE_RECOVERY,
            'title' => WorkoutPlan::REST_TITLE,
            'reasoning' => $reason,
            'reasoning_factors' => $reasoningFactors,
            'source' => WorkoutPlan::SOURCE_AI,
        ], replaceExisting: true);
        $plan->update(['status' => WorkoutPlan::STATUS_SKIPPED]);

        return $plan;
    }

    /**
     * Each change checked and normalised, or a ValidationException for the first bad one. Nothing is written.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public function validatedChanges(User $user, array $changes): array
    {
        return $this->validateChanges($changes, $this->weekRange($user));
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array{start: string, end: string}  $weekRange
     * @return array<int, array<string, mixed>>
     */
    private function validateChanges(array $changes, array $weekRange): array
    {
        $validated = [];

        foreach ($changes as $index => $change) {
            $item = Validator::make(is_array($change) ? $change : [], [
                'date' => ['required', 'date'],
                'action' => ['required', 'string', 'in:replace,clear'],
                'reason' => ['required', 'string', 'max:500'],
                'activity_type' => ['required_if:action,replace', 'nullable', 'string', 'in:'.implode(',', array_diff(ActivityService::TYPES, ['weight']))],
                'title' => ['required_if:action,replace', 'nullable', 'string', 'max:255'],
                'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
                'exercises' => ['nullable', 'array'],
                'exercises.*.exercise_name' => ['required_with:exercises', 'string', 'max:255'],
                'exercises.*.planned_sets' => ['nullable', 'integer', 'min:0', 'max:50'],
                'exercises.*.planned_reps' => ['nullable', 'integer', 'min:0', 'max:200'],
                'exercises.*.planned_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
                'exercises.*.planned_duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            ])->validate();

            if ($item['date'] < $weekRange['start'] || $item['date'] > $weekRange['end']) {
                throw ValidationException::withMessages([
                    "changes.{$index}.date" => "Date {$item['date']} is outside the current week ({$weekRange['start']} to {$weekRange['end']}).",
                ]);
            }

            $validated[] = $item;
        }

        return $validated;
    }
}
