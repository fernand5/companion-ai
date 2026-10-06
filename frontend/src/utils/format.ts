import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'

import { icons } from '@/constants/icons'
import type { ActivityType, PerformedSet } from '@/types'

const ACTIVITY_LABELS: Record<ActivityType, string> = {
  steps: 'Steps',
  treadmill: 'Treadmill',
  strength: 'Strength',
  sport: 'Sport',
  recovery: 'Recovery',
  weight: 'Weight',
}

export function activityIcon(type: ActivityType): IconDefinition {
  return icons.activityType[type] ?? icons.activityFallback
}

export function activityLabel(type: ActivityType): string {
  return ACTIVITY_LABELS[type] ?? type
}

export function formatDate(dateStr: string): string {
  const date = new Date(`${dateStr}T00:00:00`)

  return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

export function formatTime(timeStr: string): string {
  const [hours, minutes] = timeStr.split(':')
  const date = new Date()
  date.setHours(Number(hours), Number(minutes))

  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
}

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export function dayName(dayOfWeek: number): string {
  return DAY_NAMES[dayOfWeek] ?? '?'
}

/** 450 -> "7:30 /km". */
export function formatPace(secondsPerKm: number): string {
  const minutes = Math.floor(secondsPerKm / 60)
  const seconds = Math.round(secondsPerKm % 60)

  return `${minutes}:${String(seconds).padStart(2, '0')} /km`
}

function trimNumber(value: number): string {
  return String(Number(value.toFixed(2)))
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
      else if (set.reps !== null) parts.push(`${set.reps} reps`)
      else if (set.weight_kg !== null) parts.push(`${trimNumber(set.weight_kg)}`)

      if (set.duration_seconds !== null) parts.push(`${set.duration_seconds}s`)

      return set.completed ? parts.join(' ') : `${parts.join(' ')} (not completed)`
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
    log.duration_minutes ? `${log.duration_minutes} min` : null,
    log.speed_kmh != null ? `${log.speed_kmh} km/h` : null,
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
      return `${meta.steps ?? '?'} steps`
    case 'weight':
      return `${meta.weight_kg ?? '?'} kg`
    case 'treadmill':
      return cardioSummary(log) || 'Treadmill session'
    case 'sport':
      return String(meta.sport ?? 'Sport session')
    case 'recovery':
      return `Energy ${meta.energy ?? '-'} / Soreness ${meta.soreness ?? '-'}`
    case 'strength':
      return log.duration_minutes ? `${log.duration_minutes} min strength` : 'Strength session'
    default:
      return ''
  }
}
