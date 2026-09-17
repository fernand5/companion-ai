<?php

namespace App\Services\Fitness;

use App\Models\RecoveryCheckin;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

class RecoveryService
{
    public function today(User $user): ?RecoveryCheckin
    {
        return $user->recoveryCheckins()
            ->whereDate('checkin_date', $user->localToday())
            ->first();
    }

    public function latest(User $user): ?RecoveryCheckin
    {
        return $user->recoveryCheckins()->orderByDesc('checkin_date')->first();
    }

    /**
     * @return Collection<int, RecoveryCheckin>
     */
    public function recent(User $user, int $days = 14): Collection
    {
        return $user->recoveryCheckins()
            ->whereDate('checkin_date', '>=', $user->localNow()->subDays($days - 1)->toDateString())
            ->orderByDesc('checkin_date')
            ->get();
    }

    /**
     * One check-in per user per day — resubmitting the same date updates it
     * rather than creating a duplicate.
     */
    public function upsert(User $user, array $data): RecoveryCheckin
    {
        $validated = Validator::make($data, [
            'checkin_date' => 'nullable|date',
            'energy' => 'required|integer|min:1|max:5',
            'soreness' => 'required|integer|min:1|max:5',
            'motivation' => 'required|integer|min:1|max:5',
            'perceived_difficulty' => 'nullable|integer|min:1|max:5',
            'pain_notes' => 'nullable|string|max:2000',
        ])->validate();

        $checkinDate = $validated['checkin_date'] ?? $user->localToday();

        $attributes = [
            'energy' => $validated['energy'],
            'soreness' => $validated['soreness'],
            'motivation' => $validated['motivation'],
            'perceived_difficulty' => $validated['perceived_difficulty'] ?? null,
            'pain_notes' => $validated['pain_notes'] ?? null,
        ];

        // whereDate() lookup, not a raw where() match — the `date` cast can
        // serialize with a time component on save (see WorkoutPlanService).
        $checkin = $user->recoveryCheckins()->whereDate('checkin_date', $checkinDate)->first();

        if ($checkin) {
            $checkin->update($attributes);
        } else {
            $checkin = $user->recoveryCheckins()->create($attributes + ['checkin_date' => $checkinDate]);
        }

        return $checkin;
    }
}
