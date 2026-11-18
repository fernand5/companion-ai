<?php

namespace App\Support;

use App\Services\Ai\AiProviderException;
use Illuminate\Http\JsonResponse;

/**
 * The words of the API's own messages, in the language of the request.
 *
 * Presentation only. Every key below is a literal in this file: a message is chosen by a stable code or by what the
 * application itself decided happened, never by what a user typed and never by the wording of another message. The
 * language is whatever SetRequestLocale resolved (the signed-in user's saved language; for a guest, the explicit
 * X-App-Locale header; otherwise English) and is read from the application at the moment a message is built, so the
 * words can never be fixed to the wrong user. Nothing in the application decides anything from these strings.
 *
 * HTTP statuses, headers, JSON keys and machine-readable codes are not here and never change with the language.
 */
final class ApiMessages
{
    /** The stable `code` of an AI-provider failure, by the provider's reason. Additive: the message is unchanged. */
    public static function providerCode(string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'ai_not_configured',
            'rate_limited' => 'ai_rate_limited',
            default => 'ai_unavailable',
        };
    }

    /** What a person is told when the AI provider failed, by the provider's reason (anything unknown is "unavailable"). */
    public static function provider(string $reason): string
    {
        return match ($reason) {
            'not_configured' => __('errors.provider.not_configured'),
            'rate_limited' => __('errors.provider.rate_limited'),
            default => __('errors.provider.unavailable'),
        };
    }

    /** The 503 every AI endpoint answers with: the established `message`, and the stable `code` beside it. */
    public static function providerFailure(AiProviderException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->userFacingMessage(),
            'code' => self::providerCode($e->userFacingReason()),
        ], 503);
    }

    public static function loggedOut(): string
    {
        return __('errors.auth.logged_out');
    }

    public static function loginFailed(): string
    {
        return __('auth.failed');
    }

    public static function coachBusy(): string
    {
        return __('errors.coach.busy');
    }

    public static function demonstrationUnavailable(): string
    {
        return __('errors.exercise_demo.unavailable');
    }

    public static function demonstrationFailed(): string
    {
        return __('errors.exercise_demo.failed');
    }

    public static function progressionUnavailable(): string
    {
        return __('errors.progression.unavailable');
    }

    public static function weekAlreadyCovered(): string
    {
        return __('errors.weekly_plan.already_covered');
    }

    public static function onlyTheProposal(): string
    {
        return __('errors.validation.only_proposal');
    }

    public static function setNeedsAValue(): string
    {
        return __('errors.validation.set_needs_value');
    }
}
