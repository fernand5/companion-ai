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
}

export type Coach = typeof coach
