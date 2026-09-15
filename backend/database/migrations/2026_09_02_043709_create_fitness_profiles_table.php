<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('sex')->nullable();
            $table->string('fitness_level')->nullable();
            $table->string('primary_goal')->nullable();
            $table->string('secondary_goal')->nullable();
            $table->json('equipment')->nullable();
            $table->json('preferred_training_days')->nullable();
            $table->unsignedSmallInteger('preferred_training_duration_minutes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_profiles');
    }
};
