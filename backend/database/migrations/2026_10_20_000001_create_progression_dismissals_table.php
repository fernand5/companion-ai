<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Keep current target": the user's durable answer to ONE progression recommendation for ONE
        // upcoming plan row. It matches only while the recommendation is still exactly the one declined
        // (the fingerprint covers the row, its current load and the evidence behind the recommendation);
        // when any of that changes it simply stops matching, so nothing is suppressed for good. It
        // never touches the plan. It goes with the plan row (a replaced plan) and with the user.
        Schema::create('progression_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_plan_exercise_id')->constrained()->cascadeOnDelete();
            $table->string('exercise_slug', 100);
            $table->string('fingerprint', 64);
            $table->timestamp('dismissed_at');
            $table->timestamps();

            // One answer per row: repeated or simultaneous declines land on the same record.
            $table->unique(['user_id', 'workout_plan_exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progression_dismissals');
    }
};
