<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single, global demonstration image for one exercise (identified by its
 * normalized name). Stored in object storage; only the reference lives here.
 *
 * Generated images are not human-reviewed: treat `ready` as "generated", and
 * review them before considering them production quality.
 */
class ExerciseImage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** A failed generation may be retried this many times in total. */
    public const MAX_ATTEMPTS = 3;

    /** Minimum wait before retrying a failed generation. */
    public const RETRY_AFTER_MINUTES = 5;

    /** A `generating` claim older than this is presumed dead (killed worker, spin-down). */
    public const STALE_AFTER_MINUTES = 3;

    protected $fillable = [
        'slug',
        'name',
        'status',
        'image_url',
        'object_path',
        'attempts',
        'generating_started_at',
        'failed_at',
        'generated_at',
        'last_error',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'generating_started_at' => 'datetime',
            'failed_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->image_url !== null;
    }
}
