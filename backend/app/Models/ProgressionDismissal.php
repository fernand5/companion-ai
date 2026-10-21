<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's "keep current target" for one progression recommendation on one upcoming plan row.
 * Only server code writes it (see ProgressionApprovalService); it is never filled from a request.
 */
class ProgressionDismissal extends Model
{
    protected $fillable = ['user_id', 'workout_plan_exercise_id', 'exercise_slug', 'fingerprint', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlanExercise::class, 'workout_plan_exercise_id');
    }
}
