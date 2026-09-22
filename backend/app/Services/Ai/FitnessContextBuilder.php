<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Fitness\ActivityService;
use App\Services\Fitness\AdherenceService;
use App\Services\Fitness\RecoveryService;
use App\Services\Fitness\ScheduleService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Memory\MemoryProvider;
use App\Services\Tools\GetRecentActivityTool;
use App\Services\Tools\GetTodaysPlanTool;

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
     *   week_plan: array|null,
     * }
     *
     * $includeWeekPlan/$weekRange are opt-in per caller (not a default part of
     * every context build) — only the flows that actually reason about more
     * than "today" (the weekly-plan adaptation flow, and ordinary chat so it
     * can consider whether a logged activity affects upcoming days) pass
     * these, keeping every other call site's prompt size unchanged.
     */
    public function build(
        User $user,
        string $userMessage,
        ?Conversation $conversation = null,
        bool $includeWeekPlan = false,
        ?array $weekRange = null,
    ): array {
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

        // The user's own local calendar date, not the server's UTC clock —
        // otherwise a user behind UTC would have "today" roll over hours
        // before their actual local midnight, misdating anything logged
        // without an explicit date and confusing today-vs-tomorrow reasoning.
        $today = $user->localToday();
        $todayPlan = $this->workoutPlanService->forDate($user, $today);

        $adherenceSignal = $this->adherenceService->summary(
            $user,
            $user->localNow()->subDays(6)->toDateString(),
            $today,
        );

        $latestRecovery = $this->recoveryService->latest($user);

        $weekPlan = null;
        if ($includeWeekPlan && $weekRange !== null) {
            // A past day's title/reasoning was written by the AI when it was
            // created (e.g. "Soccer Match Tonight") and is never re-labeled
            // once that date passes — without an explicit marker here, that
            // stale wording sits right next to `today` with nothing telling
            // the model the two don't refer to the same day, which invites it
            // to treat "tonight" as still current. relative_to_today makes
            // that unambiguous from the data itself, not from prose alone.
            $weekPlan = $this->workoutPlanService->forRange($user, $weekRange['start'], $weekRange['end'])
                ->map(function (WorkoutPlan $plan) use ($today) {
                    $presented = GetTodaysPlanTool::present($plan);
                    $presented['relative_to_today'] = match (true) {
                        $presented['planned_date'] < $today => 'past',
                        $presented['planned_date'] === $today => 'today',
                        default => 'future',
                    };

                    return $presented;
                })
                ->all();
        }

        return [
            'today' => $today,
            'weekday' => $user->localNow()->format('l'),
            'timezone' => $user->localNow()->getTimezone()->getName(),
            'date_reference' => $this->dateReference($user),
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
            'week_plan' => $weekPlan,
        ];
    }

    /**
     * Resolved calendar dates for the week either side of today, so relative
     * references from the user ("yesterday", "Tuesday", "tomorrow") are looked
     * up rather than derived — models are unreliable at weekday arithmetic,
     * and a wrong date here silently misfiles a logged activity.
     *
     * @return array<int, array{date: string, weekday: string, label: string|null}>
     */
    private function dateReference(User $user): array
    {
        $today = $user->localNow()->startOfDay();
        $labels = [-1 => 'yesterday', 0 => 'today', 1 => 'tomorrow'];

        return array_map(function (int $offset) use ($today, $labels) {
            $day = $today->copy()->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'weekday' => $day->format('l'),
                'label' => $labels[$offset] ?? null,
            ];
        }, range(-7, 7));
    }

    public function toPromptText(array $context): string
    {
        $lines = [];
        $lines[] = "Today's date: {$context['today']} ({$context['weekday']}), user's timezone: {$context['timezone']}";
        $lines[] = 'Date reference — resolve every relative day ("yesterday", "Tuesday", "next Friday") by looking it up here, never by calculating it:';
        $lines[] = implode('; ', array_map(
            fn (array $d) => "{$d['weekday']} {$d['date']}".($d['label'] ? " ({$d['label']})" : ''),
            $context['date_reference'],
        ));

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

        if ($context['week_plan'] !== null) {
            $lines[] = '';
            $lines[] = "## This week's plan (Mon-Sun)";
            $lines[] = $context['week_plan']
                ? json_encode($context['week_plan'])
                    .' — each entry\'s relative_to_today is "past", "today", or "future". A "past" entry\'s '
                    .'title/reasoning was written when it was created and may say "tonight"/"today" even though '
                    .'that date has since passed — treat it as historical record only, never as describing '
                    .'anything happening today or later.'
                : 'Nothing planned yet this week.';
        }

        $lines[] = '';
        $lines[] = 'Use the available tools to fetch anything above that is missing or to look further back in history.';

        return implode("\n", $lines);
    }
}
