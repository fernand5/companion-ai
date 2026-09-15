import api from '@/services/api'
import type { ActivityLog, ActivityType } from '@/types'

export interface LogActivityPayload {
  type: Exclude<ActivityType, 'weight'>
  logged_date?: string
  duration_minutes?: number
  intensity?: 'low' | 'moderate' | 'high'
  notes?: string
  metadata?: Record<string, unknown>
}

export function fetchActivity(params?: { start_date?: string; end_date?: string; type?: string }) {
  return api.get<ActivityLog[]>('/activity', { params }).then((r) => r.data)
}

export function logActivity(payload: LogActivityPayload) {
  return api.post<ActivityLog>('/activity', payload).then((r) => r.data)
}

export function deleteActivity(id: number) {
  return api.delete(`/activity/${id}`)
}
