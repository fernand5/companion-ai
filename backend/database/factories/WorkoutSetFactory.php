<?php

namespace Database\Factories;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutSet>
 */
class WorkoutSetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_exercise_id' => WorkoutExercise::factory(),
            'set_number' => 1,
            'reps' => 10,
            'weight_kg' => 40,
            'duration_seconds' => null,
            'completed' => true,
        ];
    }
}
