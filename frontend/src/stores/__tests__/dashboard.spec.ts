import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as adherenceService from '@/services/adherence'
import * as dashboardService from '@/services/dashboard'
import { useDashboardStore } from '@/stores/dashboard'
import type { AdherenceData } from '@/types'

vi.mock('@/services/dashboard', () => ({
  fetchDashboard: vi.fn(),
  fetchProgress: vi.fn(),
}))

vi.mock('@/services/adherence', () => ({
  fetchAdherence: vi.fn(),
}))

function makeAdherence(): AdherenceData {
  return {
    series: [{ week_start: '2026-09-08', week_end: '2026-09-14', adherence_pct: 75 }],
    summary: {
      start_date: '2026-09-08',
      end_date: '2026-09-14',
      adherence_pct: 75,
      due_exercise_count: 4,
      planned_workouts_completed: 1,
      planned_workouts_partial: 0,
      planned_workouts_skipped: 0,
      active_days: 3,
    },
  }
}

describe('dashboard store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads adherence data', async () => {
    vi.mocked(adherenceService.fetchAdherence).mockResolvedValue(makeAdherence())

    const store = useDashboardStore()
    await store.loadAdherence()

    expect(store.adherence?.summary.adherence_pct).toBe(75)
    expect(store.adherence?.series).toHaveLength(1)
  })

  it('loadDashboard toggles the loading flag', async () => {
    vi.mocked(dashboardService.fetchDashboard).mockResolvedValue({
      profile: null,
      today_activity: [],
      upcoming_schedule: [],
      progress: {
        current_weight_kg: null,
        starting_weight_kg: null,
        weight_change_kg: null,
        workouts_this_week: 0,
        average_steps: 0,
      },
      coach_recommendation: null,
      coach_recommendation_unavailable_reason: null,
    })

    const store = useDashboardStore()
    const promise = store.loadDashboard()
    expect(store.loading).toBe(true)
    await promise
    expect(store.loading).toBe(false)
  })
})
