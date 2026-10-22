<?php

namespace App\Http\Middleware;

use App\Support\ApplicationKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses, with a plain 503, the endpoints whose approval proposals need a usable APP_KEY when it is
 * not one. The client learns only that the feature is temporarily unavailable; the operator gets an
 * actionable error in the log. Nothing is signed, sealed or half-done in the meantime.
 */
class EnsureApplicationKeyIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApplicationKey::usable()) {
            ApplicationKey::reportUnusable($request->path());

            return self::unavailable();
        }

        return $next($request);
    }

    public static function unavailable(): JsonResponse
    {
        return response()->json([
            'code' => 'progression_unavailable',
            'message' => 'Progression suggestions are temporarily unavailable. Please try again later.',
        ], 503);
    }
}
