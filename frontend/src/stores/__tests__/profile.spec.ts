import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import { useProfileStore } from '@/stores/profile'
import type { FitnessProfile } from '@/types'

vi.mock('@/services/profile', () => ({
  fetchProfile: vi.fn(),
  updateProfile: vi.fn(),
}))

vi.mock('@/services/schedule', () => ({
  fetchSchedule: vi.fn(),
  createSchedule: vi.fn(),
  deleteSchedule: vi.fn(),
}))

function makeProfile(overrides: Partial<FitnessProfile> = {}): FitnessProfile {
  return {
    id: 1,
    height_cm: 180,
    weight_kg: 80,
    age: 30,
    sex: null,
    fitness_level: 'intermediate',
    primary_goal: 'Lose fat',
    secondary_goal: null,
    equipment: [],
    preferred_training_days: [],
    preferred_training_duration_minutes: null,
    updated_at: null,
    ...overrides,
  }
}

describe('profile store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the profile and schedule', async () => {
    vi.mocked(profileService.fetchProfile).mockResolvedValue(makeProfile())
    vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])

    const store = useProfileStore()
    await store.load()

    expect(store.profile?.primary_goal).toBe('Lose fat')
    expect(store.error).toBeNull()
  })

  it('sets an error instead of leaving the form blank and silent when the fetch fails', async () => {
    vi.mocked(profileService.fetchProfile).mockRejectedValue(new Error('network error'))
    vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])

    const store = useProfileStore()
    await store.load()

    expect(store.profile).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.error).not.toBeNull()
  })
})
