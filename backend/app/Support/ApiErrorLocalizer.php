<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Puts the `message` of an error response into the language of the request, at the one place every exception passes
 * through on its way out (the exception handler's `respond` hook).
 *
 * It changes the text and nothing else. The status, every header (Retry-After and the rest), the JSON keys, the
 * `errors` field keys and any machine-readable code are exactly what the framework built. English is returned
 * untouched, so an English client reads byte for byte what it always did.
 *
 * Which message is chosen by the KIND of exception and the status it already has, never by the message it carries:
 * a Spanish "not found" does not repeat a model class name or a path the framework would show in debug mode.
 * The one exception is a validation error raised by a fitness service in English (it has no code to choose by): the
 * handful of those sentences are recognised by their exact English template and nothing else is guessed; any other
 * stays as it was.
 *
 * The language is read when the response is built. The language middleware restores the previous language in
 * `finally` AFTER the response is returned, so the error of one request can only ever be worded in that request's
 * language. A request that never reached that middleware (a URL that matches no route, a wrong method) was never
 * given a language and stays English.
 */
final class ApiErrorLocalizer
{
    /** The service-raised validation sentences, by catalog key. The English template is read from the English catalog. */
    private const SERVICE_VALIDATION = [
        'errors.validation.plan_has_performance',
        'errors.validation.plan_started',
        'errors.validation.exercise_not_pending',
        'errors.validation.record_one_set',
        'errors.validation.date_in_future',
        'errors.validation.date_outside_range',
        'errors.validation.date_outside_week',
    ];

    public static function localize(Response $response, Throwable $e): Response
    {
        if (! $response instanceof JsonResponse || app()->getLocale() === AppLocale::DEFAULT->value) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || ! array_key_exists('message', $data)) {
            return $response;
        }

        $localized = $e instanceof ValidationException ? self::validation($data) : self::generic($e, $response->getStatusCode(), $data);

        if ($localized !== null) {
            $response->setData($localized);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function generic(Throwable $e, int $status, array $data): ?array
    {
        $message = match (true) {
            $status === 401 && $e instanceof AuthenticationException => __('errors.http.unauthenticated'),
            $status === 403 && $e instanceof HttpExceptionInterface => __('errors.http.forbidden'),
            $status === 404 && $e instanceof NotFoundHttpException => __('errors.http.not_found'),
            $status === 405 && $e instanceof MethodNotAllowedHttpException => __('errors.http.method_not_allowed'),
            $status === 429 && $e instanceof ThrottleRequestsException => __('errors.http.too_many_attempts'),
            // An unhandled failure. With debug on, the message is the developer's diagnostic: left alone.
            $status === 500 && ! config('app.debug') => __('errors.http.server_error'),
            default => null,
        };

        return $message === null ? null : ['message' => $message] + $data;
    }

    /**
     * Wording of the fitness services' own validation sentences (the framework's rules are already worded in the
     * request's language when they fail). The summary `message` is rebuilt from the localized first error.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function validation(array $data): ?array
    {
        $errors = $data['errors'] ?? null;

        if (! is_array($errors)) {
            return null;
        }

        $changed = false;

        foreach ($errors as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $i => $message) {
                if (is_string($message) && ($localized = self::serviceSentence($message)) !== null) {
                    $errors[$field][$i] = $localized;
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return null;
        }

        $all = array_values(Arr::flatten($errors));
        $summary = (string) array_shift($all);

        if (($more = count($all)) > 0) {
            $summary .= ' '.app('translator')->choice('(and :count more '.($more === 1 ? 'error' : 'errors').')', $more, ['count' => $more]);
        }

        return ['message' => $summary, 'errors' => $errors] + $data;
    }

    /** One of the known English sentences, said in the current language; null for anything else. */
    public static function serviceSentence(string $english): ?string
    {
        $dates = ['date' => '(\d{4}-\d{2}-\d{2})', 'start' => '(\d{4}-\d{2}-\d{2})', 'end' => '(\d{4}-\d{2}-\d{2})'];

        foreach (self::SERVICE_VALIDATION as $key) {
            $template = trans($key, [], AppLocale::DEFAULT->value);
            $names = [];

            $pattern = preg_replace_callback('/:(date|start|end)\b/', function (array $m) use (&$names, $dates) {
                $names[] = $m[1];

                return "\x00".$dates[$m[1]]."\x00";
            }, $template);

            // Quote everything but the capture groups.
            $parts = explode("\x00", (string) $pattern);
            $regex = '';
            foreach ($parts as $n => $part) {
                $regex .= $n % 2 === 1 ? $part : preg_quote($part, '/');
            }

            if (preg_match('/^'.$regex.'$/u', $english, $m) !== 1) {
                continue;
            }

            return trans($key, array_combine($names, array_slice($m, 1)) ?: []);
        }

        return null;
    }
}
