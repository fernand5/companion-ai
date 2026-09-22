import { afterEach, describe, expect, it, vi } from 'vitest'

import { getCurrentWeekRange, todayIn } from '@/utils/week'

// Wednesday 2026-09-16, 19:30 in Bogota (UTC-5) == Thursday 2026-09-17, 00:30 UTC.
const BOGOTA_WED_EVENING_UTC = '2026-09-17T00:30:00Z'

describe('week utils', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it("resolves today in the user's timezone, not UTC, across the UTC day boundary", () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(BOGOTA_WED_EVENING_UTC))

    expect(todayIn('America/Bogota')).toBe('2026-09-16')
    expect(todayIn('UTC')).toBe('2026-09-17')
  })

  it('falls back to UTC when the user has no stored timezone, matching the backend', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(BOGOTA_WED_EVENING_UTC))

    expect(todayIn(null)).toBe('2026-09-17')
    expect(todayIn(undefined)).toBe('2026-09-17')
  })

  it('builds the Monday-Sunday week from the local date on a mid-week evening', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(BOGOTA_WED_EVENING_UTC))

    const week = getCurrentWeekRange('America/Bogota')

    expect(week.start).toBe('2026-09-14')
    expect(week.end).toBe('2026-09-20')
    expect(week.dates).toHaveLength(7)
    expect(week.dates).toContain('2026-09-16')
  })

  it('does not roll into next week on Sunday evening in Bogota (UTC is already Monday)', () => {
    vi.useFakeTimers()
    // Sunday 2026-09-13, 23:30 in Bogota == Monday 2026-09-14, 04:30 UTC.
    vi.setSystemTime(new Date('2026-09-14T04:30:00Z'))

    const week = getCurrentWeekRange('America/Bogota')

    expect(week.start).toBe('2026-09-07')
    expect(week.end).toBe('2026-09-13')
    expect(getCurrentWeekRange('UTC').start).toBe('2026-09-14')
  })
})
