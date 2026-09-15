<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Fitness\WeightService;

class GetWeightHistoryTool implements AiTool
{
    public function __construct(private readonly WeightService $weightService) {}

    public function name(): string
    {
        return 'get_weight_history';
    }

    public function description(): string
    {
        return 'Get the history of logged body weight entries, most recent first.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max entries to return, default 30.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $limit = min(max((int) ($arguments['limit'] ?? 30), 1), 100);

        return $this->weightService->history($user, $limit)
            ->map(fn (ActivityLog $log) => [
                'date' => $log->logged_date->toDateString(),
                'weight_kg' => (float) $log->metadata['weight_kg'],
            ])
            ->all();
    }
}
