/**
 * Mirrors the backend's WeeklyPlanService::weekRange() exactly: Monday
 * through Sunday, computed in UTC (the app's "current week" is server-clock
 * UTC everywhere, not the viewer's local timezone — see that service's
 * docblock) so the week shown here always lines up with the week the coach
 * actually reasons about.
 */
function toDateString(date: Date): string {
  return date.toISOString().slice(0, 10)
}

export function getCurrentWeekRange(): { start: string; end: string; dates: string[] } {
  const now = new Date()
  const dayOfWeek = now.getUTCDay() // 0 = Sunday, 1 = Monday, ...
  const diffToMonday = (dayOfWeek + 6) % 7

  const monday = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - diffToMonday))

  const dates = Array.from({ length: 7 }, (_, i) => {
    const d = new Date(monday)
    d.setUTCDate(monday.getUTCDate() + i)
    return toDateString(d)
  })

  return { start: dates[0], end: dates[6], dates }
}

export function todayUtc(): string {
  return toDateString(new Date())
}
