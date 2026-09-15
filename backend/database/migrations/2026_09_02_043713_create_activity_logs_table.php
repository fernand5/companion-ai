<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // steps | treadmill | strength | sport | recovery
            $table->string('type');
            $table->date('logged_date');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('intensity')->nullable();
            $table->text('notes')->nullable();
            // Type-specific fields: steps count, treadmill speed/incline/distance/intervals,
            // sport name, recovery energy/soreness/sleep_hours.
            $table->json('metadata')->nullable();
            $table->foreignId('workout_session_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'logged_date']);
            $table->index(['user_id', 'type', 'logged_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
