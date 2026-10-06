<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            // Identity for per-exercise history (same normalization as plan exercises).
            $table->string('exercise_slug', 100)->nullable()->after('exercise_name')->index();

            // Which plan row this performance answers, when it came from a plan. Plan
            // exercises are deleted/recreated on replacement, so this can go null.
            $table->foreignId('workout_plan_exercise_id')->nullable()->after('workout_session_id')
                ->constrained('workout_plan_exercises')->nullOnDelete();

            // The target at the moment of logging. Planned and actual stay separate,
            // and this snapshot keeps the comparison valid even if the plan later changes.
            $table->unsignedSmallInteger('planned_sets')->nullable();
            $table->unsignedSmallInteger('planned_reps')->nullable();
            $table->decimal('planned_weight_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('planned_duration_seconds')->nullable();

            // entered | as_planned | migrated. NULL only on rows that predate this column.
            $table->string('recorded_as', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workout_plan_exercise_id');
            $table->dropIndex(['exercise_slug']);
            $table->dropColumn([
                'exercise_slug', 'planned_sets', 'planned_reps', 'planned_weight_kg',
                'planned_duration_seconds', 'recorded_as',
            ]);
        });
    }
};
