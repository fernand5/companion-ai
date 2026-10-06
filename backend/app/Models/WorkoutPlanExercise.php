<?php

namespace App\Models;

use App\Support\ExerciseSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected static function booted(): void
    {
        // The slug is what links an exercise to its global demonstration
        // image; keeping it in the model means every creation path (the plan
        // service, weekly adaptation, factories) stays consistent.
        static::saving(function (self $exercise) {
            if ($exercise->isDirty('exercise_name') || $exercise->exercise_slug === null) {
                $exercise->exercise_slug = ExerciseSlug::from($exercise->exercise_name);
            }
        });
    }

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

    public function exerciseImage(): BelongsTo
    {
        return $this->belongsTo(ExerciseImage::class, 'exercise_slug', 'slug');
    }

    /** What the user actually did for this planned exercise (the recorded session exercise, if any). */
    public function performance(): HasOne
    {
        return $this->hasOne(WorkoutExercise::class, 'workout_plan_exercise_id');
    }
}
