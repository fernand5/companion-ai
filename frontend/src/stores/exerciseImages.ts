import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as exerciseImageService from '@/services/exerciseImages'

export type ExerciseImageState = 'loading' | 'ready' | 'failed'

export interface ExerciseImageEntry {
  state: ExerciseImageState
  url: string | null
}

export const POLL_INTERVAL_MS = 3000
// ~2 minutes of polling, matching how long the backend may take to generate.
export const MAX_POLLS = 40

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

/**
 * Demonstration-image state, kept apart from the workout-plan store on purpose:
 * an image failing must never touch plan state or block completing a workout.
 * Keyed by plan-exercise id (unique across plans).
 */
export const useExerciseImageStore = defineStore('exerciseImages', () => {
  const entries = ref<Record<number, ExerciseImageEntry>>({})
  const inFlight = new Map<number, { cancelled: boolean }>()

  function entryFor(exerciseId: number): ExerciseImageEntry | null {
    return entries.value[exerciseId] ?? null
  }

  async function request(planId: number, exerciseId: number) {
    const existing = entries.value[exerciseId]

    // Already have it, or a request/poll for it is already running.
    if (existing?.state === 'ready' || inFlight.has(exerciseId)) {
      return
    }

    const run = { cancelled: false }
    inFlight.set(exerciseId, run)
    entries.value[exerciseId] = { state: 'loading', url: null }

    try {
      for (let poll = 0; poll < MAX_POLLS; poll++) {
        const result = await exerciseImageService.requestExerciseImage(planId, exerciseId)

        if (run.cancelled) {
          return
        }

        if (result.status === 'ready' && result.image_url) {
          entries.value[exerciseId] = { state: 'ready', url: result.image_url }

          return
        }

        if (result.status !== 'generating') {
          break
        }

        await sleep(POLL_INTERVAL_MS)

        if (run.cancelled) {
          return
        }
      }

      entries.value[exerciseId] = { state: 'failed', url: null }
    } catch {
      // Any failure (503 failed, 422 no demo, network) just means "no demo".
      if (!run.cancelled) {
        entries.value[exerciseId] = { state: 'failed', url: null }
      }
    } finally {
      inFlight.delete(exerciseId)
    }
  }

  /** Stop polling for an exercise (e.g. its row was unmounted) without recording a failure. */
  function cancel(exerciseId: number) {
    const run = inFlight.get(exerciseId)

    if (run) {
      run.cancelled = true
      inFlight.delete(exerciseId)
      delete entries.value[exerciseId]
    }
  }

  return { entries, entryFor, request, cancel }
})
