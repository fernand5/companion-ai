<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FitnessProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'height_cm',
        'weight_kg',
        'age',
        'sex',
        'fitness_level',
        'primary_goal',
        'secondary_goal',
        'equipment',
        'preferred_training_days',
        'preferred_training_duration_minutes',
        'coach_style',
        'response_detail',
    ];

    /** What a freshly created row holds before the user chooses (matches the column defaults). */
    protected $attributes = [
        'coach_style' => 'balanced',
        'response_detail' => 'standard',
    ];

    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
            'age' => 'integer',
            'equipment' => 'array',
            'preferred_training_days' => 'array',
            'preferred_training_duration_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
