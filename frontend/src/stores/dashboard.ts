import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import type { AdherenceData, DashboardData, ProgressData, WeeklySummary } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useDashboardStore = defineStore('dashboard', () => {
  const dashboard = ref<DashboardData | null>(null)
  const progress = ref<ProgressData | null>(null)
  const adherence = ref<AdherenceData | null>(null)
  const weeklySummary = ref<WeeklySummary | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const progressLoading = ref(false)
  const progressError = ref<string | null>(null)
  const adherenceError = ref<string | null>(null)
  const weeklySummaryLoading = ref(false)
  const weeklySummaryError = ref<string | null>(null)

  async function loadDashboard() {
    loading.value = true
    error.value = null

    try {
      dashboard.value = await dashboardService.fetchDashboard()
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your dashboard.")
    } finally {
      loading.value = false
    }
  }

  async function loadProgress() {
    progressLoading.value = true
    progressError.value = null

    try {
      progress.value = await dashboardService.fetchProgress()
    } catch (e: unknown) {
      progressError.value = extractErrorMessage(e, "Couldn't load your progress.")
    } finally {
      progressLoading.value = false
    }
  }

  async function loadAdherence() {
    adherenceError.value = null

    try {
      adherence.value = await adherenceService.fetchAdherence()
    } catch (e: unknown) {
      adherenceError.value = extractErrorMessage(e, "Couldn't load your adherence data.")
    }
  }

  async function loadWeeklySummary() {
    weeklySummaryLoading.value = true
    weeklySummaryError.value = null

    try {
      weeklySummary.value = await coachService.fetchWeeklySummary()
    } catch (e: unknown) {
      weeklySummaryError.value = extractErrorMessage(e, "Couldn't load your weekly summary.")
    } finally {
      weeklySummaryLoading.value = false
    }
  }

  return {
    dashboard,
    progress,
    adherence,
    weeklySummary,
    loading,
    error,
    progressLoading,
    progressError,
    adherenceError,
    weeklySummaryLoading,
    weeklySummaryError,
    loadDashboard,
    loadProgress,
    loadAdherence,
    loadWeeklySummary,
  }
})
