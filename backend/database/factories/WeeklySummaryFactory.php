<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WeeklySummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => now()->startOfWeek()->toDateString(),
            'stats' => [
                'adherence_pct' => 75,
                'due_exercise_count' => 4,
                'planned_workouts_completed' => 1,
                'planned_workouts_partial' => 0,
                'planned_workouts_skipped' => 0,
                'active_days' => 3,
            ],
            'coach_insight' => 'Solid week overall.',
            'next_week_focus' => 'Keep sessions under 45 minutes.',
            'generated_at' => now(),
        ];
    }
}
