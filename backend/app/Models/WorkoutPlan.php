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

    /** The title the weekly adaptation gives a day it clears: an explicit rest day, not a skipped workout. */
    public const REST_TITLE = 'Rest';

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

    /**
     * A day the plan deliberately leaves for rest (what clearing a day writes), as opposed to a real
     * workout the user marked skipped. Only that exact marker counts: a day with no plan is not a rest day.
     */
    public function isRestDay(): bool
    {
        return $this->activity_type === 'recovery'
            && $this->status === self::STATUS_SKIPPED
            && $this->title === self::REST_TITLE
            && ! $this->exercises()->exists();
    }

    /**
     * True once the user has recorded anything for this day (a completed or
     * partial exercise, or a mirrored session). Such a plan can no longer be
     * replaced: replacing it deletes its exercises, which would orphan the
     * performance and double-count the day when it is completed again.
     */
    public function hasRecordedPerformance(): bool
    {
        return $this->workout_session_id !== null
            || $this->exercises()
                ->whereIn('status', [WorkoutPlanExercise::STATUS_COMPLETED, WorkoutPlanExercise::STATUS_PARTIAL])
                ->exists();
    }

    /**
     * Nothing the user has done is attached to this day, so it may be replaced wholesale if they confirm:
     * not started, not completed or partly done, and no exercise or session recorded against it.
     */
    public function isReplaceable(): bool
    {
        return in_array($this->status, [self::STATUS_PLANNED, self::STATUS_SKIPPED], true) && ! $this->hasRecordedPerformance();
    }

    /**
     * A fingerprint of everything a replacement would overwrite: the plan's own fields and each exercise's
     * target and status. A confirmation is bound to it, so a plan edited, progressed or performed since it
     * was shown no longer matches.
     */
    public function revision(): string
    {
        $exercises = $this->exercises()->get()->map(fn (WorkoutPlanExercise $e) => [
            $e->id, $e->exercise_name, $e->planned_sets, $e->planned_reps,
            $e->planned_weight_kg !== null ? round((float) $e->planned_weight_kg, 2) : null,
            $e->planned_duration_seconds, $e->status,
        ])->all();

        return hash('sha256', (string) json_encode([
            $this->id, $this->planned_date->toDateString(), $this->activity_type, $this->title, $this->status,
            $this->duration_minutes, $this->workout_session_id, $exercises,
        ], JSON_PRESERVE_ZERO_FRACTION));
    }
}
