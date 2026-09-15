<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkoutSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'logged_date' => now()->toDateString(),
            'duration_minutes' => $this->faker->numberBetween(30, 60),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
