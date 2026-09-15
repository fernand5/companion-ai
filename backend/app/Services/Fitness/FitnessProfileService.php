<?php

namespace App\Services\Fitness;

use App\Models\FitnessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class FitnessProfileService
{
    /**
     * Partial update — only the fields present in $data are validated and
     * written, so a call that only mentions the user's goal doesn't touch
     * their equipment/height/etc. Body weight is intentionally NOT handled
     * here — that always goes through WeightService/update_weight so it's
     * tracked with history, not silently overwritten.
     */
    public function update(User $user, array $data): FitnessProfile
    {
        $validated = Validator::make($data, [
            'height_cm' => ['nullable', 'numeric', 'min:50', 'max:260'],
            'age' => ['nullable', 'integer', 'min:10', 'max:120'],
            'sex' => ['nullable', 'string', 'in:male,female,other'],
            'fitness_level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
            'primary_goal' => ['nullable', 'string', 'max:255'],
            'secondary_goal' => ['nullable', 'string', 'max:255'],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['string', 'max:100'],
            'preferred_training_days' => ['nullable', 'array'],
            'preferred_training_days.*' => ['integer', 'min:0', 'max:6'],
            'preferred_training_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ])->validate();

        return $user->fitnessProfile()->updateOrCreate(['user_id' => $user->id], $validated);
    }
}
