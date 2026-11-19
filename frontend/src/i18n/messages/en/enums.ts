/**
 * Words for the stored identifiers (activity type, plan status, day state, progression decision ...). The key is the
 * identifier exactly as the server stores and sends it; it is never translated, compared by label or sent back.
 */
export const enums = {
  activityType: {
    steps: 'Steps',
    treadmill: 'Treadmill',
    strength: 'Strength',
    sport: 'Sport',
    recovery: 'Recovery',
    weight: 'Weight',
  },
  intensity: {
    low: 'Low',
    moderate: 'Moderate',
    high: 'High',
  },
  planStatus: {
    planned: 'planned',
    in_progress: 'in progress',
    completed: 'completed',
    partial: 'partial',
    skipped: 'skipped',
  },
  recordedAs: {
    as_planned: 'as planned',
    migrated: 'earlier log',
  },
  dayState: {
    completed: 'Completed',
    partial: 'Partly done',
    in_progress: 'In progress',
    planned: 'Planned',
    not_marked_done: 'Not marked done',
    skipped: 'Skipped',
    rest: 'Rest day',
    activity_only: 'Unplanned activity',
    nothing: 'Nothing planned',
  },
}

export type Enums = typeof enums
