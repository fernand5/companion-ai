import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'

import { icons } from '@/constants/icons'
import { DEFAULT_SPORT } from '@/constants/activity'
import { i18n } from '@/i18n'
import { formatDateOnly, formatDecimal, formatMeasure, formatTimeOfDay, weekdayName } from '@/i18n/format'
import type { ActivityType, PerformedSet } from '@/types'

/** Words are looked up when called (never cached), so whatever reads them follows the language on screen. */
const t = i18n.global.t

/** An unknown stored value is shown as it is, never hidden. */
const ACTIVITY_TYPES: ReadonlyArray<string> = ['steps', 'treadmill', 'strength', 'sport', 'recovery', 'weight']

export function activityIcon(type: ActivityType): IconDefinition {
  return icons.activityType[type] ?? icons.activityFallback
}

export function activityLabel(type: ActivityType): string {
  return ACTIVITY_TYPES.includes(type) ? t(`enums.activityType.${type}`) : type
}

/** How a stored sport name is shown: the canonical suggestion in the language on screen, anything else as saved. */
export function sportLabel(stored: string): string {
  return stored === DEFAULT_SPORT ? t('activity.quickLog.defaultSport') : stored
}

export function formatDate(dateStr: string): string {
  return formatDateOnly(dateStr, { weekday: 'short', month: 'short', day: 'numeric' })
}

export function formatTime(timeStr: string): string {
  return formatTimeOfDay(timeStr)
}

/** Short weekday name for 0 (Sunday) … 6 (Saturday), in the language the user chose. */
export function dayName(dayOfWeek: number): string {
  return weekdayName(dayOfWeek, 'short')
}

/** 450 -> "7:30 /km". */
export function formatPace(secondsPerKm: number): string {
  const minutes = Math.floor(secondsPerKm / 60)
  const seconds = Math.round(secondsPerKm % 60)

  return `${minutes}:${String(seconds).padStart(2, '0')} /km`
}

/** At most two decimals, no trailing zeros, in the user's number format: 42.5 → "42.5" / "42,5". */
function trimNumber(value: number): string {
  return formatDecimal(Number(value.toFixed(2)), { maximumFractionDigits: 2, useGrouping: false })
}

/**
 * Compact set list, e.g. "10×40, 10×40, 8×40 kg". Sets the user did not
 * complete are marked, so a missed set is never read as a finished one.
 */
export function formatPerformedSets(sets: PerformedSet[]): string {
  const hasWeight = sets.some((set) => set.weight_kg !== null)
  const text = sets
    .map((set) => {
      const parts: string[] = []

      if (set.reps !== null && set.weight_kg !== null) parts.push(`${set.reps}×${trimNumber(set.weight_kg)}`)
      else if (set.reps !== null) parts.push(t('units.reps', { n: set.reps }))
      else if (set.weight_kg !== null) parts.push(`${trimNumber(set.weight_kg)}`)

      if (set.duration_seconds !== null) parts.push(`${set.duration_seconds}s`)

      return set.completed ? parts.join(' ') : `${parts.join(' ')} ${t('units.notCompleted')}`
    })
    .join(', ')

  return hasWeight ? `${text} kg` : text
}

function cardioSummary(log: {
  duration_minutes: number | null
  distance_km?: number | null
  speed_kmh?: number | null
}): string {
  return [
    log.distance_km != null ? `${trimNumber(log.distance_km)} km` : null,
    log.duration_minutes ? t('units.minutes', { n: log.duration_minutes }) : null,
    log.speed_kmh != null ? `${formatMeasure(Number(log.speed_kmh))} km/h` : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

export function activitySummary(log: {
  type: ActivityType
  metadata: Record<string, unknown> | null
  duration_minutes: number | null
  distance_km?: number | null
  speed_kmh?: number | null
}): string {
  const meta = log.metadata ?? {}

  switch (log.type) {
    case 'steps':
      return t('units.steps', { n: meta.steps ?? '?' }, Number(meta.steps) === 1 ? 1 : 2)
    case 'weight':
      return `${meta.weight_kg == null ? '?' : formatMeasure(Number(meta.weight_kg))} kg`
    case 'treadmill':
      return cardioSummary(log) || t('activity.treadmillSession')
    case 'sport':
      return meta.sport == null ? t('activity.sportSession') : sportLabel(String(meta.sport))
    case 'recovery':
      return t('recovery.summary', { energy: meta.energy ?? '-', soreness: meta.soreness ?? '-' })
    case 'strength':
      return log.duration_minutes ? t('activity.strengthSessionMinutes', { n: log.duration_minutes }) : t('activity.strengthSession')
    default:
      return ''
  }
}

/** "3×10 @ 40 kg", "45s", "3×10"; "Not set" when the plan has no target for it. */
export function formatTarget(target: { sets: number | null; reps: number | null; weight_kg: number | null; duration_seconds: number | null } | null): string {
  if (!target) return t('units.notSet')

  const volume =
    target.sets !== null && target.reps !== null
      ? `${target.sets}×${target.reps}`
      : target.sets !== null && target.duration_seconds !== null
        ? `${target.sets}×${target.duration_seconds}s`
        : target.reps !== null
          ? t('units.reps', { n: target.reps })
          : target.duration_seconds !== null
            ? `${target.duration_seconds}s`
            : target.sets !== null
              ? t('units.sets', { n: target.sets })
              : null
  const load = target.weight_kg !== null ? `${formatMeasure(Number(target.weight_kg))} kg` : null
  const parts = [volume, load].filter((p): p is string => p !== null)

  return parts.length === 0 ? t('units.notSet') : parts.join(' @ ')
}
