<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Which workout sessions count as strength work, for strength-performance
 * history.
 *
 * The plan a session came from is the only reliable classifier we have: a
 * session linked to a plan whose activity_type is anything but strength
 * (recovery/mobility, treadmill, sport, steps) is not strength work. Sessions
 * with no plan (freeform strength logging) and legacy sessions whose plan is
 * gone cannot be classified and are kept, because strength is what freeform
 * logging records.
 *
 * KNOWN LIMITATION: classification is per session, not per exercise. A cardio
 * or mobility exercise inside a plan that is itself typed strength (e.g.
 * "Treadmill warm-up" in a lower-body day) cannot be told apart without
 * guessing from its name, which this deliberately does not do. Such rows stay.
 *
 * Read-only: it only narrows a query; no record is changed or deleted.
 */
final class StrengthSessions
{
    /**
     * Narrow a query that already includes the workout_sessions table (as the
     * FROM or a JOIN) to strength sessions.
     *
     * @param  EloquentBuilder<*>|Builder  $query
     * @return EloquentBuilder<*>|Builder
     */
    public static function only(EloquentBuilder|Builder $query): EloquentBuilder|Builder
    {
        return $query->whereNotExists(fn (Builder $plan) => $plan
            ->selectRaw('1')
            ->from('workout_plans')
            ->whereColumn('workout_plans.workout_session_id', 'workout_sessions.id')
            ->where('workout_plans.activity_type', '!=', ActivityLog::TYPE_STRENGTH));
    }
}
