<?php

use App\Support\LegacyPerformanceBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LegacyPerformanceBackfill::run();
    }

    // Data-only; nothing to undo (the columns it fills are dropped by the earlier migrations' down()).
    public function down(): void {}
};
