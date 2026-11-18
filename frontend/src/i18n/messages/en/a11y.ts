/** Names read by assistive technology (aria-label and similar). They are never shown as visible text. */
export const a11y = {
  primaryNavigation: 'Primary',
  removeScheduleEntry: 'Remove {activity} on {day}',
  scheduleDay: 'Day of the week',
  scheduleStartTime: 'Start time',
  scheduleDuration: 'Duration in minutes',
  scheduleIntensity: 'Intensity',
  resetPending: 'Reset to pending',
  deleteActivityLog: 'Delete activity log',
  removeExercise: 'Remove exercise',
  setReps: 'Set {n} reps',
  setWeight: 'Set {n} weight in kilograms',
  setDuration: 'Set {n} duration in seconds',
  setCompleted: 'Set {n} completed',
  removeSet: 'Remove set {n}',
}

export type A11y = typeof a11y
