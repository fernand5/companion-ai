import api from '@/services/api'
import type { TrainingSchedule } from '@/types'

export function fetchSchedule() {
  return api.get<TrainingSchedule[]>('/schedule').then((r) => r.data)
}

export function createSchedule(payload: Omit<TrainingSchedule, 'id'>) {
  return api.post<TrainingSchedule>('/schedule', payload).then((r) => r.data)
}

export function updateSchedule(id: number, payload: Partial<Omit<TrainingSchedule, 'id'>>) {
  return api.put<TrainingSchedule>(`/schedule/${id}`, payload).then((r) => r.data)
}

export function deleteSchedule(id: number) {
  return api.delete(`/schedule/${id}`)
}
