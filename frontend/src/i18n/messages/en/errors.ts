/**
 * Fallback errors the front end writes itself, for when the server gave no message (it was unreachable, or answered
 * with something unexpected). A message the SERVER wrote is shown as it came and is never matched against this text.
 */
export const errors = {
  loginFailed: "Couldn't log in — please try again.",
  registerFailed: 'Could not create your account — check the fields and try again.',
  profileLoad: "Couldn't load your profile.",
  coachingSave: "Couldn't save your coaching style. Please try again.",
}

export type Errors = typeof errors
