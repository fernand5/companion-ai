import api from '@/services/api'
import type { ExerciseImageStatus } from '@/types'

export interface ExerciseImageResult {
  image_url: string | null
  status: ExerciseImageStatus | 'unavailable'
}

/**
 * Idempotent: asks the backend to make sure this exercise has a demonstration
 * image. 200 = ready, 202 = still generating (both resolve); a failed or
 * unavailable image rejects. The short timeout is deliberate — generation
 * happens server-side after the response, so this call itself is quick.
 */
export function requestExerciseImage(planId: number, exerciseId: number) {
  return api
    .post<ExerciseImageResult>(`/workout-plans/${planId}/exercises/${exerciseId}/image`, undefined, {
      timeout: 15000,
    })
    .then((r) => r.data)
}
