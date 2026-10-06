import { describe, expect, it } from 'vitest'

import type { PerformedSet } from '@/types'
import { activitySummary, formatPace, formatPerformedSets } from '@/utils/format'

const set = (overrides: Partial<PerformedSet>): PerformedSet => ({
  set_number: 1,
  reps: null,
  weight_kg: null,
  duration_seconds: null,
  completed: true,
  ...overrides,
})

describe('formatPace', () => {
  it('formats seconds per km as minutes:seconds', () => {
    expect(formatPace(450)).toBe('7:30 /km')
    expect(formatPace(369)).toBe('6:09 /km')
    expect(formatPace(408)).toBe('6:48 /km')
  })

  it('zero-pads the seconds', () => {
    expect(formatPace(305)).toBe('5:05 /km')
  })
})

describe('formatPerformedSets', () => {
  it('lists each set as reps×weight with the unit once', () => {
    expect(
      formatPerformedSets([
        set({ reps: 10, weight_kg: 40 }),
        set({ reps: 10, weight_kg: 40 }),
        set({ reps: 8, weight_kg: 42.5 }),
      ]),
    ).toBe('10×40, 10×40, 8×42.5 kg')
  })

  it('handles bodyweight sets', () => {
    expect(formatPerformedSets([set({ reps: 12 }), set({ reps: 10 })])).toBe('12 reps, 10 reps')
  })

  it('handles timed sets', () => {
    expect(formatPerformedSets([set({ duration_seconds: 60 }), set({ duration_seconds: 45 })])).toBe('60s, 45s')
  })

  it('marks a set that was not completed so it is never read as finished', () => {
    expect(formatPerformedSets([set({ reps: 10, weight_kg: 40 }), set({ reps: 4, weight_kg: 40, completed: false })])).toBe(
      '10×40, 4×40 (not completed) kg',
    )
  })

  it('trims trailing zeros from weights', () => {
    expect(formatPerformedSets([set({ reps: 5, weight_kg: 100.0 })])).toBe('5×100 kg')
  })
})

describe('activitySummary for cardio', () => {
  const base = { metadata: null as Record<string, unknown> | null }

  it('shows distance, duration and speed for a treadmill session', () => {
    expect(
      activitySummary({ ...base, type: 'treadmill', duration_minutes: 32, distance_km: 5.2, speed_kmh: 9.8 }),
    ).toBe('5.2 km · 32 min · 9.8 km/h')
  })

  it('still reads well for treadmill logs that have no distance', () => {
    expect(activitySummary({ ...base, type: 'treadmill', duration_minutes: 20, distance_km: null })).toBe('20 min')
    expect(activitySummary({ ...base, type: 'treadmill', duration_minutes: null })).toBe('Treadmill session')
  })

  it('shows distance without a duration', () => {
    expect(activitySummary({ ...base, type: 'treadmill', duration_minutes: null, distance_km: 3 })).toBe('3 km')
  })

  it('leaves other activity summaries unchanged', () => {
    expect(activitySummary({ type: 'steps', metadata: { steps: 9000 }, duration_minutes: null })).toBe('9000 steps')
    expect(activitySummary({ ...base, type: 'strength', duration_minutes: 45 })).toBe('45 min strength')
  })
})
