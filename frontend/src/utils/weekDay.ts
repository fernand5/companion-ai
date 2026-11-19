import { i18n } from '@/i18n'
import { formatCount, formatDateOnly } from '@/i18n/format'
import type { WeekDayState } from '@/types'

const STATE_STYLES: Record<WeekDayState, { badge: string; dot: string }> = {
  completed: { badge: 'bg-brand-100 text-brand-700', dot: 'bg-brand-600' },
  partial: { badge: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  in_progress: { badge: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  planned: { badge: 'bg-sky-100 text-sky-700', dot: 'bg-sky-500' },
  not_marked_done: { badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
  skipped: { badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
  rest: { badge: 'bg-indigo-100 text-indigo-700', dot: 'bg-indigo-400' },
  activity_only: { badge: 'bg-violet-100 text-violet-700', dot: 'bg-violet-500' },
  nothing: { badge: 'bg-slate-100 text-slate-500', dot: 'bg-slate-200' },
}

/**
 * How each kind of day is shown. The wording matters: "not marked done" is not "missed" (the user may have
 * trained and not logged it), and a day with nothing is not "rest" (only a day the plan cleared for rest is).
 * `label` is looked up each time it is read, so it is never stale after a language change.
 */
export const DAY_STATE = Object.fromEntries(
  (Object.keys(STATE_STYLES) as WeekDayState[]).map((state) => [
    state,
    {
      ...STATE_STYLES[state],
      get label(): string {
        return i18n.global.t(`enums.dayState.${state}`)
      },
    },
  ]),
) as Record<WeekDayState, { label: string; badge: string; dot: string }>

/** "Oct 26 – Nov 1". */
export function formatWeekRange(start: string, end: string): string {
  const format = (date: string) => formatDateOnly(date, { month: 'short', day: 'numeric' })

  return `${format(start)} – ${format(end)}`
}

/** "Mon", "26" for the week strip. */
export function stripLabels(date: string): { weekday: string; day: string } {
  const d = new Date(`${date}T00:00:00`)

  return {
    weekday: formatDateOnly(date, { weekday: 'short' }),
    day: String(d.getDate()),
  }
}

/** "6,000" / "6.000" for a step count. */
export function formatSteps(steps: number): string {
  return formatCount(steps)
}
