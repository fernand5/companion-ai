/**
 * English is the source of truth for the message shape. `es` must provide every key (the compiler
 * checks it), and anything missing at runtime falls back to these strings.
 *
 * Only the language picker is translated in this milestone. The rest of the interface moves to
 * message keys area by area afterwards.
 */
export const en = {
  language: {
    label: 'Language',
    help: 'The language you read the app in. Your workouts, numbers and units are never changed.',
    guestHelp: 'Used on this screen. Your account keeps its own saved language when you log in.',
    registerHelp: 'This becomes your account language. You can change it later in your profile.',
    options: {
      en: 'English',
      es: 'Español',
    },
    saving: 'Saving…',
    saved: 'Saved',
    saveFailed: "Couldn't save your language. Please try again.",
  },
}

export type MessageSchema = typeof en
