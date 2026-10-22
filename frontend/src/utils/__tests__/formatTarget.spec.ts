import { describe, expect, it } from 'vitest'

import { formatTarget } from '@/utils/format'

describe('formatTarget', () => {
  it.each([
    [{ sets: 3, reps: 10, weight_kg: 40, duration_seconds: null }, '3×10 @ 40 kg'],
    [{ sets: 3, reps: 10, weight_kg: 42.5, duration_seconds: null }, '3×10 @ 42.5 kg'],
    [{ sets: 3, reps: 8, weight_kg: null, duration_seconds: null }, '3×8'],
    [{ sets: 2, reps: null, weight_kg: null, duration_seconds: 45 }, '2×45s'],
    [{ sets: null, reps: null, weight_kg: null, duration_seconds: 45 }, '45s'],
    [{ sets: null, reps: 12, weight_kg: 20, duration_seconds: null }, '12 reps @ 20 kg'],
    [{ sets: null, reps: null, weight_kg: null, duration_seconds: null }, 'Not set'],
    [null, 'Not set'],
  ])('formats %j as %s', (target, expected) => {
    expect(formatTarget(target)).toBe(expected)
  })
})
