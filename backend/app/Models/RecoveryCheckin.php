<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecoveryCheckin extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'checkin_date',
        'energy',
        'soreness',
        'motivation',
        'perceived_difficulty',
        'pain_notes',
    ];

    protected function casts(): array
    {
        return [
            'checkin_date' => 'date',
            'energy' => 'integer',
            'soreness' => 'integer',
            'motivation' => 'integer',
            'perceived_difficulty' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
