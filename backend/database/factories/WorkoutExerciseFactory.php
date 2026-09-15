<?php

namespace Database\Factories;

use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkoutExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_session_id' => WorkoutSession::factory(),
            'exercise_name' => $this->faker->randomElement([
                'Goblet Squat', 'Dumbbell Bench Press', 'Bent-Over Row', 'Romanian Deadlift', 'Plank',
            ]),
            'sets' => $this->faker->numberBetween(3, 5),
            'reps' => $this->faker->numberBetween(8, 15),
            'weight_kg' => $this->faker->randomFloat(2, 8, 40),
            'duration_seconds' => null,
            'notes' => null,
            'position' => 0,
        ];
    }
}
