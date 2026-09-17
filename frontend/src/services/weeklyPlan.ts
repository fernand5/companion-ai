import api from '@/services/api'
import type { WeeklyAdaptationResult, WorkoutPlan } from '@/types'

export function fetchWeek(from: string, to: string) {
  return api.get<WorkoutPlan[]>('/workout-plans', { params: { from, to } }).then((r) => r.data)
}

export function generateWeek() {
  return api.post<WeeklyAdaptationResult>('/weekly-plan/generate').then((r) => r.data)
}

export function submitAdaptation(input: string) {
  return api.post<WeeklyAdaptationResult>('/weekly-plan/adapt', { input }).then((r) => r.data)
}
