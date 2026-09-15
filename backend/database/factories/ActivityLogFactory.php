<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ActivityLog::TYPE_STEPS,
            'logged_date' => now()->toDateString(),
            'duration_minutes' => null,
            'intensity' => null,
            'notes' => null,
            'metadata' => ['steps' => $this->faker->numberBetween(2000, 12000)],
        ];
    }

    public function treadmill(): static
    {
        return $this->state(fn () => [
            'type' => ActivityLog::TYPE_TREADMILL,
            'duration_minutes' => $this->faker->numberBetween(10, 40),
            'intensity' => $this->faker->randomElement(['low', 'moderate', 'high']),
            'metadata' => [
                'distance_km' => $this->faker->randomFloat(2, 1, 8),
                'intervals' => [
                    ['minutes' => 12, 'speed_kmh' => 9],
                    ['minutes' => 5, 'speed_kmh' => 5.5],
                ],
            ],
        ]);
    }

    public function strength(): static
    {
        return $this->state(fn () => [
            'type' => ActivityLog::TYPE_STRENGTH,
            'duration_minutes' => $this->faker->numberBetween(30, 60),
            'intensity' => 'high',
            'metadata' => ['summary' => 'Strength session'],
        ]);
    }

    public function sport(): static
    {
        return $this->state(fn () => [
            'type' => ActivityLog::TYPE_SPORT,
            'duration_minutes' => 60,
            'intensity' => 'high',
            'metadata' => ['sport' => 'Football', 'format' => 'match'],
        ]);
    }

    public function recovery(): static
    {
        return $this->state(fn () => [
            'type' => ActivityLog::TYPE_RECOVERY,
            'duration_minutes' => null,
            'intensity' => null,
            'metadata' => [
                'energy' => $this->faker->numberBetween(1, 5),
                'soreness' => $this->faker->numberBetween(1, 5),
                'sleep_hours' => $this->faker->randomFloat(1, 5, 9),
            ],
        ]);
    }
}
