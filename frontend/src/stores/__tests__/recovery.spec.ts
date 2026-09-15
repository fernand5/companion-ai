import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as recoveryService from '@/services/recovery'
import { useRecoveryStore } from '@/stores/recovery'
import type { RecoveryCheckin } from '@/types'

vi.mock('@/services/recovery', () => ({
  fetchTodayCheckin: vi.fn(),
  submitCheckin: vi.fn(),
}))

function makeCheckin(overrides: Partial<RecoveryCheckin> = {}): RecoveryCheckin {
  return {
    id: 1,
    checkin_date: '2026-09-15',
    energy: 4,
    soreness: 2,
    motivation: 4,
    perceived_difficulty: null,
    pain_notes: null,
    ...overrides,
  }
}

describe('recovery store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts with no check-in until loaded', async () => {
    vi.mocked(recoveryService.fetchTodayCheckin).mockResolvedValue(null)

    const store = useRecoveryStore()
    await store.loadToday()

    expect(store.today).toBeNull()
  })

  it('loads an existing check-in', async () => {
    vi.mocked(recoveryService.fetchTodayCheckin).mockResolvedValue(makeCheckin())

    const store = useRecoveryStore()
    await store.loadToday()

    expect(store.today?.energy).toBe(4)
  })

  it('submitting a check-in updates today', async () => {
    vi.mocked(recoveryService.submitCheckin).mockResolvedValue(makeCheckin({ energy: 1, soreness: 5 }))

    const store = useRecoveryStore()
    await store.submit({ energy: 1, soreness: 5, motivation: 2 })

    expect(store.today?.energy).toBe(1)
    expect(store.today?.soreness).toBe(5)
  })
})
