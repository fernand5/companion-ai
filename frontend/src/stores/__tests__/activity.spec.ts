import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as activityService from '@/services/activity'
import * as workoutService from '@/services/workouts'
import { useActivityStore } from '@/stores/activity'
import type { ActivityLog } from '@/types'

vi.mock('@/services/activity', () => ({
  fetchActivity: vi.fn(),
  logActivity: vi.fn(),
  deleteActivity: vi.fn(),
}))

vi.mock('@/services/workouts', () => ({
  fetchWorkouts: vi.fn(),
  logWorkout: vi.fn(),
}))

function makeLog(overrides: Partial<ActivityLog> = {}): ActivityLog {
  return {
    id: 1,
    type: 'steps',
    logged_date: '2026-09-15',
    duration_minutes: null,
    intensity: null,
    notes: null,
    metadata: { steps: 6000 },
    workout_session_id: null,
    created_at: null,
    ...overrides,
  }
}

describe('activity store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads activity logs and workouts', async () => {
    vi.mocked(activityService.fetchActivity).mockResolvedValue([makeLog()])
    vi.mocked(workoutService.fetchWorkouts).mockResolvedValue([])

    const store = useActivityStore()
    await store.load()

    expect(store.logs).toHaveLength(1)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('sets an error instead of leaving the timeline empty and silent when the fetch fails', async () => {
    vi.mocked(activityService.fetchActivity).mockRejectedValue(new Error('network error'))
    vi.mocked(workoutService.fetchWorkouts).mockResolvedValue([])

    const store = useActivityStore()
    await store.load()

    expect(store.logs).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).not.toBeNull()
  })

  it('clears a previous error on a successful reload', async () => {
    vi.mocked(activityService.fetchActivity).mockRejectedValueOnce(new Error('network error'))
    vi.mocked(workoutService.fetchWorkouts).mockResolvedValue([])

    const store = useActivityStore()
    await store.load()
    expect(store.error).not.toBeNull()

    vi.mocked(activityService.fetchActivity).mockResolvedValueOnce([makeLog()])
    await store.load()

    expect(store.error).toBeNull()
    expect(store.logs).toHaveLength(1)
  })
})
