import api from '@/services/api'
import type { RecoveryCheckin } from '@/types'

export function fetchTodayCheckin() {
  return api.get<RecoveryCheckin | ''>('/recovery-checkins').then((r) => (r.data ? r.data : null))
}

export interface SubmitCheckinPayload {
  energy: number
  soreness: number
  motivation: number
  perceived_difficulty?: number
  pain_notes?: string
}

export function submitCheckin(payload: SubmitCheckinPayload) {
  return api.post<RecoveryCheckin>('/recovery-checkins', payload).then((r) => r.data)
}
