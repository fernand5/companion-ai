/**
 * Fallback errors the front end writes itself, for when the server gave no message (it was unreachable, or answered
 * with something unexpected). A message the SERVER wrote is shown as it came and is never matched against this text.
 */
export const errors = {
  loginFailed: "Couldn't log in — please try again.",
  registerFailed: 'Could not create your account — check the fields and try again.',
  profileLoad: "Couldn't load your profile.",
  coachingSave: "Couldn't save your coaching style. Please try again.",
  dashboardLoad: "Couldn't load your dashboard.",
  progressLoad: "Couldn't load your progress.",
  adherenceLoad: "Couldn't load your adherence data.",
  weeklySummaryLoad: "Couldn't load your weekly summary.",
  activityLoad: "Couldn't load your activity history.",
  todayPlanLoad: "Couldn't load today's plan.",
  exerciseUpdate: "Couldn't update that exercise — please try again.",
  performanceSave: "Couldn't save those sets — please try again.",
  weeklyPlanLoad: "Couldn't load your weekly plan.",
  weekFill: "Couldn't fill in the rest of your week.",
  coachUnreachable: "Couldn't reach your coach — please try again.",
  chatLoad: "Couldn't load your coach conversation.",
  progressionLoad: "Couldn't load your progression recommendations.",
  progressionApply: "Couldn't apply that recommendation — please try again.",
  progressionKeep: "Couldn't save that — please try again.",
}

export type Errors = typeof errors
