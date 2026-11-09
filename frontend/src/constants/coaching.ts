import type { CoachingPreferences, CoachStyle, ResponseDetail } from '@/types'

/** What a user who never chose gets (matches the server's defaults). */
export const DEFAULT_COACHING: CoachingPreferences = {
  coach_style: 'balanced',
  response_detail: 'standard',
}

export const COACH_STYLES: ReadonlyArray<{ value: CoachStyle; label: string; description: string }> = [
  {
    value: 'supportive',
    label: 'Supportive',
    description: 'Empathetic, encouraging and patient. Goes at your pace.',
  },
  {
    value: 'balanced',
    label: 'Balanced',
    description: 'Practical, motivating and direct. Plain guidance on what to do and why.',
  },
  {
    value: 'intense',
    label: 'Intense',
    description: 'Energetic, challenging and accountable. Holds you to your plan.',
  },
]

export const RESPONSE_DETAILS: ReadonlyArray<{ value: ResponseDetail; label: string; description: string }> = [
  { value: 'concise', label: 'Concise', description: 'Short answers: the point and one next step.' },
  { value: 'standard', label: 'Standard', description: 'The answer, a couple of reasons and the next step.' },
  { value: 'detailed', label: 'Detailed', description: 'Fuller reasoning from your recorded data.' },
]

export function coachStyleLabel(value: CoachStyle): string {
  return COACH_STYLES.find((s) => s.value === value)?.label ?? value
}

export function responseDetailLabel(value: ResponseDetail): string {
  return RESPONSE_DETAILS.find((d) => d.value === value)?.label ?? value
}

/** The situation every sample answers. */
export const SAMPLE_PROMPT = 'I missed Tuesday’s workout and my knee feels a bit sore.'

/**
 * Written by hand, not generated: choosing a style never calls the AI. Every sample, including the
 * Intense ones, rests the sore knee and does not make up the missed session with extra work, because
 * the style changes how the coach sounds, never what it recommends.
 */
export const COACH_SAMPLES: Record<CoachStyle, Record<ResponseDetail, string>> = {
  supportive: {
    concise:
      'That’s okay, it happens. With a sore knee, rest today and ease back in when it feels better.',
    standard:
      'That’s completely okay, missing a day happens and listening to your body is the right call. Rest your knee today and see how it feels tomorrow. If it’s better, we’ll pick up with Thursday’s session as planned.',
    detailed:
      'That’s completely okay, missing a day happens and listening to your body is the right call. Your knee is the priority: rest it today and notice how it feels tomorrow morning. If it settles, we’ll pick up with Thursday’s session exactly as planned, with no catching up. If it still bothers you, we’ll keep things light and gentle, and it’s worth checking in with a professional if it lingers or gets worse.',
  },
  balanced: {
    concise: 'Skip the catch-up. Rest the knee today; if it feels fine tomorrow, do Thursday as planned.',
    standard:
      'No need to make it up. Rest the knee today and see how it feels tomorrow. If it’s fine, do Thursday’s session as planned; if not, we’ll keep it light.',
    detailed:
      'No need to make up Tuesday, and extra volume now would not help a sore knee. Rest it today and check how it feels tomorrow morning. If it’s fine, do Thursday as planned. If it’s still sore, we’ll swap in lower-impact work and keep your loads where they are. If it persists or gets worse, get it looked at.',
  },
  intense: {
    concise: 'Missed session noted. No doubling up and no training through knee pain: rest today, then be ready Thursday.',
    standard:
      'Missed session noted, and it’s behind us. No doubling up and no training through knee pain: rest today, check it tomorrow, and if it’s fine, show up Thursday ready to work at the planned loads.',
    detailed:
      'Missed session noted, and it’s behind us. Here’s the standard: no doubling up to make up for it, and no training through knee pain. Rest today and check the knee tomorrow. If it’s fine, show up Thursday ready to work and hit the planned loads, not more. If it’s still sore, we adjust the plan rather than push through, and if it persists, get it looked at. Accountability means doing the plan well, not doing extra.',
  },
}
