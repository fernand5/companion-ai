<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FitnessMemoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'content' => $this->faker->randomElement([
                'Prefers short workouts on football days.',
                'Enjoys Cindy but should not perform it before football.',
                'Prefers training at home.',
                'Tends to train in the evening.',
            ]),
            'category' => 'preference',
            'metadata' => null,
        ];
    }
}
