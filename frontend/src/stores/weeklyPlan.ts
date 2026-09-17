import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as weeklyPlanService from '@/services/weeklyPlan'
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
  const sending = ref(false)
  const error = ref<string | null>(null)
  const lastResult = ref<WeeklyAdaptationResult | null>(null)

  async function loadWeek() {
    loading.value = true
    error.value = null

    try {
      const { start, end, dates } = getCurrentWeekRange()

      // The backend decides whether there's anything to fill (an empty week,
      // or specific days with a recurring-schedule commitment but no plan
      // yet) and is a fast no-op otherwise — safe to call on every load
      // rather than guessing from an "every day is null" heuristic here,
      // which missed partially-filled weeks with schedule gaps.
      await weeklyPlanService.generateWeek()
      const plans = await weeklyPlanService.fetchWeek(start, end)

      days.value = zip(dates, plans)
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your weekly plan.")
    } finally {
      loading.value = false
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

  return { days, loading, sending, error, lastResult, loadWeek, submitAdaptation }
})
