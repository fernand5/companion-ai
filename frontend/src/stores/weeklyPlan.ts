import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as weeklyPlanService from '@/services/weeklyPlan'
import type { WeekInfo, WeeklyAdaptationResult, WeeklyPlanDay } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useWeeklyPlanStore = defineStore('weeklyPlan', () => {
  const days = ref<WeeklyPlanDay[]>([])
  /** The week the server is showing (its start, end and the user's "today"); null until the first load. */
  const week = ref<WeekInfo | null>(null)
  const loading = ref(false)
  const generating = ref(false)
  /** True once filling the week actually added plans (so things derived from the plans can refresh). */
  const filled = ref(false)
  const sending = ref(false)
  const error = ref<string | null>(null)
  const lastResult = ref<WeeklyAdaptationResult | null>(null)
  /** Notes from the application (not the coach) about the latest plan change, e.g. a weight left for the user to choose. */
  const notices = ref<string[]>([])

  async function loadWeek() {
    loading.value = true
    error.value = null
    filled.value = false

    // Show whatever already exists first. Filling gaps is an AI call that can
    // take a minute or fail outright — it must never keep the user from seeing
    // (or block them from reading) a week that's already planned. The server
    // decides which week this is (the user's own calendar), not the browser clock.
    try {
      const overview = await weeklyPlanService.fetchOverview()
      days.value = overview.days
      week.value = overview.week
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

      if (result.notices?.length) notices.value = result.notices

      if (result.applied) {
        const overview = await weeklyPlanService.fetchOverview()
        days.value = overview.days
        week.value = overview.week
        filled.value = true
      }
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't fill in the rest of your week.")
    } finally {
      generating.value = false
    }
  }

  /**
   * Re-read the week without the loading state or filling gaps (no AI call). For when something else changed a
   * plan, e.g. an approved progression changed a load, so the days on screen match what is stored.
   */
  async function reloadDays() {
    try {
      const overview = await weeklyPlanService.fetchOverview()
      days.value = overview.days
      week.value = overview.week
    } catch {
      // Keep showing what is already on screen; a stale load is better than an empty week.
    }
  }

  async function submitAdaptation(input: string) {
    if (sending.value) return

    error.value = null
    notices.value = []
    sending.value = true

    try {
      const result = await weeklyPlanService.submitAdaptation(input)
      lastResult.value = result
      notices.value = result.notices ?? []

      if (result.applied) {
        await loadWeek()
      }
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't reach your coach — please try again.")
    } finally {
      sending.value = false
    }
  }

  return { days, week, loading, generating, filled, sending, error, lastResult, notices, loadWeek, reloadDays, submitAdaptation }
})
