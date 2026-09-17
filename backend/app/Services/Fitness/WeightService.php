<?php

namespace App\Services\Fitness;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WeightService
{
    /**
     * @return Collection<int, ActivityLog>
     */
    public function history(User $user, int $limit = 30): Collection
    {
        return $user->activityLogs()
            ->where('type', ActivityLog::TYPE_WEIGHT)
            ->orderByDesc('logged_date')
            ->limit($limit)
            ->get();
    }

    public function update(User $user, array $data): ActivityLog
    {
        $validated = Validator::make($data, [
            'weight_kg' => 'required|numeric|min:20|max:400',
            'logged_date' => 'nullable|date',
        ])->validate();

        return DB::transaction(function () use ($user, $validated) {
            $log = $user->activityLogs()->create([
                'type' => ActivityLog::TYPE_WEIGHT,
                'logged_date' => $validated['logged_date'] ?? $user->localToday(),
                'metadata' => ['weight_kg' => $validated['weight_kg']],
            ]);

            $latest = $user->activityLogs()
                ->where('type', ActivityLog::TYPE_WEIGHT)
                ->orderByDesc('logged_date')
                ->orderByDesc('created_at')
                ->first();

            if ($latest && $latest->id === $log->id) {
                $user->fitnessProfile()?->update(['weight_kg' => $validated['weight_kg']]);
            }

            return $log;
        });
    }
}
