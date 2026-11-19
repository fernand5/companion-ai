import type { Enums } from '@/i18n/messages/en/enums'

export const enums: Enums = {
  activityType: {
    steps: 'Pasos',
    treadmill: 'Cinta de correr',
    strength: 'Fuerza',
    sport: 'Deporte',
    recovery: 'Recuperación',
    weight: 'Peso',
  },
  intensity: {
    low: 'Baja',
    moderate: 'Moderada',
    high: 'Alta',
  },
  planStatus: {
    planned: 'planificado',
    in_progress: 'en curso',
    completed: 'completado',
    partial: 'parcial',
    skipped: 'omitido',
  },
  recordedAs: {
    as_planned: 'según el plan',
    migrated: 'registro anterior',
  },
  dayState: {
    completed: 'Completado',
    partial: 'Hecho en parte',
    in_progress: 'En curso',
    planned: 'Planificado',
    not_marked_done: 'Sin marcar como hecho',
    skipped: 'Omitido',
    rest: 'Día de descanso',
    activity_only: 'Actividad no planificada',
    nothing: 'Nada planificado',
  },
}
