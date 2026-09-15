<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FitnessProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'height_cm' => $this->faker->randomFloat(1, 150, 200),
            'weight_kg' => $this->faker->randomFloat(1, 50, 110),
            'age' => $this->faker->numberBetween(18, 65),
            'sex' => $this->faker->randomElement(['male', 'female', null]),
            'fitness_level' => $this->faker->randomElement(['beginner', 'intermediate', 'advanced']),
            'primary_goal' => $this->faker->randomElement([
                'Lose body fat while maintaining muscle',
                'Build strength',
                'Improve endurance',
                'General health',
            ]),
            'secondary_goal' => $this->faker->optional()->sentence(4),
            'equipment' => $this->faker->randomElements(
                ['dumbbells', 'exercise mat', 'treadmill', 'barbell', 'resistance bands', 'kettlebell'],
                $this->faker->numberBetween(1, 4)
            ),
            'preferred_training_days' => $this->faker->randomElements(
                [1, 2, 3, 4, 5, 6, 0],
                $this->faker->numberBetween(2, 4)
            ),
            'preferred_training_duration_minutes' => $this->faker->randomElement([20, 30, 45, 60]),
        ];
    }
}
