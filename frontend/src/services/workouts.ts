import api from '@/services/api'
import type { WorkoutSession } from '@/types'

export interface LogWorkoutPayload {
  logged_date?: string
  duration_minutes?: number
  notes?: string
  exercises?: Array<{
    exercise_name: string
    sets?: number
    reps?: number
    weight_kg?: number
    duration_seconds?: number
    notes?: string
  }>
}

export function fetchWorkouts(days = 30) {
  return api.get<WorkoutSession[]>('/workouts', { params: { days } }).then((r) => r.data)
}

export function logWorkout(payload: LogWorkoutPayload) {
  return api.post<WorkoutSession>('/workouts', payload).then((r) => r.data)
}
