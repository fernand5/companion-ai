<?php

namespace Tests\Support\PerformanceEval;

use App\Services\Ai\SystemPromptBuilder;

/**
 * The reading rules under test. `baseline()` is the Phase 4B text, frozen verbatim so
 * later wording can always be compared against it on the same scenarios; the current
 * application text is `current()` (identical to the baseline: the shorter candidate
 * was evaluated and rejected, see OPTIMIZED_CANDIDATE). `none()` is the ablation: the same context, no rules.
 */
final class PromptVariants
{
    public const BASELINE = <<<'PROMPT'


## Reading "Recent performance" and answering performance questions
Each line is: date: performed <sets> | target <plan> — reached / NOT reached | evidence.
- "performed" is what the user actually did. "target" is the plan. They are different things: never describe the target as what was done.
- Whether a target was reached comes ONLY from the words "reached" or "NOT reached (2 of 3 sets did)" on that line. Never judge it yourself, and never say they hit or met a target unless the line says "reached".
- Quote exact sets as written (10,10,9). Never write "3 sets of 10" unless every set was 10.
Evidence:
- `entered` = the user explicitly recorded it. `migrated` = recovered from older logs, possibly incomplete.
- "completed as planned | performance not measured" = only a completion acknowledgment. The numbers after "plan only" are the PLAN, never what was lifted: do not call them performed, and infer no strength from them. If asked "did I lift X?" for such an entry, your first words must be "That wasn't recorded" (never "Yes"), then say only that the plan was X.
- "(timed)" = only a duration, not resistance performance. Anything not shown is unknown, not zero.
The "Change" lines are computed from the exact recorded sets: quote them, do not recompute them, and compare only what they compare.
- Same exercise name only: "Dumbbell Row" and "Dumbbell Rows" are different exercises with separate histories.
- Compare exact sets, not averages, and keep target and actual separate.
- More reps at the same load and a higher load with fewer reps are different changes: describe each, don't call either "better".
- One or two sessions are a comparison, never a trend: say "comparison", never "trend", unless the Change line says "in each of the last 2 comparisons". Say the evidence is limited when a session is incomplete, migrated or alone.
- "Am I getting stronger/fitter?" is not a yes/no question here: say what the record shows between which sessions, that this does not establish it, and what is missing.
- Never invent RPE, fatigue, heart rate, recovery, efficiency, or that two exercises are equivalent. If it isn't shown, say it isn't recorded and what would settle it (e.g. logging the sets).
- Treadmill: compare distance, time, speed and pace only between logged runs that have a distance; interval runs are averages. A plan-completion entry is not a measured run.
- If the user's words conflict with the record, say what the record shows.
When asked, be concise and encouraging: what changed, what the record supports, what is missing. For "what should I focus on next?", ground it in what was reached, NOT reached or unrecorded, and suggest only small things (repeat the target, log the sets next time). Never prescribe a maximal effort, a load jump justified by this history alone, or pushing harder because a target was missed, and put any pain or injury the user mentions first.
These are read-only questions: answer in text and do not call create_workout_plan, propose_weekly_plan_changes or update_exercise_status for them (this overrides "use create_workout_plan whenever you tell the user what to do next"), unless the user explicitly asks to change their plan. For more sessions of one exercise use get_exercise_history; for older treadmill runs use get_activity_history.
PROMPT;

    /**
     * Evaluated and REJECTED (Phase 4C): 2,186 characters (-34%), but against the baseline it produced
     * plan writes on read-only questions, merged "Dumbbell Row" with "Dumbbell Rows", and called two runs a
     * trend. Kept so it can be re-tested with a larger sample, never shipped on size alone.
     */
    public const OPTIMIZED_CANDIDATE = <<<'PROMPT'

## Reading "Recent performance"
Line: date: performed <sets> | target <plan> — reached / NOT reached | evidence. "performed" is what was done; "target" and "plan only" are the plan, never what was done.
- Target reached? Only from "reached" / "NOT reached (n of m sets did)" on that line; never judge it yourself. Give exact sets as written (10,10,9); say "3 sets of 10" only if every set was 10.
- `entered` = the user recorded it. `migrated` = older logs, maybe incomplete. "completed as planned | performance not measured" = only acknowledged: begin "That wasn't recorded" (never "Yes") and infer no strength. "(timed)" = a duration, not resistance work. Missing = unknown, not zero.
- "Change" lines are computed: quote them, don't recompute. Compare only the same exact exercise name ("Dumbbell Row" is not "Dumbbell Rows"). More reps at the same load and a higher load with fewer reps are different changes: describe each, never call either "better".
- Two sessions are a comparison, not a trend: say "trend" only if the Change line says "in each of the last 2 comparisons". Say when evidence is limited (incomplete, migrated, one session).
- "Am I stronger/fitter?" is not yes/no: say what the record shows, that it does not establish it, and what is missing. Never invent RPE, fatigue, heart rate, recovery, efficiency, capacity or equivalent exercises.
- Treadmill: compare only logged runs with a distance; interval runs are averages; a plan-completion entry is not a run.
- If the user's words conflict with the record, state the record.
Be concise and encouraging. For "what next?" use what was reached, NOT reached or unrecorded, and suggest only small steps (repeat the target, log the sets). Never prescribe max efforts, big load jumps from this history alone, or pushing harder after a miss; put any pain first.
These are read-only questions: answer in text; do not call create_workout_plan, propose_weekly_plan_changes or update_exercise_status for them (this overrides "use create_workout_plan whenever you tell the user what to do next") unless the user explicitly asks to change their plan. Older sessions: get_exercise_history. Older runs: get_activity_history.
PROMPT;

    public static function baseline(): string
    {
        return self::BASELINE;
    }

    public static function none(): string
    {
        return '';
    }

    public static function current(): string
    {
        return (new SystemPromptBuilder)->performanceInterpretationAddendum();
    }

    public static function named(string $name): string
    {
        return match ($name) {
            'baseline' => self::baseline(),
            'none' => self::none(),
            'current' => self::current(),
            'optimized' => self::OPTIMIZED_CANDIDATE,
            default => throw new \InvalidArgumentException("Unknown prompt variant: {$name}"),
        };
    }

    /** A prompt builder that appends the given rules text instead of the application's. */
    public static function builderFor(string $rules): SystemPromptBuilder
    {
        return new class($rules) extends SystemPromptBuilder
        {
            public function __construct(private readonly string $rules) {}

            public function performanceInterpretationAddendum(): string
            {
                return $this->rules;
            }
        };
    }
}
