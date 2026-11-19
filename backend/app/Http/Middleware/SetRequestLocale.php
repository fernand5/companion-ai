<?php

namespace App\Http\Middleware;

use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for one request, and puts the previous one back when the request ends.
 *
 *  - A signed-in user is always answered in the language saved on their account. A header cannot
 *    override it, so one user's request can never be answered in another language by a client.
 *  - Without a signed-in user (login, register, a rejected token) the only input is an explicit
 *    X-App-Locale header with exactly a supported value. Accept-Language and the request's origin are
 *    ignored on purpose: the language is never inferred from the browser, country or location.
 *  - Anything else is English.
 *
 * The language only affects the wording of messages the framework builds. It is not consulted by
 * authorization, plan dates or any workout decision.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();

        app()->setLocale($this->resolve($request)->value);

        try {
            return $next($request);
        } finally {
            // The application object outlives a request under a long-running server and in tests;
            // restoring in `finally` also covers an exception rendered further out.
            app()->setLocale($previous);
        }
    }

    private function resolve(Request $request): AppLocale
    {
        $user = $request->user('sanctum');

        if ($user !== null) {
            return AppLocale::fromStored($user->locale);
        }

        return AppLocale::fromSupplied(trim((string) $request->header(AppLocale::HEADER))) ?? AppLocale::DEFAULT;
    }
}
