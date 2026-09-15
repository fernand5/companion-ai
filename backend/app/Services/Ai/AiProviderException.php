<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        private readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    /**
     * A short, safe-to-display reason for the failure — no response bodies,
     * no API keys. Used by controllers to give the user an accurate message
     * instead of a generic/misleading one.
     */
    public function userFacingReason(): string
    {
        if ($this->reason !== null) {
            return $this->reason;
        }

        return match (true) {
            $this->isRateLimited() => 'rate_limited',
            default => 'unavailable',
        };
    }

    /**
     * The single source of truth for the copy shown to the user when an AI
     * call fails — every controller that surfaces this error uses the same
     * wording instead of re-deriving it.
     */
    public function userFacingMessage(): string
    {
        return match ($this->userFacingReason()) {
            'not_configured' => 'The AI coach is not configured yet. Set AI_API_KEY in the backend .env file.',
            'rate_limited' => 'Your coach is at its request limit for now — please wait a moment and try again.',
            default => 'The AI coach is temporarily unavailable — please try again in a moment.',
        };
    }
}
