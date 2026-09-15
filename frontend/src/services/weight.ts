import api from '@/services/api'
import type { ActivityLog } from '@/types'

export function fetchWeightHistory(limit = 30) {
  return api.get<ActivityLog[]>('/weight', { params: { limit } }).then((r) => r.data)
}

export function logWeight(payload: { weight_kg: number; logged_date?: string }) {
  return api.post<ActivityLog>('/weight', payload).then((r) => r.data)
}
