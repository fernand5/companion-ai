<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'week_start',
        'stats',
        'coach_insight',
        'next_week_focus',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'stats' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
