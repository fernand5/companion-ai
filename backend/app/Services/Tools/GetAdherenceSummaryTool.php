<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\AdherenceService;

class GetAdherenceSummaryTool implements AiTool
{
    public function __construct(private readonly AdherenceService $adherenceService) {}

    public function name(): string
    {
        return 'get_adherence_summary';
    }

    public function description(): string
    {
        return 'Get real, computed adherence and active-days numbers for a period. Use this for any weekly '
            .'recap or consistency question — never estimate or invent an adherence number or behavioral pattern.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'weeks' => ['type' => 'integer', 'description' => 'How many weeks back to include, default 1.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user): mixed
    {
        $weeks = min(max((int) ($arguments['weeks'] ?? 1), 1), 12);
        $today = $user->localNow();

        return $this->adherenceService->summary(
            $user,
            $today->copy()->subWeeks($weeks)->addDay()->toDateString(),
            $today->toDateString(),
        );
    }
}
