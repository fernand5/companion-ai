<?php

namespace App\Support;

use App\Models\User;
use App\Services\Ai\Notices\NoticeText;

/**
 * The weekly-plan endpoints' one user-facing error string, in the language of the request.
 *
 * `applied`, `skipped` (stable reason codes), `changes` and every other key are returned exactly as they came. Only
 * `error` (a message the tool wrote for the model, in English) is said again for a person in another language, and
 * only where it is one of the failures the application knows: the fitness services' validation sentences, the
 * load guard's refusals, an incomplete decision, or "nothing was changed". Anything else gets a safe line that claims
 * nothing about what was or was not saved, so no unknown English reaches the person and none is guessed at.
 * English is returned untouched.
 */
final class WeeklyPlanMessages
{
    /** The application's own opening of a refusal that changed nothing (the reason follows it). */
    private const NOT_DONE = 'Not done, nothing was changed: ';

    /** Tool messages that mean "the decision was missing its summary or reasons". */
    private const INCOMPLETE = [
        'decision_summary and reasoning_factors are required.',
        'decision_summary and reasoning_factors are required when saving a plan.',
        'reasoning_factors and decision_summary are required when creating a plan.',
        'changes must be an array (it may be empty).',
    ];

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function present(array $result, User $user): array
    {
        if (app()->getLocale() === AppLocale::DEFAULT->value || ! isset($result['error']) || ! is_string($result['error'])) {
            return $result;
        }

        $result['error'] = self::error($result['error'], NoticeText::for($user));

        return $result;
    }

    private static function error(string $english, NoticeText $words): string
    {
        // "Not done, nothing was changed: <why>": the application's own prefix, then a known reason.
        if (str_starts_with($english, self::NOT_DONE)) {
            $why = substr($english, strlen(self::NOT_DONE));
            $reason = ApiErrorLocalizer::serviceSentence($why) ?? $words->recognisedLoadRefusal($why);

            return trim(__('errors.weekly_plan.nothing_changed').' '.($reason ?? ''));
        }

        return match (true) {
            ($known = ApiErrorLocalizer::serviceSentence($english)) !== null => $known,
            in_array($english, self::INCOMPLETE, true) => __('errors.weekly_plan.incomplete_decision'),
            ($refusal = $words->recognisedLoadRefusal($english)) !== null => $refusal,
            str_starts_with($english, 'Nothing was changed yet:') => __('errors.weekly_plan.nothing_changed'),
            default => __('errors.weekly_plan.failed'),
        };
    }
}
