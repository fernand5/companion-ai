import api from '@/services/api'
import type { ProgressionApplied, ProgressionList } from '@/types'

/** The user's calculated progression recommendations. Read-only: nothing is changed by asking. */
export function fetchRecommendations() {
  return api.get<ProgressionList>('/progression/recommendations').then((r) => r.data)
}

/**
 * Approve one recommendation. Only the opaque proposal the server issued is sent: the server
 * decides what it means, re-checks it, and refuses it if anything moved since it was issued.
 */
export function approveProposal(proposal: string) {
  return api.post<ProgressionApplied>('/progression/approvals', { proposal }).then((r) => r.data)
}

/** "Keep current target": acknowledged by the server, which changes nothing. */
export function keepCurrentTarget(proposal: string) {
  return api.post<{ kept: true }>('/progression/rejections', { proposal }).then((r) => r.data)
}
