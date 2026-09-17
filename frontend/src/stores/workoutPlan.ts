import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as workoutPlanService from '@/services/workoutPlans'
import type { UpdateExerciseStatusPayload } from '@/services/workoutPlans'
import type { PlanExerciseStatus, WorkoutPlan } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useWorkoutPlanStore = defineStore('workoutPlan', () => {
  const today = ref<WorkoutPlan | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

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

  return { today, loading, error, loadToday, updateExerciseStatus }
})
