<?php

namespace App\Support;

use App\Services\Fitness\Progression\ProgressionApprovalException;
use App\Services\Fitness\Progression\ProgressionPolicy;
use App\Services\Fitness\Progression\ReasonCode;

/**
 * The progression endpoints' words, in the language of the request.
 *
 * The calculation is not touched and nothing here feeds it. The engine still returns its reasons and caveats in
 * English with their stable identities; this only says the same thing in another language, and adds the identity of a
 * caveat beside its text. The model-facing tool keeps reading the English straight from the engine.
 *
 *  - `reasons` is rebuilt from `reason_codes` (same order, same count), so a reason can never be paired with the wrong
 *    code. The numbers in a sentence come from ProgressionPolicy, not from a translation.
 *  - A safety caveat has no code in the engine, only a fixed sentence. Its code is found by comparing the sentence with
 *    the six the engine can write (exact text, number filled in): an exact match is the one-to-one identity, anything
 *    else is reported as `null` and its text is left as it was, never guessed.
 *  - An approval refusal is worded by its stable `code`; an `ineligible` one also names why, from a closed list.
 */
final class ProgressionMessages
{
    /** ProgressionApprovalException reasons that have a catalog entry (everything else keeps its own message). */
    private const APPROVAL_REASONS = ['invalid_proposal', 'forbidden', 'expired', 'stale', 'already_used', 'dismissed', 'ineligible'];

    /** The closed list of reasons an approval can be ineligible, as the approval service names them. */
    public const INELIGIBLE_DETAILS = [
        'not_a_change', 'no_upcoming_session', 'no_load_in_plan', 'workout_already_started',
        'plan_already_at_or_above', 'above_maximum_change', 'plan_already_at_or_below',
    ];

    /** @return array<string, int> the policy numbers a sentence may quote */
    private static function numbers(): array
    {
        return [
            'sessions' => ProgressionPolicy::SESSIONS_REQUIRED,
            'stale_days' => ProgressionPolicy::STALE_AFTER_DAYS,
            'misses' => ProgressionPolicy::MISSES_FOR_DECREASE,
            'percent' => (int) round(ProgressionPolicy::MAX_INCREASE_FRACTION * 100),
            'pain_days' => ProgressionPolicy::PAIN_WINDOW_DAYS,
            'recovery_days' => ProgressionPolicy::RECOVERY_CURRENT_DAYS,
        ];
    }

    /** The meaning of a reason code, in the given language ('en' is exactly ReasonCode::meaning()). */
    public static function reason(ReasonCode $code, ?string $locale = null): string
    {
        return trans('progression.reasons.'.$code->value, self::numbers(), $locale);
    }

    /** @return array<string, string> caveat code => sentence in the given language */
    public static function caveats(?string $locale = null): array
    {
        $n = self::numbers();

        return [
            'effort_not_recorded' => trans('progression.caveats.effort_not_recorded', $n, $locale),
            'not_medical_clearance' => trans('progression.caveats.not_medical_clearance', $n, $locale),
            'recovery_unknown' => trans('progression.caveats.recovery_unknown', $n, $locale),
            'pain_reported' => trans('progression.caveats.pain_reported', $n, $locale),
            'high_soreness' => trans('progression.caveats.high_soreness', $n, $locale),
            'restriction_unmatched' => trans('progression.caveats.restriction_unmatched', $n, $locale),
        ];
    }

    /**
     * The recommendations response, with each item's caveats given their codes and, for a non-English request, its
     * reasons and caveats said in that language. Every other key and value is returned exactly as it came.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function present(array $payload): array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        $english = array_flip(self::caveats(AppLocale::DEFAULT->value));
        $localized = self::caveats();
        $translate = app()->getLocale() !== AppLocale::DEFAULT->value;

        foreach ($payload['data'] as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($translate && isset($item['reason_codes']) && is_array($item['reason_codes'])) {
                $reasons = [];

                foreach ($item['reason_codes'] as $code) {
                    $case = is_string($code) ? ReasonCode::tryFrom($code) : null;

                    if ($case === null) {
                        // A code this build does not know: keep the whole English list rather than misalign it.
                        $reasons = null;
                        break;
                    }

                    $reasons[] = self::reason($case);
                }

                if ($reasons !== null) {
                    $item['reasons'] = $reasons;
                }
            }

            if (isset($item['safety_caveats']) && is_array($item['safety_caveats'])) {
                $codes = [];
                $texts = [];

                foreach ($item['safety_caveats'] as $caveat) {
                    $code = is_string($caveat) ? ($english[$caveat] ?? null) : null;
                    $codes[] = $code;
                    $texts[] = $translate && $code !== null ? $localized[$code] : $caveat;
                }

                $item['safety_caveats'] = $texts;
                $item = self::after($item, 'safety_caveats', ['safety_caveat_codes' => $codes]);
            }

            $payload['data'][$i] = $item;
        }

        return $payload;
    }

    /** Puts new keys right after an existing one, so the established keys keep their order. */
    private static function after(array $item, string $key, array $add): array
    {
        $out = [];

        foreach ($item as $k => $v) {
            $out[$k] = $v;

            if ($k === $key) {
                $out += $add;
            }
        }

        return $out;
    }

    /**
     * The message of a refused approval. English is what the service said, untouched. Another language is chosen by
     * the stable reason, and for `ineligible` by the closed detail the service embedded in its message.
     */
    public static function approval(ProgressionApprovalException $e): string
    {
        if (app()->getLocale() === AppLocale::DEFAULT->value || ! in_array($e->reason, self::APPROVAL_REASONS, true)) {
            return $e->getMessage();
        }

        return match ($e->reason) {
            'invalid_proposal' => __('progression.approval.invalid_proposal'),
            'forbidden' => __('progression.approval.forbidden'),
            'expired' => __('progression.approval.expired'),
            'stale' => __('progression.approval.stale'),
            'already_used' => __('progression.approval.already_used'),
            'dismissed' => __('progression.approval.dismissed'),
            default => self::ineligible($e->getMessage()),
        };
    }

    private static function ineligible(string $english): string
    {
        $detail = preg_match('/\(([a-z_]+)\)/', $english, $m) === 1 && in_array($m[1], self::INELIGIBLE_DETAILS, true) ? $m[1] : null;

        return match ($detail) {
            'not_a_change' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.not_a_change')]),
            'no_upcoming_session' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.no_upcoming_session')]),
            'no_load_in_plan' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.no_load_in_plan')]),
            'workout_already_started' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.workout_already_started')]),
            'plan_already_at_or_above' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.plan_already_at_or_above')]),
            'above_maximum_change' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.above_maximum_change')]),
            'plan_already_at_or_below' => __('progression.approval.ineligible', ['detail' => __('progression.ineligible.plan_already_at_or_below')]),
            default => __('progression.approval.ineligible_unknown'),
        };
    }
}
