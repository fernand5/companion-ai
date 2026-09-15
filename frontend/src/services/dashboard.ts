import api from '@/services/api'
import type { DashboardData, ProgressData } from '@/types'

export function fetchDashboard() {
  return api.get<DashboardData>('/dashboard').then((r) => r.data)
}

export function fetchProgress() {
  return api.get<ProgressData>('/progress').then((r) => r.data)
}
