<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ActivityLog extends Model
{
    use HasFactory;

    public const TYPE_STEPS = 'steps';

    public const TYPE_TREADMILL = 'treadmill';

    public const TYPE_STRENGTH = 'strength';

    public const TYPE_SPORT = 'sport';

    public const TYPE_RECOVERY = 'recovery';

    public const TYPE_WEIGHT = 'weight';

    protected $fillable = [
        'user_id',
        'type',
        'logged_date',
        'duration_minutes',
        'distance_km',
        'intensity',
        'notes',
        'metadata',
        'workout_session_id',
    ];

    protected function casts(): array
    {
        return [
            'logged_date' => 'date',
            'duration_minutes' => 'integer',
            'distance_km' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class);
    }

    /** The plan whose completion this log mirrors, if it is a plan-completion mirror. */
    public function completedPlan(): HasOne
    {
        return $this->hasOne(WorkoutPlan::class, 'workout_session_id', 'workout_session_id');
    }

    /**
     * True when this row only acknowledges that a PLANNED session was completed
     * (it was written by the plan-completion mirror), as opposed to an activity
     * the user logged themselves. Such a row has no measured duration, distance
     * or effort; any type/intensity on it is a default for the plan's type.
     */
    public function isPlanCompletion(): bool
    {
        return $this->workout_session_id !== null && $this->completedPlan !== null;
    }

    /** Average speed, derived from distance and duration (never stored). */
    public function speedKmh(): ?float
    {
        if (! $this->distance_km || ! $this->duration_minutes) {
            return null;
        }

        return round((float) $this->distance_km / ($this->duration_minutes / 60), 1);
    }

    /** Average pace in seconds per kilometre, derived from distance and duration. */
    public function paceSecondsPerKm(): ?int
    {
        if (! $this->distance_km || ! $this->duration_minutes) {
            return null;
        }

        return (int) round($this->duration_minutes * 60 / (float) $this->distance_km);
    }

    /**
     * Distance implied by the recorded intervals (sum of minutes x speed), if any.
     *
     * NOT authoritative: distance_km is the explicit recorded distance and is
     * never rewritten from this. Interval entries are usually approximate
     * (rounded speeds, warm-ups), so the two can legitimately disagree; this is
     * exposed only so a reader can see that, not to override distance_km.
     */
    public function intervalImpliedDistanceKm(): ?float
    {
        $intervals = $this->metadata['intervals'] ?? null;

        if (! is_array($intervals) || $intervals === []) {
            return null;
        }

        $km = 0.0;

        foreach ($intervals as $interval) {
            if (! is_array($interval) || ! is_numeric($interval['minutes'] ?? null) || ! is_numeric($interval['speed_kmh'] ?? null)) {
                return null;
            }

            $km += (float) $interval['minutes'] * (float) $interval['speed_kmh'] / 60;
        }

        return round($km, 2);
    }
}
