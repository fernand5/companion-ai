<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutPlanExercise extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_PARTIAL,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'workout_plan_id',
        'exercise_name',
        'planned_sets',
        'planned_reps',
        'planned_weight_kg',
        'planned_duration_seconds',
        'position',
        'status',
        'actual_sets',
        'actual_reps',
        'actual_weight_kg',
        'actual_duration_seconds',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_sets' => 'integer',
            'planned_reps' => 'integer',
            'planned_weight_kg' => 'decimal:2',
            'planned_duration_seconds' => 'integer',
            'position' => 'integer',
            'actual_sets' => 'integer',
            'actual_reps' => 'integer',
            'actual_weight_kg' => 'decimal:2',
            'actual_duration_seconds' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function workoutPlan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class);
    }
}
