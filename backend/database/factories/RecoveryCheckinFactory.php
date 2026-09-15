<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecoveryCheckinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'checkin_date' => now()->toDateString(),
            'energy' => 4,
            'soreness' => 2,
            'motivation' => 4,
            'perceived_difficulty' => null,
            'pain_notes' => null,
        ];
    }
}
