<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_type' => $this->faker->randomElement(['Football', 'Strength', 'Cardio']),
            'day_of_week' => $this->faker->numberBetween(0, 6),
            'start_time' => $this->faker->randomElement(['18:00', '19:00', '20:00']),
            'expected_duration_minutes' => $this->faker->randomElement([45, 60, 90]),
            'intensity' => $this->faker->randomElement(['low', 'moderate', 'high']),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
