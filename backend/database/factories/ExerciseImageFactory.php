<?php

namespace Database\Factories;

use App\Models\ExerciseImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExerciseImage>
 */
class ExerciseImageFactory extends Factory
{
    public const READY_URL = 'https://images.example.test/exercises/ready-image.jpg';

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'status' => ExerciseImage::STATUS_PENDING,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => ExerciseImage::STATUS_READY,
            'image_url' => self::READY_URL,
            'object_path' => 'exercises/ready-image.jpg',
            'generated_at' => now(),
            'model' => 'test-image-model',
        ]);
    }

    public function generating(?\DateTimeInterface $startedAt = null): static
    {
        return $this->state(fn () => [
            'status' => ExerciseImage::STATUS_GENERATING,
            'generating_started_at' => $startedAt ?? now(),
            'attempts' => 1,
        ]);
    }

    public function failed(int $attempts = 1, ?\DateTimeInterface $failedAt = null): static
    {
        return $this->state(fn () => [
            'status' => ExerciseImage::STATUS_FAILED,
            'attempts' => $attempts,
            'failed_at' => $failedAt ?? now(),
            'last_error' => 'unavailable',
        ]);
    }
}
