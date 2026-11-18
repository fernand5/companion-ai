export const progress = {
  title: 'Progress',
  loading: 'Loading progress…',
  trends: 'Trends and history',
  adherence: 'Adherence',
  last7Days: 'Last 7 days: {n} active day(s)',
  weight: 'Weight',
  noWeight: 'No weight entries yet.',
  steps: 'Steps',
  noSteps: 'No steps logged yet.',
  workoutsPerWeek: 'Workouts per week',
  stats: {
    current: 'Current',
    starting: 'Starting',
    workoutsPerWeek: 'Workouts/wk',
    avgSteps: 'Avg steps',
  },
  chart: {
    adherence: 'Adherence %',
    weight: 'Weight (kg)',
    steps: 'Steps',
    workouts: 'Workouts',
  },
  summary: {
    title: 'Your week',
    adherence: 'Adherence',
    plannedDone: 'Planned done',
    activeDays: 'Active days',
    insight: 'Coach insight:',
    nextFocus: "Next week's focus:",
    asOf: 'As of {date}',
  },
}

export type Progress = typeof progress
