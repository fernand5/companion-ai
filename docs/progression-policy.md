# Progression policy (Phase 5A)

How the progression engine (`backend/app/Services/Fitness/Progression`) decides, why, and what it
deliberately does not do. Policy version: `progression-policy-1`. Every number below lives in
`ProgressionPolicy.php`; changing one is a policy change and must bump the version.

The engine **recommends**. It never changes a plan, a target or a log. Applying a recommendation
needs the user's approval and is Phase 5B/5C.

## Principles and their limits

- **Progressive overload.** Training demand (load, repetitions, sets) rises gradually once the
  current demand has been met. This is the established principle of resistance-training progression.
- **A published guideline, used more strictly.** The ACSM position stand on progression models in
  resistance training for healthy adults (Med Sci Sports Exerc 2009;41:687-708) suggests raising load
  by about 2-10% once the person can do the current work for one to two repetitions over the goal on
  two consecutive sessions. This policy borrows the *shape* (repeat success, then a small step) and is
  stricter:
  - it needs **three** consecutive measured sessions, on three different days, at the same load and
    the same target (the data is self-entered, exercise identity is free text, and Phase 4C showed that
    two sessions get read as a trend);
  - it uses the **smallest practical step**, not a percentage, and never more than **10%** (the top of
    the guideline's range);
  - it treats a reached target as *necessary, not sufficient*.
- **A percentage is not universally safe.** 10% of 12 kg dumbbells is 1.2 kg, which most gyms cannot
  load; 10% of 140 kg is 14 kg, which is a lot for one step. The step comes from the user's own
  recorded loads for that exact exercise when they show one, else a fallback by load size
  (`< 10 kg: 1 kg`, `< 25 kg: 2 kg`, else `2.5 kg`). If the smallest available step is more than 10% of
  the load, the engine returns `MANUAL_REVIEW` instead of rounding down to something unusable.
- **The guideline is for supervised, healthy adults.** It says nothing about pain, injury, technique,
  equipment granularity, sleep or medical circumstances. The engine therefore reads none of those as
  clearance, and treats missing information as unknown.
- **Nothing here is medical advice.** A training log cannot show readiness, and the engine never
  infers it.

## Evidence requirements

| Record | Used as |
|---|---|
| `entered` (per-set numbers the user recorded) | The evidence. Only this can justify an increase. |
| `migrated` (recovered from older rows) | Context only. A decision that would rest on it becomes `INSUFFICIENT_DATA` (`MIGRATED_EVIDENCE`). Never justifies an increase or a decrease. |
| `as_planned` (one-tap "done") | Not performance. Never counted; never justifies anything. A *newer* one-tap session stops any change (`LATEST_SESSION_NOT_MEASURED`). |
| Missing values | Stay unknown. A set without a load is not bodyweight (`NO_LOAD_RECORDED`); loads on some sets only is `LOAD_PARTIALLY_RECORDED`; no recorded target means "reached" cannot be judged (`NO_TARGET_RECORDED`). |

Other rules: one **exact** exercise at a time (`Dumbbell Row` and `Dumbbell Rows` are different, and a
mixed history is `MANUAL_REVIEW`); strength sessions only; duration-only exercises are
`MANUAL_REVIEW`; history older than 21 days says nothing about today (`STALE_HISTORY`); incomplete sets
and missed targets count as misses, never as successes.

## Decisions

| Decision | When |
|---|---|
| `INCREASE` | The target was reached in each of the last 3 sessions at the same load and target, on 3 different days, all `entered`, the latest session measured; no recent pain, high current soreness or saved restriction; the smallest step is within 10%. Proposes `load + step`, same sets and reps. |
| `DECREASE` | The target was missed in each of the last 3 sessions at the same load. Proposes `load - step`. Recent pain never blocks a reduction. |
| `MAINTAIN` | The default. One or two misses, inconsistent results, fewer than 3 sessions at the current load (including right after the load changed), an incomplete session, or the plan already carrying the proposed load. |
| `INSUFFICIENT_DATA` | No measured history, fewer than 3 measured sessions, stale history, no target to judge by, or the decision would rest on migrated data. |
| `MANUAL_REVIEW` | Recent pain, high soreness, or a saved injury/restriction (which cannot be matched to an exercise); a duration-only exercise; no or partial load; mixed identities; a smallest step above 10%; or a plan already more than 10% above the heaviest recorded load (the maximum permitted change). |

## Safety inputs and their limits

- **Pain:** a recovery check-in in the last 14 days whose pain note says anything other than "none".
  It cannot tell which exercise hurts, so it stops *every* increase. The note's words are never copied
  into a recommendation.
- **Soreness:** the latest check-in, within 7 days, at 4 or 5 out of 5.
- **Restrictions:** a saved memory in the `constraint` category, or whose text names an injury, pain,
  surgery or limitation. This is a keyword reading of free text. It errs towards `MANUAL_REVIEW` and
  **will miss restrictions that are worded differently**. A structured restrictions field would be the
  real fix.
- **Recovery information:** no check-in in the last 7 days is reported as unknown in the caveats; it is
  not a blocker (check-ins are optional) and it is not read as "recovered".
- **Not known at all:** effort (RPE), repetitions in reserve, technique, sleep, nutrition, equipment.

## The maximum permitted change (a cap, not a recommendation)

When the user explicitly asked for the change, an AI-written plan (`create_workout_plan`,
`propose_weekly_plan_changes`) may not carry a load more than 10% above the heaviest load the user has recorded (`entered` or `migrated`, last 180 days) for that exact
exercise, even when the user asked for the change. Exercises with no recorded load are not checked.

Read this limit for what it is:

- It is **a maximum permitted change**: it stops one write from being a bigger jump than the policy ever
  allows. It is **not** a recommended load and **not** a statement that any load up to it is appropriate
  or safe. A plan under the cap can still be wrong for the person.
- The progression engine is separate and much stricter. It proposes an increase only on three
  consecutive reached sessions at one load from entered records, and then only the smallest practical
  step. The cap never produces a recommendation.
- Injury, technique, fatigue, sleep and equipment are not in the record and may be incomplete or out of
  date, so passing the cap check says nothing about them.

User-facing and model-facing wording says "maximum", never "safe".

## Who may raise a load (Phase 5C)

A planned load goes up in exactly two ways, both by the user:

1. the user **approves a calculated suggestion** on the Weekly Plan screen (each exercise on its own), or
2. the user **explicitly asks for the change** in chat, within the maximum permitted change.

Everything the model writes without that (weekly generation and adaptation, a session for today, a
reaction to a logged activity) is held to a **baseline**: for each exercise and day it may carry at
most what the user last performed for that exact exercise, or what that day already carries if that
is higher. So it can keep or lower a load, carry an approved increase forward on its own day, and
start a new exercise, but it cannot raise a load, and it cannot copy an approved increase onto another
day. A write that does is refused and nothing is changed. This is enforced where the tool runs, not in
the prompt (the weekly prompt only repeats it).

## Starting loads: no baseline, no load

A model-written load is only ever as good as what it can be compared with. An exercise has a
**baseline** when the user has a recorded load for that exact exercise (an entered or migrated set with
a weight; a one-tap completion is not one) or already has a load planned for that exact day. With no
baseline there is nothing to personalize a starting weight from, and there is no universal "safe
starting weight" or percentage to substitute, so:

- the model's load is **left unset**: the exercise stays in the plan with its sets and reps, and
  `planned_weight_kg` stays empty (the schema, the plan screens and set entry all support that);
- the model is told so, and told not to state or suggest a weight;
- the application tells the user, in its own words and with no number: "I left the starting weight for X
  unset ... Choose a weight you can lift comfortably for the target reps, and record it when you log
  your sets." The same note is returned by the weekly endpoints as `notices` and shown on the Weekly
  Plan screen;
- the same rule applies in chat and in weekly generation and adaptation, because both run tools through
  the same guard.

Not affected: bodyweight and duration-based exercises (they carry no load), loads that are not above a
real baseline, and a weight **the user wrote themselves** in an instruction that asked for the change
("add goblet squats at 16 kg"): that number, and only that number, may start an exercise. Quoted or
pasted numbers, numbers in a request for a session or a report, and any other number are not the user's
choice and are not taken. A planned load on a different day is not a baseline for a new day.

## Progression in future planning (Phase 5C)

`AdaptivePlanningService` prepares the read-only view of what could change in upcoming workouts. It
decides nothing: every decision, number, reason and proposal is the engine's, through the same items
the approval flow issues and re-checks, so the Weekly Plan screen, the coach's tool and plan generation
cannot disagree.

- One item per exact exercise, with one plain state: `suggested`, `kept` (declined; remembered),
  `needs_review`, `insufficient_data`, `no_change` or `unavailable` (a change was calculated but cannot
  be applied, for example the session is already started).
- A suggestion is only for the exercise's **next** upcoming session. Later sessions with the same
  exercise are listed as untouched and are reviewed after that one: nothing is changed with them.
- Each suggestion is approved on its own through the approval endpoint, which recomputes eligibility,
  checks the plan row's revision and the evidence, respects dismissals, and applies one load atomically.
  There is no batch approval, and approving one exercise never approves another.
- Started and completed sessions are never adjusted. With nothing eligible the plan is left alone.
- No model is involved in calculating or proposing, and nothing runs in the background.

## Automatic progression: a possible future extension (NOT implemented)

Nothing here applies a progression unattended: there are no scheduled tasks or queued jobs that touch
plans, and the model has no way to apply a recommendation. If an automatic mode is ever wanted, the
shape that would keep these guarantees is:

- a disabled feature flag (default off) and an explicit per-user opt-in, revocable at any time;
- it may only apply what an approval would apply: the engine's own `INCREASE` or `DECREASE`, through
  the same revalidation, the same plan-row revision check, the same atomic write and the same
  maximum permitted change, never a number from the model;
- it must honour dismissals, pain, soreness and restrictions exactly as approval does, apply at most
  one exercise per session, never touch started or completed sessions, and tell the user afterwards
  what changed and how to undo it;
- it needs its own evaluation (with a real model for the explanations) and an audit trail before it is
  switched on for anyone.

None of that exists in code; it is recorded here so the design constraints are not lost.

## Write authorization

Whether a chat turn may change a plan is decided in code from the user's message (see
`App\Services\Ai\Authorization`), not by the prompt:

| The user's message | What it can do |
|---|---|
| A question (advice, analysis, "should I ...?") | Nothing. |
| A negation ("I didn't update my plan"), a quoted or pasted instruction, or an instruction attributed to someone else ("the note says: change my plan"), or a message too long to be a direct instruction | Nothing. |
| An explicit request ("update my plan ...") | Create, adapt and mark progress (still capped by the maximum permitted change). |
| A request for a session ("what should I do today?") | Write that day's plan. |
| A report of something already done ("I played soccer yesterday") | Mark progress and adapt the week. |
| A wish or news about the future ("I want to go up to 45 kg on bench next session", "I have soccer tomorrow") | Nothing. The application either builds one precise proposal to confirm, or asks for an explicit request. |
| A bare "yes" | Nothing by itself. It can only redeem a proposal the application itself presented. |

### Confirmations

A confirmation authorizes **only the operation the user was shown**.

- The application builds the proposal from the **user's own words and records**, never from model
  output: the exact exercise (matched to one of the user's pending upcoming exercises), the exact load,
  the exact plan and day, and sets/reps only if the user named them. If any of that cannot be pinned
  down (pounds, "Friday", "the week after", two exercises, an unclear name, no such session, a load above
  the maximum permitted change), **no proposal and no generic "yes" is offered**; the user is asked to send
  an explicit request instead.
- The proposal is signed (HMAC with the application key), bound to the user and conversation, valid for
  **15 minutes**, and stored in the `meta` of the assistant message that showed it. Only server code writes
  message meta; the client can send only the text of a message.
- A "yes" redeems it only when it is the very next message after that presentation. Redeeming is one
  conditional database update ("mark consumed where not yet consumed"), so of any number of simultaneous
  "yes" replies exactly one receives the proposal. Expired, forged, edited, cross-user, cross-conversation
  and already-used confirmations grant nothing and leave nothing reusable. The proposal is spent on
  redemption even if the change then fails.
- At tool dispatch the write must be **exactly** the confirmed change against the plan as it stands: same
  plan and day, same exercises in the same order, every other target unchanged, the confirmed exercise at
  the confirmed load. A different plan, exercise, date, or 47.5 kg instead of 45 is refused. The change
  itself is then applied by the application from the signed proposal (it updates that one exercise's
  target and nothing else), not from the model's arguments. Only `create_workout_plan` is available under
  a confirmation, and only once.

### Limits of the intent classifier

The classifier is **keyword matching, not understanding**. It fails closed, and nothing here makes it
universally reliable.

- **English only.** Messages in any other language grant nothing and produce no prompt: the plan is simply
  not changed until the user writes an explicit request the classifier reads. This is a known gap for the
  **localization phase**, which needs its own phrase lists, tests and a real-model evaluation per language;
  it must not be added by translating the patterns alone.
- Misspellings, slang, unusual word order and indirect requests are not recognised ("updte my plan" does
  nothing). Attribution and quote detection are also lexical, so a legitimate request that mentions a
  trainer or a note in the same sentence is refused until it is reworded.
- A false positive is possible in principle. The protections that do not depend on the classifier are the
  maximum permitted change at dispatch, signed single-use confirmations that match one exact change, and
  the fact that questions never write.
- It has not been evaluated against real users' phrasing or the real model. The `ai_coach.tool_blocked`
  log shows how often it refuses.

## What the approval flow depends on (operations)

- **`APP_KEY`.** Approval proposals are sealed with it and chat proposals are signed with it. Nothing else
  in the app needed it before, so a missing or malformed key can go unnoticed until someone opens the Weekly
  Plan screen. The key must be a valid key for the configured cipher (`php artisan key:generate --show`
  writes one) and must **not be rotated** casually: outstanding proposals stop working (harmless; they last
  30 minutes or less), and so would anything else sealed with it.
  If it is unusable the progression endpoints answer **503** with a plain message and nothing is sealed
  or signed; chat offers no confirmation it could not honour; the server log gets one `app_key.unusable`
  error (at most one a minute, shared across workers through the cache) saying what to fix; the rest of the app is unaffected. This is
  a safety net. Verify the key in each environment.
- **A cache that supports atomic "add"** (`CACHE_STORE=database` does). It is one of two layers that keep
  an approval single-use; the other, the conditional database write, holds on its own.
- **Rate limits.** The progression endpoints use their own named limiters (`progression-read`, 30 a minute,
  and `progression-decision`, 20 a minute shared by accept and keep), keyed by user. Laravel's plain
  `throttle:N,M` shares one counter per user across every route that uses it, which is why these are named:
  reviewing suggestions cannot use up the chat budget (`throttle:10,1`, unchanged), and chat cannot use up theirs.
  Chat, weekly adaptation and image polling still share the plain counter with each other, as before.

## What is not here

Applying a recommendation, an approval UI, per-exercise equipment or body-region knowledge, effort
tracking, deload scheduling, and any use of this on real users without the above. The engine has not been
evaluated with a real model; the read-only coach tool that exposes it ships switched off
(`AI_PROGRESSION_TOOL_ENABLED`).
