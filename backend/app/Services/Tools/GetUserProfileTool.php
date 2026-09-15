<?php

namespace App\Services\Tools;

use App\Models\User;

class GetUserProfileTool implements AiTool
{
    public function name(): string
    {
        return 'get_user_profile';
    }

    public function description(): string
    {
        return "Get the user's fitness profile: height, weight, age, fitness level, goals, and available equipment.";
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $profile = $user->fitnessProfile;

        if (! $profile) {
            return ['error' => 'No fitness profile set up yet.'];
        }

        return [
            'height_cm' => $profile->height_cm !== null ? (float) $profile->height_cm : null,
            'weight_kg' => $profile->weight_kg !== null ? (float) $profile->weight_kg : null,
            'age' => $profile->age,
            'sex' => $profile->sex,
            'fitness_level' => $profile->fitness_level,
            'primary_goal' => $profile->primary_goal,
            'secondary_goal' => $profile->secondary_goal,
            'equipment' => $profile->equipment,
            'preferred_training_days' => $profile->preferred_training_days,
            'preferred_training_duration_minutes' => $profile->preferred_training_duration_minutes,
        ];
    }
}
