<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('planned_date');
            // steps | treadmill | strength | sport | recovery
            $table->string('activity_type');
            $table->string('title');
            // ai | manual | schedule
            $table->string('source')->default('ai');
            // planned | in_progress | completed | partial | skipped
            $table->string('status')->default('planned');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->text('reasoning')->nullable();
            // string[] — short, concrete "why this changed" bullets
            $table->json('reasoning_factors')->nullable();
            $table->foreignId('training_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workout_session_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'planned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_plans');
    }
};
