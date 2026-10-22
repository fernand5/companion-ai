import type { ProgressionItem, ProgressionList, ProgressionState } from '@/types'

/** A server-shaped recommendation; every field a test cares about can be overridden. */
export function makeItem(overrides: Partial<ProgressionItem> = {}): ProgressionItem {
  return {
    exercise: { name: 'Bench Press', slug: 'bench-press' },
    decision: 'INCREASE',
    current_target: { sets: 3, reps: 10, weight_kg: 40, duration_seconds: null, source: 'next_planned' },
    proposed_target: { sets: 3, reps: 10, weight_kg: 42.5, duration_seconds: null, change_percent: 6.3, step_kg: 2.5, from: 40, to: 42.5 },
    supporting_sessions: [
      { date: '2026-10-15', recorded_as: 'entered', performed: '10,10,10 @ 40 kg', target: '3×10 @ 40 kg', target_reached: true, sets_reaching_target: 3, planned_sets: 3, top_load_kg: 40 },
      { date: '2026-10-12', recorded_as: 'entered', performed: '10,10,10 @ 40 kg', target: '3×10 @ 40 kg', target_reached: true, sets_reaching_target: 3, planned_sets: 3, top_load_kg: 40 },
      { date: '2026-10-08', recorded_as: 'entered', performed: '10,10,9 @ 40 kg', target: '3×10 @ 40 kg', target_reached: false, sets_reaching_target: 2, planned_sets: 3, top_load_kg: 40 },
    ],
    evidence_quality: 'strong',
    reason_codes: ['TARGETS_REACHED_CONSECUTIVELY'],
    reasons: ['The target was reached in each of the last 3 sessions at the same load.'],
    safety_caveats: ['Effort (RPE), repetitions in reserve and technique are not recorded: a reached target does not show the load felt manageable.'],
    policy_version: 'progression-policy-1',
    as_of: '2026-10-17',
    applies_to: { date: '2026-10-19', title: 'Upper body', plan_status: 'planned' },
    later_sessions: [],
    state: 'suggested',
    actionable: true,
    not_actionable_reason: null,
    dismissed: null,
    proposal: { id: 'sealed-proposal-1', expires_at: '2026-10-17T12:30:00+00:00' },
    ...overrides,
  }
}

export const dismissedItem = (): ProgressionItem =>
  makeItem({ actionable: false, proposal: null, not_actionable_reason: 'dismissed', state: 'kept', dismissed: { at: '2026-10-17T12:05:00+00:00' } })

export const insufficient = (): ProgressionItem =>
  makeItem({
    exercise: { name: 'Pull-up', slug: 'pull-up' },
    decision: 'INSUFFICIENT_DATA',
    proposed_target: null,
    evidence_quality: 'insufficient',
    reason_codes: ['NEED_MORE_SESSIONS'],
    reasons: ['Fewer than 3 measured sessions of this exercise.'],
    safety_caveats: [],
    actionable: false,
    not_actionable_reason: 'not_a_change',
    state: 'insufficient_data',
    proposal: null,
    applies_to: null,
  })

export const manualReview = (): ProgressionItem =>
  makeItem({
    exercise: { name: 'Overhead Press', slug: 'overhead-press' },
    decision: 'MANUAL_REVIEW',
    proposed_target: null,
    evidence_quality: 'limited',
    reason_codes: ['PAIN_REPORTED_RECENTLY'],
    reasons: ['A recovery check-in in the last 14 days mentions pain.'],
    safety_caveats: ['A recent recovery check-in mentions pain. Do not add load without checking with a qualified professional if it persists.'],
    actionable: false,
    not_actionable_reason: 'not_a_change',
    state: 'needs_review',
    proposal: null,
  })

/** A server-shaped list response, with the summary and sessions the server would derive from the items. */
export function makeList(...data: ProgressionItem[]): ProgressionList {
  const summary = Object.fromEntries((['suggested', 'kept', 'needs_review', 'insufficient_data', 'no_change', 'unavailable'] as ProgressionState[]).map((s) => [s, data.filter((i) => i.state === s).length])) as ProgressionList['summary']

  return {
    data,
    sessions: [],
    summary,
    meta: { policy_version: 'progression-policy-1', max_exercises: 8, generated_at: '2026-10-17T12:00:00+00:00' },
  }
}
