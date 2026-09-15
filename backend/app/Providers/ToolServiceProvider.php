<?php

namespace App\Providers;

use App\Services\Tools\AddTrainingScheduleTool;
use App\Services\Tools\CreateWorkoutPlanTool;
use App\Services\Tools\GetActivityForDateTool;
use App\Services\Tools\GetActivityHistoryTool;
use App\Services\Tools\GetAdherenceSummaryTool;
use App\Services\Tools\GetRecentActivityTool;
use App\Services\Tools\GetRecentWorkoutsTool;
use App\Services\Tools\GetTodayActivityTool;
use App\Services\Tools\GetTodaysPlanTool;
use App\Services\Tools\GetUpcomingScheduleTool;
use App\Services\Tools\GetUserPreferencesTool;
use App\Services\Tools\GetUserProfileTool;
use App\Services\Tools\GetWeeklyTrainingSummaryTool;
use App\Services\Tools\GetWeightHistoryTool;
use App\Services\Tools\LogActivityTool;
use App\Services\Tools\LogWorkoutTool;
use App\Services\Tools\RememberPreferenceTool;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\UpdateExerciseStatusTool;
use App\Services\Tools\UpdateFitnessProfileTool;
use App\Services\Tools\UpdateWeightTool;
use Illuminate\Support\ServiceProvider;

class ToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            return new ToolRegistry([
                $app->make(GetUserProfileTool::class),
                $app->make(GetRecentActivityTool::class),
                $app->make(GetActivityForDateTool::class),
                $app->make(GetActivityHistoryTool::class),
                $app->make(GetUpcomingScheduleTool::class),
                $app->make(GetWeightHistoryTool::class),
                $app->make(GetRecentWorkoutsTool::class),
                $app->make(GetWeeklyTrainingSummaryTool::class),
                $app->make(GetTodayActivityTool::class),
                $app->make(LogActivityTool::class),
                $app->make(LogWorkoutTool::class),
                $app->make(UpdateWeightTool::class),
                $app->make(GetUserPreferencesTool::class),
                $app->make(RememberPreferenceTool::class),
                $app->make(GetTodaysPlanTool::class),
                $app->make(CreateWorkoutPlanTool::class),
                $app->make(UpdateExerciseStatusTool::class),
                $app->make(GetAdherenceSummaryTool::class),
                $app->make(UpdateFitnessProfileTool::class),
                $app->make(AddTrainingScheduleTool::class),
            ]);
        });
    }
}
