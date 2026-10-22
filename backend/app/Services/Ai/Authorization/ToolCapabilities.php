<?php

namespace App\Services\Ai\Authorization;

/**
 * The capability of every AI tool, declared in one place and checked by a test
 * against the registry, so a new tool cannot be added without deciding what it
 * may do. A tool that is missing here is treated as the most restrictive plan
 * capability: it can only run when the user has asked for a plan change.
 */
final class ToolCapabilities
{
    private const MAP = [
        'get_user_profile' => ToolCapability::Read,
        'get_recent_activity' => ToolCapability::Read,
        'get_activity_for_date' => ToolCapability::Read,
        'get_activity_history' => ToolCapability::Read,
        'get_upcoming_schedule' => ToolCapability::Read,
        'get_weight_history' => ToolCapability::Read,
        'get_recent_workouts' => ToolCapability::Read,
        'get_exercise_history' => ToolCapability::Read,
        'get_weekly_training_summary' => ToolCapability::Read,
        'get_today_activity' => ToolCapability::Read,
        'get_user_preferences' => ToolCapability::Read,
        'get_todays_plan' => ToolCapability::Read,
        'get_adherence_summary' => ToolCapability::Read,
        'get_progression_recommendation' => ToolCapability::Read,

        'log_activity' => ToolCapability::Record,
        'log_workout' => ToolCapability::Record,
        'update_weight' => ToolCapability::Record,
        'remember_preference' => ToolCapability::Record,
        'update_fitness_profile' => ToolCapability::Record,
        'add_training_schedule_entry' => ToolCapability::Record,

        'update_exercise_status' => ToolCapability::PlanProgress,
        'create_workout_plan' => ToolCapability::PlanCreate,
        'propose_weekly_plan_changes' => ToolCapability::PlanAdapt,
    ];

    public static function for(string $toolName): ToolCapability
    {
        return self::MAP[$toolName] ?? ToolCapability::PlanCreate;
    }

    public static function isDeclared(string $toolName): bool
    {
        return isset(self::MAP[$toolName]);
    }

    /** @return array<string, ToolCapability> */
    public static function all(): array
    {
        return self::MAP;
    }
}
