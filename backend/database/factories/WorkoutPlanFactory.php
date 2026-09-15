<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkoutPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkoutPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'planned_date' => now()->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Full Body Strength',
            'source' => WorkoutPlan::SOURCE_AI,
            'status' => WorkoutPlan::STATUS_PLANNED,
            'duration_minutes' => 45,
            'reasoning' => 'Keeping today moderate ahead of your next high-intensity session.',
            'reasoning_factors' => ['Balanced week so far', 'Equipment available at home'],
        ];
    }
}
