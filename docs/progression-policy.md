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

An AI-written plan (`create_workout_plan`, `propose_weekly_plan_changes`) may not carry a load more than
10% above the heaviest load the user has recorded (`entered` or `migrated`, last 180 days) for that exact
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

## What is not here

Applying a recommendation, an approval UI, per-exercise equipment or body-region knowledge, effort
tracking, deload scheduling, and any use of this on real users without the above. The engine has not been
evaluated with a real model; the read-only coach tool that exposes it ships switched off
(`AI_PROGRESSION_TOOL_ENABLED`).
