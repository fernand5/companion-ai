<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the user wants the coach to sound (style) and how much it should write (detail).
     * Both are NOT NULL with a default, so every existing profile row keeps working and simply
     * reads as "balanced" / "standard" until the user changes it. Additive: nothing is rewritten.
     */
    public function up(): void
    {
        Schema::table('fitness_profiles', function (Blueprint $table) {
            $table->string('coach_style', 20)->default('balanced');
            $table->string('response_detail', 20)->default('standard');
        });
    }

    public function down(): void
    {
        Schema::table('fitness_profiles', function (Blueprint $table) {
            $table->dropColumn(['coach_style', 'response_detail']);
        });
    }
};
