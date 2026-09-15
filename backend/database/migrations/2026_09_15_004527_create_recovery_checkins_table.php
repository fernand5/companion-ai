<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checkin_date');
            $table->unsignedTinyInteger('energy');
            $table->unsignedTinyInteger('soreness');
            $table->unsignedTinyInteger('motivation');
            $table->unsignedTinyInteger('perceived_difficulty')->nullable();
            $table->text('pain_notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'checkin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_checkins');
    }
};
