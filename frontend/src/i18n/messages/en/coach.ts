/** The AI Coach screen. What the coach and the person write is shown as written, in whatever language they used. */
export const coach = {
  title: 'Coach',
  empty: 'Say hello, or ask what to do today.',
  thinking: 'Coach is thinking…',
  placeholder: "Tell your coach what you did, or ask what's next…",
  send: 'Send',
  // Suggested messages. Picking one SENDS it as the person's own message, in this language.
  suggestions: {
    today: 'What should I do today?',
    logWorkout: 'Log today’s workout',
    logSteps: 'Log my steps',
    updateWeight: 'Update my weight',
    progress: 'How am I progressing?',
  },
  checked: 'Checked {count} thing | Checked {count} things',
  // Under a replacement question the application is holding. A button SENDS this answer as the person's own message.
  confirm: {
    yes: 'Yes, replace',
    no: 'No, keep it',
    yesMessage: 'Yes',
    noMessage: 'No',
    group: 'Answer the question above',
    expired: 'This question has expired. Ask again if you still want the change.',
  },
  // What a save actually did (shown only after the server confirmed it was stored).
  saved: {
    title: 'Saved to your plan',
    added: 'Added',
    replaced: 'Replaced',
    rest: 'Rest day',
    dashboard: 'Open Dashboard',
  },
}

export type Coach = typeof coach
