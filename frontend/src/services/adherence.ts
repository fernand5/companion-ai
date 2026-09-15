import api from '@/services/api'
import type { AdherenceData } from '@/types'

export function fetchAdherence(weeks = 8) {
  return api.get<AdherenceData>('/adherence', { params: { weeks } }).then((r) => r.data)
}
