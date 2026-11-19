<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetRequestLocale;
use App\Support\ApiErrorLocalizer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            HandleCors::class,
            AssignRequestId::class,
            SetRequestLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The wording of an error, in the language of the request: only the `message` (never the status, a header,
        // a JSON key or a code). English is returned untouched. See ApiErrorLocalizer.
        $exceptions->respond(fn (Response $response, Throwable $e) => ApiErrorLocalizer::localize($response, $e));
    })->create();
