import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'

import { icons } from '@/constants/icons'
import type { ActivityType } from '@/types'

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

export function activitySummary(log: {
  type: ActivityType
  metadata: Record<string, unknown> | null
  duration_minutes: number | null
}): string {
  const meta = log.metadata ?? {}

  switch (log.type) {
    case 'steps':
      return `${meta.steps ?? '?'} steps`
    case 'weight':
      return `${meta.weight_kg ?? '?'} kg`
    case 'treadmill':
      return log.duration_minutes ? `${log.duration_minutes} min` : 'Treadmill session'
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
