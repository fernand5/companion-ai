import type { WeekDayState } from '@/types'

/**
 * How each kind of day is shown. The wording matters: "not marked done" is not "missed" (the user may have
 * trained and not logged it), and a day with nothing is not "rest" (only a day the plan cleared for rest is).
 */
export const DAY_STATE: Record<WeekDayState, { label: string; badge: string; dot: string }> = {
  completed: { label: 'Completed', badge: 'bg-brand-100 text-brand-700', dot: 'bg-brand-600' },
  partial: { label: 'Partly done', badge: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  in_progress: { label: 'In progress', badge: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  planned: { label: 'Planned', badge: 'bg-sky-100 text-sky-700', dot: 'bg-sky-500' },
  not_marked_done: { label: 'Not marked done', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
  skipped: { label: 'Skipped', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
  rest: { label: 'Rest day', badge: 'bg-indigo-100 text-indigo-700', dot: 'bg-indigo-400' },
  activity_only: { label: 'Unplanned activity', badge: 'bg-violet-100 text-violet-700', dot: 'bg-violet-500' },
  nothing: { label: 'Nothing planned', badge: 'bg-slate-100 text-slate-500', dot: 'bg-slate-200' },
}

/** "Oct 26 – Nov 1". */
export function formatWeekRange(start: string, end: string): string {
  const format = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })

  return `${format(start)} – ${format(end)}`
}

/** "Mon", "26" for the week strip. */
export function stripLabels(date: string): { weekday: string; day: string } {
  const d = new Date(`${date}T00:00:00`)

  return {
    weekday: d.toLocaleDateString(undefined, { weekday: 'short' }),
    day: String(d.getDate()),
  }
}
