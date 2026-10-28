import api from '@/services/api'
import type { WeekOverview, WeeklyAdaptationResult } from '@/types'

/** The current week, day by day: the plan, what was recorded against it, and unplanned workouts. */
export function fetchOverview() {
  return api.get<WeekOverview>('/weekly-plan/overview').then((r) => r.data)
}

export function generateWeek() {
  return api.post<WeeklyAdaptationResult>('/weekly-plan/generate').then((r) => r.data)
}

export function submitAdaptation(input: string) {
  return api.post<WeeklyAdaptationResult>('/weekly-plan/adapt', { input }).then((r) => r.data)
}
