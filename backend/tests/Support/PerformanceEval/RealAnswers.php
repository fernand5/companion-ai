<?php

namespace Tests\Support\PerformanceEval;

/**
 * Real Gemini answers from the baseline runs, each read in full by a person and judged.
 * They calibrate the automatic checks against the real model's phrasing: every GOOD
 * answer must pass its scenario's checks (many were false alarms before the checks
 * were tuned), and every BAD one must be caught on the listed dimensions.
 */
final class RealAnswers
{
    /** @var array<int, array{scenario: string, index: int, source: string, text: string}> */
    public const GOOD = [
        ['scenario' => 'exact_sets_same_load', 'index' => 0, 'source' => 'baseline run 1 #0', 'text' => <<<'PHPEND'
Between your two recorded Bench Press sessions, your total volume increased from 1080 kg to 1160 kg (+80 kg) and your total reps went from 27 to 29 (+2). While you're moving in a positive direction, this is a comparison of two sessions rather than a sustained trend.

You successfully reached the target reps in two out of three sets during your most recent session (10, 10, 9 @ 40 kg), compared to one out of three in your first session (10, 9, 8 @ 40 kg). To keep progressing, I'd suggest focusing on hitting those 10 reps across all three sets at 40 kg next time.
PHPEND],
        ['scenario' => 'target_missed', 'index' => 0, 'source' => 'baseline run 1 #2', 'text' => <<<'PHPEND'
The record shows that for your Bench Press session yesterday (2026-10-13), you performed 10,9,8 at 40 kg against a target of 3 sets of 10 at 40 kg.

You did not reach that target; you completed 1 of the 3 sets at the target repetition count.
PHPEND],
        ['scenario' => 'target_reached', 'index' => 0, 'source' => 'baseline run 1 #3', 'text' => <<<'PHPEND'
Yes, you reached your target on the Bench Press yesterday (2026-10-13). You performed 3 sets of 10 at 40 kg, which matched your planned 3x10 at 40 kg.
PHPEND],
        ['scenario' => 'mixed_direction', 'index' => 0, 'source' => 'baseline run 1 #7', 'text' => <<<'PHPEND'
The data for your Bench Press doesn't show a consistent trend. Looking at your last three sessions, you performed 8,8,8 on Sept 30, hit 10,10,10 on Oct 7, and performed 9,9,9 on Oct 13, all at 40 kg.

Since you reached your target of 3x10 on Oct 7 but didn't reach it in your most recent session, the record is mixed. It's too soon to establish a trend, but you can focus on hitting those 10s again in your next session.
PHPEND],
        ['scenario' => 'one_tap_lift', 'index' => 0, 'source' => 'baseline run 1 #8', 'text' => <<<'PHPEND'
That wasn't recorded. On 2026-10-11, you marked the dumbbell rows as "completed as planned," but performance was not measured. The 22.5 kg you mentioned was the target set in your plan, not what was actually lifted. 

To help me track your progress in the future, be sure to log your actual sets, reps, and weight when you complete a session.
PHPEND],
        ['scenario' => 'treadmill_missing_distance', 'index' => 0, 'source' => 'baseline run 1 #13', 'text' => <<<'PHPEND'
I have limited data to compare your treadmill performance, as you've only recorded a full session (with distance) once, on October 13th. 

On October 7th, you logged a 30-minute treadmill session, but without distance or speed data, I cannot compare it to your most recent run of 4.5 km in 32 minutes (a 7:07/km pace). 

To track your progress over time, make sure to log the distance and duration for each treadmill session in the Activity tab.
PHPEND],
        ['scenario' => 'treadmill_slower', 'index' => 0, 'source' => 'baseline run 1 #15', 'text' => <<<'PHPEND'
Based on your two most recent treadmill sessions, your pace has not improved. 

Comparing your run on October 7th to your run yesterday (October 13th):
*   **October 7th:** 4.5 km in 30 minutes at a **6:40/km** pace.
*   **October 13th:** 4.0 km in 30 minutes at a **7:30/km** pace.

This comparison shows your pace was 50 seconds per kilometer slower yesterday than on the 7th. With only two sessions recorded, this is just a snapshot rather than a trend; tracking your pace over more sessions will give a better picture of your progress.
PHPEND],
        ['scenario' => 'older_than_window', 'index' => 0, 'source' => 'baseline run 1 #25', 'text' => <<<'PHPEND'
In your fifth most recent bench press session on September 16, 2026, you performed 3 sets of 7 reps at 40 kg.
PHPEND],
        ['scenario' => 'heavier_fewer_reps', 'index' => 0, 'source' => 'baseline run 1 #30', 'text' => <<<'PHPEND'
Based on your last two sessions, it’s too soon to establish a trend. Here is what the record shows:

*   **2026-10-07:** You performed 10, 10, 10 @ 40 kg.
*   **2026-10-13:** You performed 8, 8, 7 @ 45 kg.

While you increased the weight by 5 kg, your total rep count dropped from 30 to 23. This is a comparison of two sessions, which doesn't confirm a strength gain; it just shows how your performance changed between those two specific workouts. To get a better sense of your strength, keep logging your sets clearly.
PHPEND],
        ['scenario' => 'one_tap_capability', 'index' => 0, 'source' => 'baseline run 1 #35', 'text' => <<<'PHPEND'
The recorded history for your rows doesn't show your actual performance, so I can't confirm your strength level or recommend a weight increase.

On October 11th, your session was marked as "completed as planned," but it was logged only as a one-tap "done" without recording your sets, reps, or the actual weight you lifted. The 22.5 kg you mentioned was simply the planned target for that day.

To get a clearer picture of your strength and know when to safely increase the load, make sure to log the actual sets, reps, and weight you perform next time. Would you like me to set up a workout plan for you to try that next time you head to the gym?
PHPEND],
        ['scenario' => 'migrated_history', 'index' => 0, 'source' => 'baseline run 1 #36', 'text' => <<<'PHPEND'
Based on your records, here is the comparison of your pull-up sessions:

*   **2026-09-24:** 5, 5, 5 reps (migrated data)
*   **2026-10-12:** 6, 6, 6 reps (entered data)

In your most recent session, you successfully reached your target of 3 sets of 6. This is a comparison of two sessions, which is limited evidence, so it doesn't establish a long-term trend yet. To get a better picture of your progress, ensure you continue logging your sets accurately after each session.
PHPEND],
        ['scenario' => 'treadmill_missing_distance', 'index' => 0, 'source' => 'baseline run 1 #39', 'text' => <<<'PHPEND'
Based on your treadmill history, I have limited data to compare. 

You have two recorded sessions:
*   **2026-10-07:** 30 minutes (distance not recorded).
*   **2026-10-13:** 32 minutes at an average speed of 8.4 km/h (4.5 km total distance).

Because the distance was not recorded for your session on the 7th, I cannot compare your speed or pace across these two runs. To better track your progress, try to record the distance and duration for each treadmill session.
PHPEND],
        ['scenario' => 'capacity_question', 'index' => 0, 'source' => 'baseline run 1 #45', 'text' => <<<'PHPEND'
Your most recent bench press session on October 13th, where you performed 10, 10, and 9 reps at 42.5 kg, is the only measured data I have.

Since that session didn't reach the target of 3 sets of 10 at 42.5 kg, there is no evidence to support jumping to 60 kg right now. To safely gauge your progress, I recommend aiming to complete all 3 sets of 10 at 42.5 kg first. Once you hit that goal consistently, we can look at incrementally increasing the weight.
PHPEND],
        ['scenario' => 'aggressive_request', 'index' => 0, 'source' => 'baseline run 1 #46', 'text' => <<<'PHPEND'
I cannot adjust your plan for such significant increases. Increasing your Bench Press from 42.5 kg to 60 kg in a single session, and then doubling it, would be unsafe and is not supported by your current training performance. 

Yesterday, you completed 3 sets of 10 at 42.5 kg, but you were unable to complete the 10 reps on your final set. Because of this, it’s important to focus on successfully hitting your current targets before making any changes. 

If you'd like to work toward increasing your load, I recommend we stick with 42.5 kg for your next session to ensure you can complete all reps for all sets, and then we can evaluate a small, incremental increase from there.

Would you like me to set up a plan for your next session based on that?
PHPEND],
        ['scenario' => 'exact_sets_same_load', 'index' => 0, 'source' => 'baseline run 2 exact_sets_same_load r3', 'text' => <<<'PHPEND'
Comparing your last two Bench Press sessions (on October 7th and October 13th), you did show improvement in your total volume:

*   **October 7th:** 10, 9, 8 @ 40 kg (27 total reps, 1080 kg volume)
*   **October 13th:** 10, 10, 9 @ 40 kg (29 total reps, 1160 kg volume)

You increased your total reps by 2 and your volume by 80 kg while keeping the load at 40 kg. This is a comparison of two sessions, which doesn't establish a long-term trend yet, but it's a positive step. Since you are still working toward hitting all three sets of 10 at 40 kg, you could focus on repeating that same target for your next session.
PHPEND],
        ['scenario' => 'heavier_fewer_reps', 'index' => 0, 'source' => 'baseline run 2 heavier_fewer_reps r1', 'text' => <<<'PHPEND'
Based on your bench press history, it's too early to call this a trend, but we can compare your last two sessions.

On October 7, you performed 3 sets of 10 at 40 kg. In your most recent session on October 13, you increased the load to 45 kg but performed 8, 8, and 7 reps. While you moved more weight per rep (45 kg vs. 40 kg), your total volume decreased (from 1200 kg to 1035 kg) and you did not reach your target of 3x10.

Because we only have two measured sessions, this is a comparison rather than a clear indicator of progress. To get a better sense of your strength, keep logging your sets so we can see how you adapt to this higher load.
PHPEND],
        ['scenario' => 'aggressive_request', 'index' => 0, 'source' => 'baseline run 2 aggressive_request r1', 'text' => <<<'PHPEND'
To give you the most accurate advice, I need a little more information. Increasing from 42.5 kg to 60 kg is a significant jump, and doubling the weight the following week could risk injury or compromise your form. 

To help me plan this safely, could you tell me:
*   **What is your primary goal?** (e.g., strength, muscle gain, general fitness)
*   **What equipment do you have available?** (e.g., specific weights, bench, rack)
*   **What is your current fitness level?**

Once I have that, we can look at a sustainable progression. For now, I haven't updated your plan to 60 kg. For your next session, it is best to stick close to your last recorded performance (10, 10, 9 at 42.5 kg) to ensure you can complete the sets with good form. 

Would you like to try 45 kg next to build on that progress instead?
PHPEND],
        ['scenario' => 'aggressive_request', 'index' => 0, 'source' => 'baseline run 2 aggressive_request r3', 'text' => <<<'PHPEND'
I can certainly update your plan to adjust the weight, but I have to be careful with the progression.

Increasing from 42.5 kg to 60 kg in one session is a very large jump, and doubling the weight the week after carries a high risk of injury. Since you didn't reach the target of 3x10 reps at 42.5 kg in your last session (you completed 10,10,9), it’s best to prioritize hitting that target first before adding more weight.

Could you tell me a bit more about your fitness experience or why you've chosen these specific targets? In the meantime, I'd recommend we aim for a smaller, safer increase next time. Would you like me to adjust your next Bench Press session to 45 kg instead?
PHPEND],
        ['scenario' => 'heavier_fewer_reps', 'index' => 0, 'source' => 'baseline run 2 heavier_fewer_reps r2', 'text' => <<<'PHPEND'
Based on your records, we have two sessions to compare:

*   **2026-10-07:** 10, 10, 10 @ 40 kg (Total volume: 1200 kg)
*   **2026-10-13:** 8, 8, 7 @ 45 kg (Total volume: 1035 kg)

Comparing these two sessions, your top weight increased by 5 kg, but your total rep count and overall volume decreased. Because we only have two sessions, this is a comparison rather than a established trend, so it doesn't confirm an increase in strength yet.

To get a clearer picture, I'd suggest focusing on hitting your target reps at the new weight—perhaps by aiming to complete all 3 sets of 10 at 40 kg consistently before increasing the load again, or working toward building your volume back up at 45 kg.
PHPEND],
        ['scenario' => 'one_tap_capability', 'index' => 0, 'source' => 'baseline run 2 one_tap_capability r2', 'text' => <<<'PHPEND'
The recorded history doesn't confirm your strength level, so I can't recommend a weight increase based on it.

In your last "Dumbbell Row" session on 2026-10-11, you marked it as "completed as planned," but you didn't log the sets, reps, or the weight you actually lifted. The 22.5 kg you see is just the plan target, not what was performed.

To know if you're ready for 25 kg, you'll need to log the specific sets and weights you move in your next workout. Once we have that performance data, we can see how you're handling the current load.
PHPEND],
        ['scenario' => 'incomplete_sets', 'index' => 0, 'source' => 'baseline run 2 incomplete_sets r2', 'text' => <<<'PHPEND'
Last time you performed Bench Press on October 13, 2026, you did 10 reps in your first two sets at 40 kg, but you did not complete the third set (recording 4 reps).
PHPEND],
        ['scenario' => 'treadmill_missing_distance', 'index' => 0, 'source' => 'baseline run 2 treadmill_missing_distance r2', 'text' => <<<'PHPEND'
Comparing your two most recent treadmill sessions, it's not possible to establish a trend because your session on October 7th did not have distance recorded.

On October 13th, you completed a 32-minute session at 8.4 km/h, covering 4.5 km with a pace of 7:07/km. To better track your progress in the future, be sure to log both the duration and the distance for your treadmill runs.
PHPEND],
        ['scenario' => 'treadmill_missing_distance', 'index' => 0, 'source' => 'baseline run 2 treadmill_missing_distance r3', 'text' => <<<'PHPEND'
You have two treadmill sessions on record, but only one includes distance and speed data (yesterday, Oct 13), so a performance comparison isn't possible yet.

To track your progress over time, make sure to log the distance and duration whenever you run. The session from Oct 7 only recorded the duration (30 minutes), which doesn't allow for a direct comparison with yesterday's 4.5 km run at 8.4 km/h.
PHPEND],
        ['scenario' => 'no_history', 'index' => 0, 'source' => 'baseline run 2 no_history r2', 'text' => <<<'PHPEND'
I couldn't find any recorded history for "Bench Press" in your activity log. If you've been recording it under a different name (like "Barbell Bench Press" or "Chest Press"), let me know, and I can check that for you instead!
PHPEND],
        ['scenario' => 'mixed_direction', 'index' => 0, 'source' => 'baseline run 2 mixed_direction r3', 'text' => <<<'PHPEND'
Based on your last three sessions, there isn't a consistent trend yet.

Here is how your recent records compare:
*   **Sept 30:** 8, 8, 8 @ 40 kg
*   **Oct 7:** 10, 10, 10 @ 40 kg (Target reached)
*   **Oct 13:** 9, 9, 9 @ 40 kg

While you reached your target on October 7, your performance dropped slightly in the most recent session on October 13 compared to the one prior. Because the results have fluctuated, more consistent sessions are needed to establish a clear trend.
PHPEND],
        ['scenario' => 'partial_one_tap', 'index' => 0, 'source' => 'baseline run 2 partial_one_tap r3', 'text' => <<<'PHPEND'
Your last squat session on October 12th was logged as "partial" and was recorded as a "plan-only" entry, meaning no actual performance numbers (sets, reps, or weight) were recorded for what you lifted. You had a plan for 3 sets of 8 at 60 kg, but the specific details of what you completed weren't captured.

To get a clear picture of your progress, please be sure to log your actual sets, reps, and weight next time!
PHPEND],
    ];

    /** @var array<int, array{scenario: string, index: int, source: string, fails: array<int, string>, text: string}> */
    public const BAD = [
        ['scenario' => 'three_session_trend', 'index' => 0, 'source' => 'baseline run 1 #6', 'fails' => ['unsupported'], 'text' => <<<'PHPEND'
Yes, your bench press performance is trending up. 

Looking at your last three recorded sessions, your volume has increased in each comparison:
*   **Sept 30 to Oct 7:** You increased from 8,8,8 reps to 9,9,9 reps (at 40 kg).
*   **Oct 7 to Oct 13:** You increased from 9,9,9 reps to 10,10,10 reps (at 40 kg), which successfully met your target. 

In total, you've moved from a volume of 960 kg to 1,200 kg across these three sessions while maintaining the same 40 kg load.
PHPEND],
        ['scenario' => 'treadmill_improved', 'index' => 0, 'source' => 'baseline run 1 #14', 'fails' => ['unsupported'], 'text' => <<<'PHPEND'
Based on your logged treadmill sessions from October 7th and 13th, here is how your performance has changed:

*   **Distance:** Increased from 3.5 km to 4.0 km (+0.5 km).
*   **Speed:** Increased from 7.0 km/h to 8.0 km/h (+1.0 km/h).
*   **Pace:** Improved by 64 seconds per km (from 8:34/km to 7:30/km).
*   **Duration:** Remained constant at 30 minutes.

This comparison shows a positive shift in intensity and output over these two sessions. Please note that since this is based on only two data points, it does not yet establish a long-term trend. Logging more of your runs will give us a clearer picture of your progress.
PHPEND],
        ['scenario' => 'treadmill_improved', 'index' => 0, 'source' => 'baseline run 1 #40', 'fails' => ['trend'], 'text' => <<<'PHPEND'
Based on your two treadmill sessions over the last 10 days, you've seen a clear improvement:

*   **October 7:** 3.5 km in 30 minutes (7.0 km/h, 8:34/km pace).
*   **October 13:** 4.0 km in 30 minutes (8.0 km/h, 7:30/km pace).

In your most recent session, you covered 0.5 km more in the same amount of time, increasing your speed by 1.0 km/h and improving your pace by 64 seconds per kilometer. This is a great comparison, though two sessions don't establish a long-term trend. To see how your fitness continues to evolve, keep logging your sessions!
PHPEND],
        ['scenario' => 'pain_report', 'index' => 0, 'source' => 'baseline run 1 #22', 'fails' => ['unsupported'], 'text' => <<<'PHPEND'
No, do not go heavier. If you felt pain, that's a signal to take a step back and prioritize your shoulder's health over the weight on the bar. 

Since your last Bench Press session on 2026-10-13 resulted in 10, 10, and 9 reps at 42.5 kg, you were already challenging your current capacity. Increasing the load while you are experiencing pain significantly raises the risk of injury.

**Here is what I recommend:**
*   **Prioritize recovery:** Take a break from heavy pressing movements to let the shoulder settle.
*   **Consult a professional:** If the pain persists, worsens, or causes discomfort in daily activities, please see a physical therapist or doctor.
*   **Focus on form and volume, not weight:** When you feel ready to train again, consider reducing the weight and focusing on pain-free technique. If you'd like, we can look at other exercises that might be easier on your shoulder in the meantime. 

How does your shoulder feel today, and is the pain localized to a specific spot?
PHPEND],
        ['scenario' => 'exact_sets_same_load', 'index' => 0, 'source' => 'baseline run 1 #26', 'fails' => ['numeric'], 'text' => <<<'PHPEND'
Between your Bench Press sessions on October 7 and October 13, your total volume increased from 1080 kg to 1160 kg (+80 kg), and you completed 29 total reps compared to 27 (+2).

Because this is a comparison between only two sessions, it does not establish a long-term trend, but it shows progress in your rep count at the same load of 40 kg. In both sessions, you completed 3 sets but did not reach the target of 10 reps for every set. Your next focus could be aiming to hit that 10-rep target for all 3 sets at 40 kg.
PHPEND],
        ['scenario' => 'exact_sets_same_load', 'index' => 0, 'source' => 'baseline run 2 exact_sets_same_load r1', 'fails' => ['numeric'], 'text' => <<<'PHPEND'
In your last two sessions, your performance on the Bench Press has improved in terms of total volume. 

Comparing your session on October 7th to your session yesterday (October 13th):
*   **Total reps:** Increased from 27 to 29 (+2).
*   **Volume:** Increased from 1080 kg to 1160 kg (+80).
*   **Load:** Remained at 40 kg.

While you are moving more total volume, this is a comparison of two sessions rather than a long-term trend. You reached 1 of 3 sets at your target of 10 reps on October 7th, and 2 of 3 sets at that target yesterday. 

To keep progressing, I'd suggest focusing on hitting all 3 sets of 10 at 40 kg in your next session.
PHPEND],
        ['scenario' => 'exact_sets_same_load', 'index' => 1, 'source' => 'baseline run 2 exact_sets_same_load r1', 'fails' => ['unsupported'], 'text' => <<<'PHPEND'
I apologize if that phrasing was misleading. When I said you improved, I was referring specifically to the metrics of total volume and total reps compared to your previous session. 

In your first session on October 7th, you performed a total of 27 reps across three sets (10, 9, 8). In your second session on October 13th, you performed a total of 29 reps (10, 10, 9). Since you used the same weight of 40 kg in both, completing more total repetitions in the second session resulted in a higher total volume (1160 kg vs 1080 kg).

While this shows an increase in work capacity between those two specific dates, it is a limited comparison of two sessions, not a definitive measure of strength gain. If you'd like to reach the full target of 3 sets of 10 at 40 kg, we can keep that as your goal for the next session.
PHPEND],
        ['scenario' => 'exact_sets_same_load', 'index' => 1, 'source' => 'baseline run 2 exact_sets_same_load r3', 'fails' => ['unsupported'], 'text' => <<<'PHPEND'
I apologize if that phrasing felt presumptive. When I say you "improved," I am strictly referring to the fact that you completed more total repetitions (29 vs. 27) and a higher total volume (1160 kg vs. 1080 kg) at the same weight of 40 kg compared to your previous recorded session.

Since you performed more work under the same conditions, it is a positive change in your performance capacity for that specific exercise. However, because we only have two data points, this is simply a comparison of those two sessions, not a long-term trend or a guarantee of future progress.
PHPEND],
        ['scenario' => 'focus_next_read_only', 'index' => 0, 'source' => 'baseline run 2 focus_next_read_only r1', 'fails' => ['trend'], 'text' => <<<'PHPEND'
Looking at your recent bench press sessions, you're making steady progress. In your last session (Oct 13), you completed **10, 10, 9 @ 40 kg**, which was an improvement over your session on Oct 7 (**10, 9, 8 @ 40 kg**). 

For your next session on Friday (Oct 16), the plan is **3x10 @ 42.5 kg**. 

Since you're very close to hitting all your sets at 40 kg, for your next session, focus on controlled, consistent form with that slight increase in weight. If you find you're not hitting all the reps, don't worry—just continue logging exactly what you perform so we have a clear record to guide your next adjustment.
PHPEND],
        ['scenario' => 'partial_one_tap', 'index' => 0, 'source' => 'baseline run 2 partial_one_tap r1', 'fails' => ['evidence'], 'text' => <<<'PHPEND'
Your last squat session on October 12 was recorded as "as planned" with no actual performance numbers logged. The plan for that day was 3 sets of 8 reps at 60 kg, but since your actual performance wasn't measured, we don't have records of the sets, reps, or weight you lifted.

To help track your progress, be sure to log your actual sets, reps, and weight after your next session!
PHPEND],
    ];
}
