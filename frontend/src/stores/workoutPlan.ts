import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as workoutPlanService from '@/services/workoutPlans'
import type { PerformedSetInput, UpdateExerciseStatusPayload } from '@/services/workoutPlans'
import type { PlanExerciseStatus, WorkoutPlan } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useWorkoutPlanStore = defineStore('workoutPlan', () => {
  const today = ref<WorkoutPlan | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  /** The exercise whose set-by-set record is being saved, if any. */
  const savingPerformanceFor = ref<number | null>(null)
  /** Kept apart from `error` so a rejected set entry shows beside the editor, not under the whole plan. */
  const performanceError = ref<string | null>(null)

  async function loadToday() {
    loading.value = true
    error.value = null

    try {
      today.value = await workoutPlanService.fetchPlanForDate()
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load today's plan.")
    } finally {
      loading.value = false
    }
  }

  async function updateExerciseStatus(exerciseId: number, status: PlanExerciseStatus, actuals?: Partial<UpdateExerciseStatusPayload>) {
    if (!today.value) return

    const planId = today.value.id
    const previous = today.value

    // Optimistic update so the checklist feels instant.
    today.value = {
      ...today.value,
      exercises: today.value.exercises.map((exercise) =>
        exercise.id === exerciseId ? { ...exercise, status } : exercise,
      ),
    }

    try {
      today.value = await workoutPlanService.updateExerciseStatus(planId, exerciseId, { status, ...actuals })
    } catch {
      today.value = previous
      error.value = "Couldn't update that exercise — please try again."
    }
  }

  /**
   * Save what the user actually did. Not optimistic: the server derives the
   * exercise and plan status from the sets, so the response is what we show.
   * Resolves to whether it was saved, so the editor knows when to close.
   */
  async function recordPerformance(exerciseId: number, sets: PerformedSetInput[]): Promise<boolean> {
    if (!today.value || savingPerformanceFor.value !== null) return false

    savingPerformanceFor.value = exerciseId
    performanceError.value = null

    try {
      today.value = await workoutPlanService.recordExercisePerformance(today.value.id, exerciseId, { sets })

      return true
    } catch (e: unknown) {
      performanceError.value = extractErrorMessage(e, "Couldn't save those sets — please try again.")

      return false
    } finally {
      savingPerformanceFor.value = null
    }
  }

  return {
    today,
    loading,
    error,
    savingPerformanceFor,
    performanceError,
    loadToday,
    updateExerciseStatus,
    recordPerformance,
  }
})
