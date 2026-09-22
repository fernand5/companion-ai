/**
 * Mirrors the backend's WeeklyPlanService::weekRange(): Monday through
 * Sunday, computed from the user's own calendar date. The timezone comes from
 * the same stored value the backend's User::localToday() reads (exposed on
 * the /me payload), so both sides always agree on "today" and "this week".
 * A missing timezone falls back to UTC, exactly as the backend does.
 */
const FALLBACK_TIMEZONE = 'UTC'

export function todayIn(timeZone: string | null | undefined): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: timeZone || FALLBACK_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

export function getCurrentWeekRange(timeZone: string | null | undefined): {
  start: string
  end: string
  dates: string[]
} {
  const [year, month, day] = todayIn(timeZone).split('-').map(Number)

  // Calendar arithmetic only — UTC here is just a stable container for a
  // plain year/month/day, not a claim about the user's timezone.
  const today = new Date(Date.UTC(year, month - 1, day))
  const diffToMonday = (today.getUTCDay() + 6) % 7

  const dates = Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(year, month - 1, day - diffToMonday + i)).toISOString().slice(0, 10),
  )

  return { start: dates[0], end: dates[6], dates }
}
