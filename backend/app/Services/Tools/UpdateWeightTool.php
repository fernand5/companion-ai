<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\WeightService;
use Illuminate\Validation\ValidationException;

class UpdateWeightTool implements AiTool
{
    public function __construct(private readonly WeightService $weightService) {}

    public function name(): string
    {
        return 'update_weight';
    }

    public function description(): string
    {
        return 'Log a new body weight measurement in kilograms.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'weight_kg' => ['type' => 'number'],
                'logged_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD of when it actually happened. Defaults to today; must not be in the future.'],
            ],
            'required' => ['weight_kg'],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        try {
            $log = $this->weightService->update($user, $arguments);
        } catch (ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        }

        return [
            'date' => $log->logged_date->toDateString(),
            'weight_kg' => (float) $log->metadata['weight_kg'],
        ];
    }
}
