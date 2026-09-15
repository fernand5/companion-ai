import api from '@/services/api'
import type { PlanExerciseStatus, WorkoutPlan } from '@/types'

export function fetchPlanForDate(date?: string) {
  return api
    .get<WorkoutPlan | ''>('/workout-plans', { params: date ? { date } : undefined })
    .then((r) => (r.data ? r.data : null))
}

export interface UpdateExerciseStatusPayload {
  status: PlanExerciseStatus
  actual_sets?: number
  actual_reps?: number
  actual_weight_kg?: number
  actual_duration_seconds?: number
  notes?: string
}

export function updateExerciseStatus(planId: number, exerciseId: number, payload: UpdateExerciseStatusPayload) {
  return api
    .patch<WorkoutPlan>(`/workout-plans/${planId}/exercises/${exerciseId}`, payload)
    .then((r) => r.data)
}
