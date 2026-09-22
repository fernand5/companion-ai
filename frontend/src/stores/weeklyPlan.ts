import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as weeklyPlanService from '@/services/weeklyPlan'
import { useAuthStore } from '@/stores/auth'
import type { WeeklyAdaptationResult, WeeklyPlanDay, WorkoutPlan } from '@/types'
import { extractErrorMessage } from '@/utils/errors'
import { getCurrentWeekRange } from '@/utils/week'

function zip(dates: string[], plans: WorkoutPlan[]): WeeklyPlanDay[] {
  return dates.map((date) => ({
    date,
    plan: plans.find((plan) => plan.planned_date === date) ?? null,
  }))
}

export const useWeeklyPlanStore = defineStore('weeklyPlan', () => {
  const days = ref<WeeklyPlanDay[]>([])
  const loading = ref(false)
  const generating = ref(false)
  const sending = ref(false)
  const error = ref<string | null>(null)
  const lastResult = ref<WeeklyAdaptationResult | null>(null)

  async function loadWeek() {
    loading.value = true
    error.value = null

    const { start, end, dates } = getCurrentWeekRange(useAuthStore().user?.timezone)

    // Show whatever already exists first. Filling gaps is an AI call that can
    // take a minute or fail outright — it must never keep the user from seeing
    // (or block them from reading) a week that's already planned.
    try {
      days.value = zip(dates, await weeklyPlanService.fetchWeek(start, end))
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your weekly plan.")
      loading.value = false

      return
    }

    loading.value = false
    generating.value = true

    try {
      // The backend decides whether there's anything to fill (an empty week,
      // or specific days with a recurring-schedule commitment but no plan
      // yet) and is a fast no-op otherwise — safe to call on every load
      // rather than guessing from an "every day is null" heuristic here.
      const result = await weeklyPlanService.generateWeek()

      if (result.applied) {
        days.value = zip(dates, await weeklyPlanService.fetchWeek(start, end))
      }
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't fill in the rest of your week.")
    } finally {
      generating.value = false
    }
  }

  async function submitAdaptation(input: string) {
    if (sending.value) return

    error.value = null
    sending.value = true

    try {
      const result = await weeklyPlanService.submitAdaptation(input)
      lastResult.value = result

      if (result.applied) {
        await loadWeek()
      }
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't reach your coach — please try again.")
    } finally {
      sending.value = false
    }
  }

  return { days, loading, generating, sending, error, lastResult, loadWeek, submitAdaptation }
})
