<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plan_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_plan_id')->constrained()->cascadeOnDelete();
            $table->string('exercise_name');
            $table->unsignedSmallInteger('planned_sets')->nullable();
            $table->unsignedSmallInteger('planned_reps')->nullable();
            $table->decimal('planned_weight_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('planned_duration_seconds')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // pending | completed | partial | skipped
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('actual_sets')->nullable();
            $table->unsignedSmallInteger('actual_reps')->nullable();
            $table->decimal('actual_weight_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('actual_duration_seconds')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workout_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_plan_exercises');
    }
};
