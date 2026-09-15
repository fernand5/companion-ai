<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\AdherenceService;
use App\Services\Fitness\RecoveryService;
use App\Services\Fitness\ScheduleService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Memory\MemoryProvider;
use App\Services\Tools\GetRecentActivityTool;
use App\Services\Tools\GetTodaysPlanTool;
use Carbon\Carbon;

/**
 * Assembles a compact, selective context block for the AI coach — never a raw
 * dump of the database or full conversation history (spec §13). The model can
 * still call tools to drill into anything not included here.
 */
class FitnessContextBuilder
{
    public function __construct(
        private readonly ActivityService $activityService,
        private readonly ScheduleService $scheduleService,
        private readonly MemoryProvider $memoryProvider,
        private readonly WorkoutPlanService $workoutPlanService,
        private readonly AdherenceService $adherenceService,
        private readonly RecoveryService $recoveryService,
    ) {}

    /**
     * @return array{
     *   today: string,
     *   profile: array|null,
     *   recent_activity: array,
     *   today_activity: array,
     *   upcoming_schedule: array,
     *   memories: array,
     *   today_plan: array|null,
     *   adherence_signal: array,
     *   latest_recovery: array|null,
     * }
     */
    public function build(User $user, string $userMessage, ?Conversation $conversation = null): array
    {
        $profile = $user->fitnessProfile;

        $recentActivity = $this->activityService->recent($user, 10)
            ->map(fn (ActivityLog $log) => GetRecentActivityTool::present($log))
            ->all();

        $todayActivity = $this->activityService->today($user)
            ->map(fn (ActivityLog $log) => GetRecentActivityTool::present($log))
            ->all();

        $upcomingSchedule = $this->scheduleService->upcoming($user, 5)
            ->map(fn (array $occurrence) => [
                'date' => $occurrence['date'],
                'activity_type' => $occurrence['schedule']->activity_type,
                'start_time' => $occurrence['schedule']->start_time,
                'intensity' => $occurrence['schedule']->intensity,
            ])
            ->all();

        $memories = $this->memoryProvider->recall($user, $userMessage, (int) config('memory.recall_limit', 5));

        $today = Carbon::today()->toDateString();
        $todayPlan = $this->workoutPlanService->forDate($user, $today);

        $adherenceSignal = $this->adherenceService->summary(
            $user,
            Carbon::today()->subDays(6)->toDateString(),
            $today,
        );

        $latestRecovery = $this->recoveryService->latest($user);

        return [
            'today' => $today,
            'profile' => $profile ? [
                'height_cm' => $profile->height_cm !== null ? (float) $profile->height_cm : null,
                'weight_kg' => $profile->weight_kg !== null ? (float) $profile->weight_kg : null,
                'fitness_level' => $profile->fitness_level,
                'primary_goal' => $profile->primary_goal,
                'secondary_goal' => $profile->secondary_goal,
                'equipment' => $profile->equipment,
                'preferred_training_duration_minutes' => $profile->preferred_training_duration_minutes,
            ] : null,
            'recent_activity' => $recentActivity,
            'today_activity' => $todayActivity,
            'upcoming_schedule' => $upcomingSchedule,
            'memories' => $memories,
            'today_plan' => $todayPlan ? GetTodaysPlanTool::present($todayPlan) : null,
            'adherence_signal' => $adherenceSignal,
            'latest_recovery' => $latestRecovery ? [
                'checkin_date' => $latestRecovery->checkin_date->toDateString(),
                'energy' => $latestRecovery->energy,
                'soreness' => $latestRecovery->soreness,
                'motivation' => $latestRecovery->motivation,
                'perceived_difficulty' => $latestRecovery->perceived_difficulty,
                'pain_notes' => $latestRecovery->pain_notes,
            ] : null,
        ];
    }

    public function toPromptText(array $context): string
    {
        $lines = [];
        $lines[] = "Today's date: {$context['today']}";

        $lines[] = '';
        $lines[] = '## User profile';
        $lines[] = $context['profile'] ? json_encode($context['profile']) : 'No profile set up yet.';

        $lines[] = '';
        $lines[] = "## Today's plan";
        $lines[] = $context['today_plan'] ? json_encode($context['today_plan']) : 'No plan created for today yet.';

        $lines[] = '';
        $lines[] = "## Today's activity so far";
        $lines[] = $context['today_activity'] ? json_encode($context['today_activity']) : 'Nothing logged today yet.';

        $lines[] = '';
        $lines[] = '## Recent activity (last ~10 days)';
        $lines[] = $context['recent_activity'] ? json_encode($context['recent_activity']) : 'No recent activity logged.';

        $lines[] = '';
        $lines[] = '## Upcoming schedule (next 5 days)';
        $lines[] = $context['upcoming_schedule'] ? json_encode($context['upcoming_schedule']) : 'Nothing scheduled.';

        $lines[] = '';
        $lines[] = '## Relevant long-term memories';
        $lines[] = $context['memories'] ? json_encode($context['memories']) : 'None yet.';

        $lines[] = '';
        $lines[] = '## Adherence signal (last 7 days)';
        $lines[] = json_encode($context['adherence_signal']);

        $lines[] = '';
        $lines[] = '## Latest recovery check-in';
        $lines[] = $context['latest_recovery'] ? json_encode($context['latest_recovery']) : 'No check-in recorded yet.';

        $lines[] = '';
        $lines[] = 'Use the available tools to fetch anything above that is missing or to look further back in history.';

        return implode("\n", $lines);
    }
}
