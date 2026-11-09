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
    coach_style: 'balanced',
    response_detail: 'standard',
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

  describe('coaching preferences', () => {
    it('reads the saved style and detail when the profile loads', async () => {
      vi.mocked(profileService.fetchProfile).mockResolvedValue(
        makeProfile({ coach_style: 'intense', response_detail: 'detailed' }),
      )
      vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])

      const store = useProfileStore()
      await store.load()

      expect(store.coaching).toEqual({ coach_style: 'intense', response_detail: 'detailed' })
    })

    it('uses the defaults for a user who has no profile yet', async () => {
      vi.mocked(profileService.fetchProfile).mockResolvedValue(null)
      vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])

      const store = useProfileStore()
      await store.load()

      expect(store.profile).toBeNull()
      expect(store.coaching).toEqual({ coach_style: 'balanced', response_detail: 'standard' })
    })

    it('saves only the two coaching fields and leaves the loaded profile object alone', async () => {
      vi.mocked(profileService.fetchProfile).mockResolvedValue(makeProfile())
      vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
      vi.mocked(profileService.updateProfile).mockResolvedValue(
        makeProfile({ coach_style: 'supportive', response_detail: 'concise' }),
      )

      const store = useProfileStore()
      await store.load()
      const loaded = store.profile

      const ok = await store.saveCoaching({ coach_style: 'supportive', response_detail: 'concise' })

      expect(ok).toBe(true)
      expect(profileService.updateProfile).toHaveBeenCalledWith({
        coach_style: 'supportive',
        response_detail: 'concise',
      })
      expect(store.coaching).toEqual({ coach_style: 'supportive', response_detail: 'concise' })
      expect(store.profile).toBe(loaded)
      expect(store.coachingError).toBeNull()
      expect(store.savingCoaching).toBe(false)
    })

    it('keeps what was saved and reports the message when saving fails', async () => {
      vi.mocked(profileService.updateProfile).mockRejectedValue({
        response: { data: { message: 'The selected coach style is invalid.' } },
      })

      const store = useProfileStore()
      const ok = await store.saveCoaching({ coach_style: 'intense', response_detail: 'standard' })

      expect(ok).toBe(false)
      expect(store.coachingError).toBe('The selected coach style is invalid.')
      expect(store.coaching).toEqual({ coach_style: 'balanced', response_detail: 'standard' })
      expect(store.savingCoaching).toBe(false)
    })

    it('falls back to a plain message when the failure carries none', async () => {
      vi.mocked(profileService.updateProfile).mockRejectedValue(new Error('network error'))

      const store = useProfileStore()
      await store.saveCoaching({ coach_style: 'intense', response_detail: 'standard' })

      expect(store.coachingError).toMatch(/couldn.t save/i)
    })
  })
})
