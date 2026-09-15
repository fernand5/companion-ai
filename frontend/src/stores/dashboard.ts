import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import type { AdherenceData, DashboardData, ProgressData, WeeklySummary } from '@/types'

export const useDashboardStore = defineStore('dashboard', () => {
  const dashboard = ref<DashboardData | null>(null)
  const progress = ref<ProgressData | null>(null)
  const adherence = ref<AdherenceData | null>(null)
  const weeklySummary = ref<WeeklySummary | null>(null)
  const loading = ref(false)
  const weeklySummaryLoading = ref(false)
  const weeklySummaryError = ref<string | null>(null)

  async function loadDashboard() {
    loading.value = true

    try {
      dashboard.value = await dashboardService.fetchDashboard()
    } finally {
      loading.value = false
    }
  }

  async function loadProgress() {
    progress.value = await dashboardService.fetchProgress()
  }

  async function loadAdherence() {
    adherence.value = await adherenceService.fetchAdherence()
  }

  async function loadWeeklySummary() {
    weeklySummaryLoading.value = true
    weeklySummaryError.value = null

    try {
      weeklySummary.value = await coachService.fetchWeeklySummary()
    } catch (e: unknown) {
      const apiMessage = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      weeklySummaryError.value = apiMessage ?? "Couldn't load your weekly summary."
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
    weeklySummaryLoading,
    weeklySummaryError,
    loadDashboard,
    loadProgress,
    loadAdherence,
    loadWeeklySummary,
  }
})
