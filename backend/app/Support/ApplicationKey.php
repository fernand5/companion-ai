<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Whether APP_KEY can actually be used to seal and sign things.
 *
 * Signed chat proposals and sealed approval tokens depend on it, and nothing in the app used it
 * before them, so a missing or malformed key could sit unnoticed in an environment. This answers the
 * question the same way Laravel's own encrypter does (a key present, "base64:" decoded, and the right
 * length for the configured cipher), but without throwing, so callers can refuse cleanly instead of
 * failing with a 500, and never fall back to signing with a key that is not one.
 *
 * It never returns, logs or exposes the key itself.
 */
final class ApplicationKey
{
    private const REPORTED_KEY = 'app_key.unusable.reported';

    private static ?int $lastReportedAt = null;

    public static function usable(): bool
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return false;
        }

        if (Str::startsWith($key, 'base64:')) {
            $decoded = base64_decode(Str::after($key, 'base64:'), true);

            if ($decoded === false) {
                return false;
            }

            $key = $decoded;
        }

        return Encrypter::supported($key, (string) config('app.cipher'));
    }

    /**
     * Say, in the server log only, that the key is unusable and what to do about it. At most once a
     * minute across all workers (through the cache), so a busy screen cannot flood the log; if the cache
     * cannot be used, it falls back to once a minute per process rather than losing the signal. Contains
     * no key material.
     */
    public static function reportUnusable(string $where): void
    {
        $now = time();

        try {
            if (! Cache::add(self::REPORTED_KEY, 1, 60)) {
                return;
            }
        } catch (\Throwable) {
            if (self::$lastReportedAt !== null && $now - self::$lastReportedAt < 60) {
                return;
            }
        }

        self::$lastReportedAt = $now;

        Log::error('app_key.unusable', [
            'where' => $where,
            'problem' => 'APP_KEY is missing or is not a valid key for the configured cipher, so signed proposals cannot be created or checked.',
            'action' => 'Generate a key with `php artisan key:generate --show` and set it as APP_KEY in this service\'s environment, then redeploy. Do not rotate an existing valid key.',
        ]);
    }

    /** Forget when the key problem was last reported (for tests). */
    public static function resetReporting(): void
    {
        self::$lastReportedAt = null;

        try {
            Cache::forget(self::REPORTED_KEY);
        } catch (\Throwable) {
            // nothing to forget
        }
    }
}
