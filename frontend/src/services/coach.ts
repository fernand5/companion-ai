import api from '@/services/api'
import type { WeeklySummary } from '@/types'

export function fetchWeeklySummary() {
  return api.get<WeeklySummary>('/coach/weekly-summary').then((r) => r.data)
}
