<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutPlan extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_PARTIAL,
        self::STATUS_SKIPPED,
    ];

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SCHEDULE = 'schedule';

    public const SOURCES = [self::SOURCE_AI, self::SOURCE_MANUAL, self::SOURCE_SCHEDULE];

    protected $fillable = [
        'user_id',
        'planned_date',
        'activity_type',
        'title',
        'source',
        'status',
        'duration_minutes',
        'reasoning',
        'reasoning_factors',
        'training_schedule_id',
        'workout_session_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'duration_minutes' => 'integer',
            'reasoning_factors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainingSchedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class);
    }

    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutPlanExercise::class)->orderBy('position');
    }
}
