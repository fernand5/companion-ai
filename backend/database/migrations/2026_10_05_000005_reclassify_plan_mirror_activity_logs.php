<?php

use App\Models\ActivityLog;
use App\Support\PlanMirrorClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completed recovery/mobility (and other non-strength) plans were mirrored onto
 * the activity timeline as high-intensity strength, which distorts any load
 * reasoning. Re-label those mirror rows from the plan's own activity_type.
 *
 * Narrow on purpose: only activity_logs rows that are the mirror of a session
 * linked to a plan whose type is not strength, and only while they still carry
 * the old strength label. Nothing else on the log (duration, distance, notes,
 * metadata) and none of the exercises/sets are touched. Idempotent. A mirror
 * whose plan no longer exists cannot be classified and is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workout_plans')
            ->whereNotNull('workout_session_id')
            ->where('activity_type', '!=', ActivityLog::TYPE_STRENGTH)
            ->orderBy('id')
            ->each(function ($plan) {
                $profile = PlanMirrorClassification::for($plan->activity_type);

                DB::table('activity_logs')
                    ->where('workout_session_id', $plan->workout_session_id)
                    ->where('type', ActivityLog::TYPE_STRENGTH)
                    ->update(['type' => $profile['type'], 'intensity' => $profile['intensity']]);
            });
    }

    public function down(): void
    {
        // The previous (incorrect) labels are not worth restoring.
    }
};
