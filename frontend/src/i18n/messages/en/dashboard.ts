export const dashboard = {
  loading: 'Loading dashboard…',
  recommendation: "Today's recommendation",
  askCoach: 'Ask your coach',
  today: 'Today',
  nothingToday: 'Nothing logged yet today.',
  upcoming: 'Upcoming',
  progress: 'Progress',
  stats: {
    currentWeight: 'Current weight',
    change: 'Change',
    workoutsWeek: 'Workouts this week',
    avgSteps: 'Avg steps',
  },
  // Chosen by the server's stable reason code, never by the words of any message.
  unavailable: {
    rate_limited: 'Your coach is at its request limit for now — check back in a bit, or ask directly on the Coach tab.',
    unavailable: 'Your coach is temporarily unavailable — try again shortly, or ask directly on the Coach tab.',
    not_configured: 'Set your AI_API_KEY to get a live recommendation, or ask your coach directly on the Coach tab.',
  },
}

export type Dashboard = typeof dashboard
