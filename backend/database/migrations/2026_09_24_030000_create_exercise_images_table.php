<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_images', function (Blueprint $table) {
            $table->id();
            // Global to the exercise (never per user): the normalized name.
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            // pending -> generating -> ready | failed
            $table->string('status', 20)->default('pending')->index();
            $table->text('image_url')->nullable();
            $table->string('object_path')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('generating_started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            // A short machine code only — never provider error text.
            $table->string('last_error', 40)->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_images');
    }
};
