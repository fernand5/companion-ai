<?php

namespace App\Support;

use Closure;

/**
 * Runs a piece of the coach's tool machinery in English, whatever language the request is in.
 *
 * What the model reads (tool results, validation errors, refusals) and what the application matches by its English
 * wording (the reasons a plan change was refused, which a Spanish user's notice is rebuilt from) must not change with
 * the language of the person asking: the model is told, in its prompt, which language to answer in, and a validation
 * error in Spanish would be a different input to the same decision. So only the calls that produce that text run
 * inside this, one tool call at a time, not the whole request.
 *
 * The language is put back in `finally`, so a failure cannot leave the application in English for whatever comes
 * next in the same process.
 */
final class ModelFacingLocale
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function english(Closure $callback): mixed
    {
        $previous = app()->getLocale();

        if ($previous === AppLocale::DEFAULT->value) {
            return $callback();
        }

        app()->setLocale(AppLocale::DEFAULT->value);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
