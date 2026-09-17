<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable, IANA identifier (e.g. "America/Bogota"). Null means we
            // don't know it yet — every "today"/"now" computation falls back
            // to config('app.timezone') (UTC) in that case, matching prior
            // behavior. Captured from the browser at login/register and
            // refreshed on every login so it self-corrects if the user
            // travels, without needing per-request timezone headers.
            $table->string('timezone')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
