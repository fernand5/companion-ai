<?php

namespace Database\Factories;

use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkoutPlanExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_plan_id' => WorkoutPlan::factory(),
            'exercise_name' => $this->faker->randomElement([
                'Goblet Squat', 'Dumbbell Bench Press', 'Bent-Over Row', 'Romanian Deadlift', 'Plank',
            ]),
            'planned_sets' => 3,
            'planned_reps' => 10,
            'planned_weight_kg' => 15,
            'position' => 0,
            'status' => WorkoutPlanExercise::STATUS_PENDING,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => WorkoutPlanExercise::STATUS_COMPLETED,
            'actual_sets' => 3,
            'actual_reps' => 10,
            'actual_weight_kg' => 15,
            'completed_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => ['status' => WorkoutPlanExercise::STATUS_SKIPPED]);
    }
}
