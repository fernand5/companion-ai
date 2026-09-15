<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Fitness\ActivityService;

class GetWeeklyTrainingSummaryTool implements AiTool
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function name(): string
    {
        return 'get_weekly_training_summary';
    }

    public function description(): string
    {
        return 'Get an aggregate summary of this week (Mon-Sun) of training: session count, total duration, breakdown by type, total steps.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, User $user): mixed
    {
        return $this->activityService->weeklySummary($user);
    }
}
