<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
