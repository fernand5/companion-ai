<?php

namespace App\Services\Fitness;

use App\Models\ExerciseImage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\ImageGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lazily generates ONE global image per exercise: Gemini -> object storage ->
 * a reference row. No queue or worker exists in this app, so a request that
 * wins the atomic claim generates the image after its response has been sent
 * (dispatch()->afterResponse()); everyone else just sees "generating".
 */
class ExerciseImageService
{
    public const DISK = 'exercise_images';

    public function __construct(private readonly ImageGenerator $generator) {}

    /**
     * Return the image record for this exercise, starting generation if it is
     * needed and nobody else already has. Never generates in the caller's
     * request and never throws for provider/storage problems.
     */
    public function request(string $slug, string $name): ExerciseImage
    {
        $image = $this->findOrCreate($slug, $name);

        if ($image->isReady()) {
            return $image;
        }

        $this->failIfStuck($image);

        if ($this->claim($image)) {
            Log::info('exercise_image.claimed', ['exercise_image_id' => $image->id, 'slug' => $image->slug]);

            $id = $image->id;
            dispatch(fn () => app(self::class)->generate($id))->afterResponse();
        } else {
            Log::info('exercise_image.busy', ['exercise_image_id' => $image->id, 'slug' => $image->slug]);
        }

        return $image->refresh();
    }

    /**
     * Atomically take ownership of generation. One conditional UPDATE decides
     * the winner (works on MySQL and SQLite, no external lock): exactly one
     * caller sees an affected-row count of 1. A claim is also possible on a
     * failed image after its cooldown, or on a `generating` row whose worker
     * evidently died — so an image can never stay stuck.
     */
    public function claim(ExerciseImage $image): bool
    {
        $affected = ExerciseImage::query()
            ->whereKey($image->id)
            ->where(function ($q) {
                $q->where('status', ExerciseImage::STATUS_PENDING)
                    ->orWhere(fn ($q) => $q
                        ->where('status', ExerciseImage::STATUS_FAILED)
                        ->where('attempts', '<', ExerciseImage::MAX_ATTEMPTS)
                        ->where('failed_at', '<=', now()->subMinutes(ExerciseImage::RETRY_AFTER_MINUTES)))
                    ->orWhere(fn ($q) => $q
                        ->where('status', ExerciseImage::STATUS_GENERATING)
                        ->where('attempts', '<', ExerciseImage::MAX_ATTEMPTS)
                        ->where('generating_started_at', '<=', now()->subMinutes(ExerciseImage::STALE_AFTER_MINUTES)));
            })
            ->update([
                'status' => ExerciseImage::STATUS_GENERATING,
                'generating_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);

        return $affected === 1;
    }

    /**
     * Runs after the response. Every outcome is recorded on the row; nothing is
     * rethrown, since there is no user request left to fail.
     */
    public function generate(int $imageId): void
    {
        $image = ExerciseImage::find($imageId);

        if (! $image || $image->status !== ExerciseImage::STATUS_GENERATING) {
            return;
        }

        set_time_limit(120);
        $startedAt = microtime(true);

        // Check storage BEFORE paying for an image we could not keep.
        if (! $this->storageConfigured()) {
            $this->fail($image, 'not_configured', null, $startedAt);

            return;
        }

        try {
            $generated = $this->generator->generate(ExerciseImagePrompt::for($image->name));
        } catch (AiProviderException $e) {
            $this->fail($image, $e->userFacingReason(), $e->statusCode, $startedAt);

            return;
        } catch (Throwable $e) {
            $this->fail($image, 'unexpected', null, $startedAt);

            return;
        }

        $path = "exercises/{$image->slug}/image.{$generated->extension()}";

        try {
            $disk = Storage::disk(self::DISK);

            if ($disk->put($path, $generated->bytes) === false) {
                throw new \RuntimeException('Upload returned false.');
            }

            // ?v= busts CDN/browser caches if the image is ever regenerated.
            $url = $disk->url($path).'?v='.now()->timestamp;
        } catch (Throwable $e) {
            $this->fail($image, 'storage_error', null, $startedAt);

            return;
        }

        $image->update([
            'status' => ExerciseImage::STATUS_READY,
            'image_url' => $url,
            'object_path' => $path,
            'model' => $generated->model,
            'generated_at' => now(),
            'failed_at' => null,
            'last_error' => null,
        ]);

        Log::info('exercise_image.generated', [
            'exercise_image_id' => $image->id,
            'slug' => $image->slug,
            'model' => $generated->model,
            'mime' => $generated->mimeType,
            'bytes' => strlen($generated->bytes),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function findOrCreate(string $slug, string $name): ExerciseImage
    {
        $existing = ExerciseImage::where('slug', $slug)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return ExerciseImage::create([
                'slug' => $slug,
                'name' => mb_substr(ExerciseImagePrompt::sanitize($name), 0, 100),
                'status' => ExerciseImage::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it a moment ago — use theirs.
            return ExerciseImage::where('slug', $slug)->firstOrFail();
        }
    }

    /**
     * A `generating` row that is stale AND out of attempts can never be
     * claimed again, so record it as failed instead of leaving it spinning.
     */
    private function failIfStuck(ExerciseImage $image): void
    {
        if ($image->status === ExerciseImage::STATUS_GENERATING
            && $image->attempts >= ExerciseImage::MAX_ATTEMPTS
            && $image->generating_started_at?->lte(now()->subMinutes(ExerciseImage::STALE_AFTER_MINUTES))) {
            $image->update([
                'status' => ExerciseImage::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => 'timeout',
            ]);
        }
    }

    private function fail(ExerciseImage $image, string $code, ?int $statusCode, float $startedAt): void
    {
        $image->update([
            'status' => ExerciseImage::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => mb_substr($code, 0, 40),
        ]);

        Log::warning('exercise_image.failed', [
            'exercise_image_id' => $image->id,
            'slug' => $image->slug,
            'error_code' => $code,
            'status_code' => $statusCode,
            'attempts' => $image->attempts,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function storageConfigured(): bool
    {
        $config = config('filesystems.disks.'.self::DISK, []);

        if (($config['driver'] ?? null) !== 's3') {
            return true;
        }

        return filled($config['key'] ?? null)
            && filled($config['secret'] ?? null)
            && filled($config['bucket'] ?? null)
            && filled($config['endpoint'] ?? null)
            && filled($config['url'] ?? null);
    }
}
