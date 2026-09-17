<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdherenceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\RecoveryCheckinController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\WeeklyPlanController;
use App\Http\Controllers\Api\WeightController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutPlanController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::post('/schedule', [ScheduleController::class, 'store']);
    Route::put('/schedule/{schedule}', [ScheduleController::class, 'update']);
    Route::delete('/schedule/{schedule}', [ScheduleController::class, 'destroy']);

    Route::get('/activity', [ActivityController::class, 'index']);
    Route::post('/activity', [ActivityController::class, 'store']);
    Route::get('/activity/{activity}', [ActivityController::class, 'show']);
    Route::put('/activity/{activity}', [ActivityController::class, 'update']);
    Route::delete('/activity/{activity}', [ActivityController::class, 'destroy']);

    Route::get('/workouts', [WorkoutController::class, 'index']);
    Route::post('/workouts', [WorkoutController::class, 'store']);

    Route::get('/weight', [WeightController::class, 'index']);
    Route::post('/weight', [WeightController::class, 'store']);

    Route::get('/workout-plans', [WorkoutPlanController::class, 'index']);
    Route::post('/workout-plans', [WorkoutPlanController::class, 'store']);
    Route::patch('/workout-plans/{plan}/exercises/{exercise}', [WorkoutPlanController::class, 'updateExercise']);

    Route::get('/recovery-checkins', [RecoveryCheckinController::class, 'index']);
    Route::post('/recovery-checkins', [RecoveryCheckinController::class, 'store']);

    // Same shared-AI-capacity reasoning as the chat message throttle below —
    // each call can trigger several sequential Gemini calls.
    Route::post('/weekly-plan/generate', [WeeklyPlanController::class, 'generate'])->middleware('throttle:10,1');
    Route::post('/weekly-plan/adapt', [WeeklyPlanController::class, 'adapt'])->middleware('throttle:10,1');

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    // Each message can trigger several sequential Gemini calls (the tool-calling
    // loop), and the AI provider's free-tier request capacity is shared across
    // every beta user — this caps a single confused/retrying user from being
    // able to exhaust it for everyone else.
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])
        ->middleware('throttle:10,1');

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/progress', [ProgressController::class, 'index']);
    Route::get('/adherence', [AdherenceController::class, 'index']);
    Route::get('/coach/weekly-summary', [CoachController::class, 'weeklySummary']);
});
