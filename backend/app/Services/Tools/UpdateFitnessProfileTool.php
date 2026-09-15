<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\FitnessProfileService;
use Illuminate\Validation\ValidationException;

class UpdateFitnessProfileTool implements AiTool
{
    public function __construct(private readonly FitnessProfileService $fitnessProfileService) {}

    public function name(): string
    {
        return 'update_fitness_profile';
    }

    public function description(): string
    {
        return "Update the user's fitness profile — goals, fitness level, height, equipment, preferred training "
            .'days/duration. Call this whenever the user tells you any of this in conversation (even as part of a '
            .'longer message describing their goals/equipment/preferences) — only include the fields they actually '
            .'stated, do not guess the rest. Do NOT use this for body weight — use update_weight instead so it stays '
            .'tracked with history.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'height_cm' => ['type' => 'number'],
                'age' => ['type' => 'integer'],
                'sex' => ['type' => 'string', 'description' => 'One of: male, female, other.'],
                'fitness_level' => ['type' => 'string', 'description' => 'One of: beginner, intermediate, advanced.'],
                'primary_goal' => ['type' => 'string'],
                'secondary_goal' => ['type' => 'string'],
                'equipment' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of equipment the user has available, e.g. ["2x 6kg dumbbells", "treadmill", "exercise mat"].',
                ],
                'preferred_training_days' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Days the user prefers to train, 0=Sunday..6=Saturday.',
                ],
                'preferred_training_duration_minutes' => ['type' => 'integer'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        try {
            $profile = $this->fitnessProfileService->update($user, $arguments);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return [
            'height_cm' => $profile->height_cm !== null ? (float) $profile->height_cm : null,
            'age' => $profile->age,
            'sex' => $profile->sex,
            'fitness_level' => $profile->fitness_level,
            'primary_goal' => $profile->primary_goal,
            'secondary_goal' => $profile->secondary_goal,
            'equipment' => $profile->equipment,
            'preferred_training_days' => $profile->preferred_training_days,
            'preferred_training_duration_minutes' => $profile->preferred_training_duration_minutes,
        ];
    }
}
