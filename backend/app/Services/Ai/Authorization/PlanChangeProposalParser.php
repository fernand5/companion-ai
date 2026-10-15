<?php

namespace App\Services\Ai\Authorization;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a user's wish ("I want to go up to 45 kg on bench next session") into ONE precise
 * proposal, or says why it cannot. It reads only the user's own message and the user's
 * own upcoming plan; the model is not involved, so the model cannot shape what the user
 * is asked to confirm.
 *
 * Deliberately narrow, and it fails closed: one load in kilograms, one exercise that
 * resolves to exactly one of the user's pending upcoming exercises, one day that resolves
 * to an existing plan, and a load within the maximum permitted change. Anything it cannot
 * pin down (pounds, "Friday", "the week after", two exercises, an unclear name, sets or
 * reps it cannot read) produces no proposal, so no generic "yes" is ever offered.
 *
 * English only. A message in another language yields no proposal.
 */
class PlanChangeProposalParser
{
    public function __construct(private readonly PlanLoadGuard $loadGuard) {}

    public function propose(User $user, Conversation $conversation, string $message): ProposalResult
    {
        $text = mb_strtolower(str_replace(['’', '‘'], "'", $message));

        if (preg_match('/\b(?:lbs?|pounds?)\b/u', $text)) {
            return ProposalResult::failed('unsupported_unit');
        }

        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:kg|kgs|kilos?|kilograms?)\b/u', $text, $loads);

        if (count($loads[1]) !== 1) {
            return ProposalResult::failed('no_single_load');
        }

        $weight = (float) str_replace(',', '.', $loads[1][0]);

        if ($weight <= 0 || $weight > 500) {
            return ProposalResult::failed('load_out_of_range');
        }

        // A day it cannot name exactly is a day it will not guess.
        if (preg_match('/\b(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekend|week|month|every|each|daily|after|then|later|until)\b/u', $text)) {
            return ProposalResult::failed('date_not_representable');
        }

        $sets = null;
        $reps = null;

        if (preg_match('/\b(\d{1,2})\s*[x×]\s*(\d{1,3})\b/u', $text, $m)) {
            [$sets, $reps] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/\b(?:sets?|reps?|repetitions?)\b/u', $text)) {
            return ProposalResult::failed('sets_reps_not_representable');
        }

        $candidates = $this->upcoming($user);
        $slug = $this->resolveExercise($text, $candidates);

        if (is_array($slug)) {
            return ProposalResult::failed($slug['failure']);
        }

        $rows = $candidates->where('exercise_slug', $slug)->sortBy('plan_date')->values();
        $date = $this->resolveDate($text, $user);

        if ($date === false) {
            return ProposalResult::failed('date_not_representable');
        }

        $row = $date === null ? $rows->first() : $rows->firstWhere('plan_date', $date);

        if ($row === null) {
            return ProposalResult::failed('no_plan_on_date');
        }

        if ($rows->where('plan_date', $row->plan_date)->count() > 1) {
            return ProposalResult::failed('exercise_twice_in_one_day');
        }

        if ($row->planned_weight_kg !== null && abs((float) $row->planned_weight_kg - $weight) < 1e-9) {
            return ProposalResult::failed('no_change');
        }

        $limit = $this->loadGuard->limitFor($user, $slug);

        if ($limit !== null && $weight > $limit['maximum_kg']) {
            return ProposalResult::failed('above_maximum_change', ['requested_kg' => $weight] + $limit);
        }

        return ProposalResult::ok(PlanChangeProposal::create(
            userId: $user->id,
            conversationId: $conversation->id,
            planId: (int) $row->workout_plan_id,
            planExerciseId: (int) $row->id,
            date: (string) $row->plan_date,
            exerciseSlug: $slug,
            exerciseName: $row->exercise_name,
            plannedWeightKg: $weight,
            plannedSets: $sets,
            plannedReps: $reps,
        ));
    }

    /** The user's own pending exercises in upcoming plans that are not yet done. */
    private function upcoming(User $user): Collection
    {
        return WorkoutPlanExercise::query()
            ->select('workout_plan_exercises.*', 'workout_plans.planned_date as plan_date_raw')
            ->join('workout_plans', 'workout_plans.id', '=', 'workout_plan_exercises.workout_plan_id')
            ->where('workout_plans.user_id', $user->id)
            ->whereDate('workout_plans.planned_date', '>=', $user->localToday())
            ->whereIn('workout_plans.status', [WorkoutPlan::STATUS_PLANNED, WorkoutPlan::STATUS_IN_PROGRESS])
            ->where('workout_plan_exercises.status', WorkoutPlanExercise::STATUS_PENDING)
            ->whereNotNull('workout_plan_exercises.exercise_slug')
            ->orderBy('workout_plans.planned_date')
            ->get()
            ->each(fn ($row) => $row->plan_date = substr((string) $row->plan_date_raw, 0, 10));
    }

    /** @return string|array{failure: string} the exercise's slug, or why none could be chosen */
    private function resolveExercise(string $text, Collection $candidates): string|array
    {
        $slugs = $candidates->pluck('exercise_slug')->unique()->values();
        $messageSlug = '-'.Str::slug($text).'-';

        // The exercise's full name, spelled exactly.
        $exact = $slugs->filter(fn (string $slug) => str_contains($messageSlug, '-'.$slug.'-'));

        // Or a single distinctive word ("bench") that belongs to exactly one of the user's exercises.
        $words = collect(explode('-', trim($messageSlug, '-')))->filter(fn ($w) => strlen($w) >= 4)->unique();
        $byWord = $slugs->filter(function (string $slug) use ($words, $slugs) {
            $own = explode('-', $slug);

            return $words->contains(fn ($w) => in_array($w, $own, true)
                && $slugs->every(fn (string $other) => $other === $slug || ! in_array($w, explode('-', $other), true)));
        });

        // Any hint at a second exercise ends it: one change names one exercise.
        $named = $exact->merge($byWord)->unique()->values();

        return match (true) {
            $named->count() === 1 => $named->first(),
            $named->count() > 1 => ['failure' => 'several_exercises'],
            default => ['failure' => 'exercise_not_identified'],
        };
    }

    /** @return string|false|null a date, null for "the next one", false when the wording names a day it cannot resolve */
    private function resolveDate(string $text, User $user): string|false|null
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\btomorrow\b/u', $text)) {
            return $user->localNow()->addDay()->toDateString();
        }

        if (preg_match('/\btoday\b|\btonight\b/u', $text)) {
            return $user->localToday();
        }

        return null;
    }
}
