<?php

namespace App\Services\Fitness;

use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ScheduleService
{
    /**
     * Add a recurring weekly entry, or update it in place if one already
     * exists for the same day + activity (case-insensitive) — so re-stating
     * "soccer on Tuesdays" doesn't create duplicate rows.
     */
    public function create(User $user, array $data): TrainingSchedule
    {
        $validated = Validator::make($data, [
            'activity_type' => 'required|string|max:255',
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'expected_duration_minutes' => 'required|integer|min:5|max:600',
            'intensity' => 'nullable|string|in:low,moderate,high',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        $existing = $user->trainingSchedules()
            ->where('day_of_week', $validated['day_of_week'])
            ->whereRaw('LOWER(activity_type) = ?', [mb_strtolower($validated['activity_type'])])
            ->first();

        if ($existing) {
            $existing->update($validated);

            return $existing;
        }

        return $user->trainingSchedules()->create($validated);
    }

    /**
     * Expand recurring weekly schedule entries into concrete upcoming
     * occurrences over the next $days (inclusive of today).
     *
     * @return Collection<int, array{schedule: TrainingSchedule, date: string}>
     */
    public function upcoming(User $user, int $days = 7): Collection
    {
        $schedules = $user->trainingSchedules()->get();
        $today = $user->localNow();
        $occurrences = collect();

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $today->copy()->addDays($offset);

            foreach ($schedules->where('day_of_week', $date->dayOfWeek) as $schedule) {
                $occurrences->push(['schedule' => $schedule, 'date' => $date->toDateString()]);
            }
        }

        return $occurrences->sortBy('date')->values();
    }
}
