<?php

namespace App\Models;

use App\Support\ExerciseSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutExercise extends Model
{
    use HasFactory;

    /** The user typed the numbers (per-set entry, or reps/weight via chat/API). */
    public const RECORDED_ENTERED = 'entered';

    /** One-tap "done": the plan's targets, assumed — weak evidence, never measured performance. */
    public const RECORDED_AS_PLANNED = 'as_planned';

    /** Converted from rows that predate set-level tracking. */
    public const RECORDED_MIGRATED = 'migrated';

    protected $fillable = [
        'workout_session_id',
        'workout_plan_exercise_id',
        'exercise_name',
        'exercise_slug',
        'planned_sets',
        'planned_reps',
        'planned_weight_kg',
        'planned_duration_seconds',
        'recorded_as',
        'sets',
        'reps',
        'weight_kg',
        'duration_seconds',
        'notes',
        'position',
    ];

    protected static function booted(): void
    {
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
            'sets' => 'integer',
            'reps' => 'integer',
            'weight_kg' => 'decimal:2',
            'duration_seconds' => 'integer',
            'position' => 'integer',
        ];
    }

    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class);
    }

    public function planExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlanExercise::class, 'workout_plan_exercise_id');
    }

    /** @return HasMany<WorkoutSet, $this> */
    public function performedSets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('set_number');
    }

    /** True when the numbers were entered by the user or converted from genuine logs — not assumed from the plan. */
    public function isMeasured(): bool
    {
        return $this->recorded_as !== self::RECORDED_AS_PLANNED;
    }
}
