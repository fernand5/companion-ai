import api from '@/services/api'
import type { FitnessProfile } from '@/types'

export async function fetchProfile(): Promise<FitnessProfile | null> {
  const response = await api.get<FitnessProfile | ''>('/profile')

  return response.data || null
}

export function updateProfile(payload: Partial<FitnessProfile>) {
  return api.put<FitnessProfile>('/profile', payload).then((r) => r.data)
}
