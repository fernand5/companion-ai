import { describe, expect, it } from 'vitest'

import { applyLocale } from '@/i18n'
import {
  formatCount,
  formatDateOnly,
  formatDateTime,
  formatDecimal,
  formatMeasure,
  formatTimeOfDay,
  intlTag,
  weekdayName,
} from '@/i18n/format'
import type { PerformedSet } from '@/types'
import {
  activitySummary,
  dayName,
  formatDate,
  formatPace,
  formatPerformedSets,
  formatTarget,
  formatTime,
} from '@/utils/format'
import { formatSteps, formatWeekRange, stripLabels } from '@/utils/weekDay'

const WED = '2026-11-04' // a Wednesday

describe('which locale formats', () => {
  it('uses en-US for English and es-419 for Spanish', () => {
    expect(intlTag('en')).toBe('en-US')
    expect(intlTag('es')).toBe('es-419')

    applyLocale('es')
    expect(intlTag()).toBe('es-419')
  })
})

describe('dates', () => {
  it('formats a calendar date in the chosen language, not the browser language', () => {
    expect(formatDate(WED)).toBe('Wed, Nov 4')

    applyLocale('es')
    expect(formatDate(WED)).toBe('mié, 4 nov')

    applyLocale('en')
    expect(formatDate(WED)).toBe('Wed, Nov 4')
  })

  it('never shifts the day: a date is shown as the same calendar day', () => {
    for (const locale of ['en', 'es'] as const) {
      applyLocale(locale)
      expect(formatDateOnly('2026-11-01', { day: 'numeric' })).toBe('1')
      expect(formatDateOnly('2026-12-31', { day: 'numeric' })).toBe('31')
    }
  })

  it('names the weekday by number in both languages', () => {
    expect(weekdayName(0)).toBe('Sun')
    expect(weekdayName(3)).toBe('Wed')
    expect(weekdayName(6, 'long')).toBe('Saturday')
    expect(dayName(1)).toBe('Mon')

    applyLocale('es')
    expect(weekdayName(0)).toBe('dom')
    expect(weekdayName(3)).toBe('mié')
    expect(weekdayName(6, 'long')).toBe('sábado')
    expect(dayName(1)).toBe('lun')
  })

  it('returns a placeholder for a weekday number that does not exist', () => {
    expect(weekdayName(7)).toBe('?')
    expect(weekdayName(-1)).toBe('?')
    expect(weekdayName(1.5)).toBe('?')
  })

  it('formats the week range and strip labels', () => {
    expect(formatWeekRange('2026-11-02', '2026-11-08')).toBe('Nov 2 – Nov 8')
    expect(stripLabels(WED)).toEqual({ weekday: 'Wed', day: '4' })

    applyLocale('es')
    expect(formatWeekRange('2026-11-02', '2026-11-08')).toBe('2 nov – 8 nov')
    expect(stripLabels(WED)).toEqual({ weekday: 'mié', day: '4' })
  })

  it('formats an instant in the reader’s own timezone', () => {
    const iso = '2026-11-04T17:30:00'
    expect(formatDateTime(iso, { weekday: 'short', hour: 'numeric', minute: '2-digit' })).toBe('Wed 5:30 PM')

    applyLocale('es')
    expect(formatDateTime(iso, { weekday: 'short', hour: 'numeric', minute: '2-digit' })).toContain('mié')
  })
})

describe('times', () => {
  it('formats a clock time in the chosen language', () => {
    expect(formatTime('17:30')).toBe('5:30 PM')
    expect(formatTimeOfDay('07:05')).toBe('7:05 AM')

    applyLocale('es')
    expect(formatTime('17:30')).toBe('5:30 p.m.')
  })
})

describe('numbers', () => {
  it('shows a measurement with every digit it has and no thousands grouping', () => {
    expect(formatMeasure(42.5)).toBe('42.5')
    expect(formatMeasure(40)).toBe('40')
    expect(formatMeasure(42.555)).toBe('42.555')
    expect(formatMeasure(1234.5)).toBe('1234.5')
    expect(formatMeasure(0.125)).toBe('0.125')
    expect(formatMeasure(-2.5)).toBe('-2.5')

    applyLocale('es')
    // Same digits in Spanish: es-419 writes decimals with a point, so a stored 42.5 never reads as another value.
    expect(formatMeasure(42.5)).toBe('42.5')
    expect(formatMeasure(42.555)).toBe('42.555')
    expect(formatMeasure(40)).toBe('40')
  })

  it('formats counts with the language’s own grouping', () => {
    expect(formatCount(6000)).toBe('6,000')
    expect(formatSteps(12345)).toBe('12,345')

    applyLocale('es')
    expect(formatCount(6000)).toBe(new Intl.NumberFormat('es-419', { maximumFractionDigits: 0 }).format(6000))
  })

  it('rounds only as far as the caller asks', () => {
    expect(formatDecimal(7.46, { minimumFractionDigits: 1, maximumFractionDigits: 1 })).toBe('7.5')
    expect(formatDecimal(7, { minimumFractionDigits: 1, maximumFractionDigits: 1 })).toBe('7.0')
  })

  it('does not change a value it is given, and loads are never converted', () => {
    const sets: PerformedSet[] = Object.freeze([
      Object.freeze({ set_number: 1, reps: 10, weight_kg: 42.5, duration_seconds: null, completed: true }),
      Object.freeze({ set_number: 2, reps: 8, weight_kg: 45, duration_seconds: null, completed: false }),
    ]) as unknown as PerformedSet[]

    for (const locale of ['en', 'es'] as const) {
      applyLocale(locale)
      // Kilograms stay kilograms; nothing is converted to pounds or rescaled.
      expect(formatPerformedSets(sets)).toBe('10×42.5, 8×45 (not completed) kg')
      expect(formatTarget({ sets: 3, reps: 10, weight_kg: 42.5, duration_seconds: null })).toBe('3×10 @ 42.5 kg')
    }

    expect(sets[0].weight_kg).toBe(42.5)
  })

  it('keeps units as stored in activity summaries', () => {
    for (const locale of ['en', 'es'] as const) {
      applyLocale(locale)
      expect(activitySummary({ type: 'weight', metadata: { weight_kg: 81.4 }, duration_minutes: null })).toBe('81.4 kg')
      expect(
        activitySummary({ type: 'treadmill', metadata: null, duration_minutes: 30, distance_km: 5.25, speed_kmh: 10.5 }),
      ).toBe('5.25 km · 30 min · 10.5 km/h')
      expect(formatPace(450)).toBe('7:30 /km')
    }
  })
})
