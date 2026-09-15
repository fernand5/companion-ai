<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            // Snapshot: adherence_pct, planned_workouts_completed/partial/skipped, active_days, due_exercise_count.
            $table->json('stats');
            $table->text('coach_insight');
            $table->text('next_week_focus');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_summaries');
    }
};
