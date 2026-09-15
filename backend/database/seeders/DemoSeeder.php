<?php

namespace Database\Seeders;

use App\Models\Message;
use App\Models\User;
use App\Services\Fitness\RecoveryService;
use App\Services\Fitness\WorkoutPlanService;
use App\Services\Fitness\WorkoutService;
use App\Services\Memory\MemoryProvider;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => Hash::make('password')],
        );

        $user->fitnessProfile()->updateOrCreate(['user_id' => $user->id], [
            'height_cm' => 168,
            'weight_kg' => 86.5,
            'age' => 32,
            'sex' => 'male',
            'fitness_level' => 'intermediate',
            'primary_goal' => 'Lose body fat while maintaining muscle',
            'secondary_goal' => 'Improve 5k run time',
            'equipment' => ['dumbbells', 'exercise mat', 'treadmill'],
            'preferred_training_days' => [1, 2, 3, 4],
            'preferred_training_duration_minutes' => 45,
        ]);

        $user->trainingSchedules()->delete();
        $user->trainingSchedules()->createMany([
            ['activity_type' => 'Football', 'day_of_week' => 2, 'start_time' => '20:00', 'expected_duration_minutes' => 90, 'intensity' => 'high'],
            ['activity_type' => 'Football', 'day_of_week' => 4, 'start_time' => '20:00', 'expected_duration_minutes' => 90, 'intensity' => 'high'],
        ]);

        $user->activityLogs()->delete();
        $user->workoutSessions()->delete();

        // 3 days ago: heavy strength session ("10 rounds of Cindy").
        app(WorkoutService::class)->log($user, [
            'logged_date' => Carbon::today()->subDays(3)->toDateString(),
            'duration_minutes' => 40,
            'notes' => '10 rounds of Cindy',
            'exercises' => [
                ['exercise_name' => 'Pull-ups', 'sets' => 10, 'reps' => 5],
                ['exercise_name' => 'Push-ups', 'sets' => 10, 'reps' => 10],
                ['exercise_name' => 'Air Squats', 'sets' => 10, 'reps' => 15],
            ],
        ]);

        // 2 days ago: moderate treadmill cardio.
        $user->activityLogs()->create([
            'type' => 'treadmill',
            'logged_date' => Carbon::today()->subDays(2)->toDateString(),
            'duration_minutes' => 17,
            'intensity' => 'moderate',
            'notes' => '17 minutes on the treadmill',
            'metadata' => [
                'distance_km' => 2.6,
                'intervals' => [
                    ['minutes' => 12, 'speed_kmh' => 9],
                    ['minutes' => 5, 'speed_kmh' => 5.5],
                ],
            ],
        ]);

        // Yesterday: football match.
        $user->activityLogs()->create([
            'type' => 'sport',
            'logged_date' => Carbon::today()->subDay()->toDateString(),
            'duration_minutes' => 90,
            'intensity' => 'high',
            'notes' => 'Football match',
            'metadata' => ['sport' => 'Football', 'format' => 'match'],
        ]);

        $user->activityLogs()->create([
            'type' => 'weight',
            'logged_date' => Carbon::today()->subDays(10)->toDateString(),
            'metadata' => ['weight_kg' => 88.0],
        ]);
        $user->activityLogs()->create([
            'type' => 'weight',
            'logged_date' => Carbon::today()->subDays(3)->toDateString(),
            'metadata' => ['weight_kg' => 87.0],
        ]);
        $user->activityLogs()->create([
            'type' => 'weight',
            'logged_date' => Carbon::today()->toDateString(),
            'metadata' => ['weight_kg' => 86.5],
        ]);

        $this->seedWorkoutPlansAndRecovery($user);

        $memoryProvider = app(MemoryProvider::class);
        $user->fitnessMemories()->delete();
        $memoryProvider->remember($user, 'Prefers short workouts on football days.', ['category' => 'preference']);
        $memoryProvider->remember($user, 'Enjoys Cindy but should not perform it before football.', ['category' => 'preference']);
        $memoryProvider->remember($user, 'Prefers training at home.', ['category' => 'preference']);
        $memoryProvider->remember($user, 'Tends to train in the evening.', ['category' => 'preference']);

        $user->conversations()->delete();
        $conversation = $user->conversations()->create(['title' => 'This week']);

        $this->seedMessage($conversation, Message::ROLE_USER, 'I completed my strength workout — 10 rounds of Cindy.', Carbon::today()->subDays(3));
        $this->seedMessage($conversation, Message::ROLE_ASSISTANT, "Nice work — Cindy is a demanding session. Recover well tonight; let's ease into cardio tomorrow instead of another heavy day.", Carbon::today()->subDays(3));
        $this->seedMessage($conversation, Message::ROLE_USER, 'Did 17 minutes on the treadmill today.', Carbon::today()->subDays(2));
        $this->seedMessage($conversation, Message::ROLE_ASSISTANT, "Good call keeping it moderate after Cindy. You've got football Tuesday and Thursday — I'll keep tomorrow light so you're fresh for it.", Carbon::today()->subDays(2));
        $this->seedMessage($conversation, Message::ROLE_USER, 'Football went well tonight, we won 3-1.', Carbon::today()->subDay());
        $this->seedMessage($conversation, Message::ROLE_ASSISTANT, 'Great result! That was a high-intensity session on top of your strength day and cardio — plan on recovery or light activity today.', Carbon::today()->subDay());

        // Today is left open on purpose: ask the coach "What should I do today?"
        // in the app to see it adapt live to the strength/cardio/football history
        // above and the upcoming Tue/Thu football schedule.
    }

    private function seedMessage($conversation, string $role, string $content, Carbon $date): void
    {
        $message = $conversation->messages()->create(['role' => $role, 'content' => $content]);
        $message->forceFill(['created_at' => $date, 'updated_at' => $date])->save();
    }

    /**
     * Demonstrates the adaptive-coach iteration: completed/partial/skipped
     * plan histories (so the adherence chart has real, non-trivial numbers
     * across a few weeks), a skipped plan that coexists with a real logged
     * substitute activity (the "soccer instead of the planned gym session"
     * scenario), a recovery check-in, and today's plan left open so a live
     * demo can mark exercises done and ask the coach to adapt.
     */
    private function seedWorkoutPlansAndRecovery(User $user): void
    {
        $user->workoutPlans()->delete();
        $user->recoveryCheckins()->delete();

        $planService = app(WorkoutPlanService::class);

        // Two weeks ago: a fully completed session.
        $twoWeeksAgo = $planService->createOrReplace($user, [
            'planned_date' => Carbon::today()->subDays(13)->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Full Body Strength',
            'source' => 'ai',
            'exercises' => [
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell Row', 'planned_sets' => 3, 'planned_reps' => 12],
            ],
        ]);
        foreach ($twoWeeksAgo->exercises as $exercise) {
            $planService->updateExerciseStatus($user, $exercise, ['status' => 'completed']);
        }

        // Last week: another fully completed session.
        $lastWeek = $planService->createOrReplace($user, [
            'planned_date' => Carbon::today()->subDays(6)->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Full Body Strength',
            'source' => 'ai',
            'exercises' => [
                ['exercise_name' => 'Goblet Squat', 'planned_sets' => 3, 'planned_reps' => 12],
                ['exercise_name' => 'Dumbbell Row', 'planned_sets' => 3, 'planned_reps' => 12],
            ],
        ]);
        foreach ($lastWeek->exercises as $exercise) {
            $planService->updateExerciseStatus($user, $exercise, ['status' => 'completed']);
        }

        // Last week: a partially completed session (one exercise skipped).
        $partial = $planService->createOrReplace($user, [
            'planned_date' => Carbon::today()->subDays(5)->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Upper Body',
            'source' => 'ai',
            'exercises' => [
                ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Shoulder Press', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Lateral Raise', 'planned_sets' => 3, 'planned_reps' => 15],
            ],
        ]);
        $planService->updateExerciseStatus($user, $partial->exercises[0], ['status' => 'completed']);
        $planService->updateExerciseStatus($user, $partial->exercises[1], ['status' => 'completed']);
        $planService->updateExerciseStatus($user, $partial->exercises[2], ['status' => 'skipped']);

        // Yesterday: the planned leg day was skipped — but a real football
        // match was logged that same day (see the activity_logs entry above).
        // This is the "planned vs actual" scenario: a skipped plan does not
        // mean zero activity, and active_days counts the real logged sport.
        $skipped = $planService->createOrReplace($user, [
            'planned_date' => Carbon::today()->subDay()->toDateString(),
            'activity_type' => 'strength',
            'title' => 'Leg Day',
            'source' => 'schedule',
            'exercises' => [
                ['exercise_name' => 'Back Squat', 'planned_sets' => 4, 'planned_reps' => 8],
                ['exercise_name' => 'Walking Lunge', 'planned_sets' => 3, 'planned_reps' => 12],
            ],
        ]);
        foreach ($skipped->exercises as $exercise) {
            $planService->updateExerciseStatus($user, $exercise, ['status' => 'skipped']);
        }

        // Today: left open on purpose, exactly like the conversation below —
        // ask the coach "What should I do today?" to see it author this kind
        // of plan live, or mark these exercises done/partial/skipped yourself.
        $planService->createOrReplace($user, [
            'activity_type' => 'strength',
            'title' => 'Upper Body Strength',
            'source' => 'ai',
            'duration_minutes' => 40,
            'reasoning' => 'Keeping today moderate and upper-body focused so your legs stay fresh for football tomorrow.',
            'reasoning_factors' => ['Football scheduled tomorrow', 'Legs worked hard recently'],
            'exercises' => [
                ['exercise_name' => 'Bench Press', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Bent-Over Row', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Shoulder Press', 'planned_sets' => 3, 'planned_reps' => 10],
                ['exercise_name' => 'Plank', 'planned_sets' => 3, 'planned_reps' => 1],
            ],
        ]);

        app(RecoveryService::class)->upsert($user, [
            'energy' => 4,
            'soreness' => 2,
            'motivation' => 4,
        ]);
    }
}
