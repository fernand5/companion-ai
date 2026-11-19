import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import type { AdherenceData, DashboardData, ProgressData, WeeklySummary } from '@/types'
import { errorRef } from '@/utils/errors'

export const useDashboardStore = defineStore('dashboard', () => {
  const dashboard = ref<DashboardData | null>(null)
  const progress = ref<ProgressData | null>(null)
  const adherence = ref<AdherenceData | null>(null)
  const weeklySummary = ref<WeeklySummary | null>(null)
  const loading = ref(false)
  const failure = errorRef()
  const error = failure.message
  const progressLoading = ref(false)
  const progressErrorRef = errorRef()
  const progressError = progressErrorRef.message
  const adherenceErrorRef = errorRef()
  const adherenceError = adherenceErrorRef.message
  const weeklySummaryLoading = ref(false)
  const weeklySummaryErrorRef = errorRef()
  const weeklySummaryError = weeklySummaryErrorRef.message

  async function loadDashboard() {
    loading.value = true
    failure.clear()

    try {
      dashboard.value = await dashboardService.fetchDashboard()
    } catch (e: unknown) {
      failure.fail(e, 'dashboardLoad')
    } finally {
      loading.value = false
    }
  }

  async function loadProgress() {
    progressLoading.value = true
    progressErrorRef.clear()

    try {
      progress.value = await dashboardService.fetchProgress()
    } catch (e: unknown) {
      progressErrorRef.fail(e, 'progressLoad')
    } finally {
      progressLoading.value = false
    }
  }

  async function loadAdherence() {
    adherenceErrorRef.clear()

    try {
      adherence.value = await adherenceService.fetchAdherence()
    } catch (e: unknown) {
      adherenceErrorRef.fail(e, 'adherenceLoad')
    }
  }

  async function loadWeeklySummary() {
    weeklySummaryLoading.value = true
    weeklySummaryErrorRef.clear()

    try {
      weeklySummary.value = await coachService.fetchWeeklySummary()
    } catch (e: unknown) {
      weeklySummaryErrorRef.fail(e, 'weeklySummaryLoad')
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
