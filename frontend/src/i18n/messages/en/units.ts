/**
 * The words around a number. Units that are symbols (kg, km, km/h, /km, s) are not translated and stay in the code; a
 * stored value is never converted or rounded, only shown.
 */
export const units = {
  steps: '{n} step | {n} steps',
  minutes: '{n} min',
  reps: '{n} reps',
  sets: '{n} sets',
  notSet: 'Not set',
  notCompleted: '(not completed)',
}

export type Units = typeof units
