import type { Progress } from '@/i18n/messages/en/progress'

export const progress: Progress = {
  title: 'Progreso',
  loading: 'Cargando progreso…',
  trends: 'Tendencias e historial',
  adherence: 'Cumplimiento',
  last7Days: 'Últimos 7 días: {n} día activo | Últimos 7 días: {n} días activos',
  weight: 'Peso',
  noWeight: 'Aún no hay registros de peso.',
  steps: 'Pasos',
  noSteps: 'Aún no hay pasos registrados.',
  workoutsPerWeek: 'Entrenamientos por semana',
  stats: {
    current: 'Actual',
    starting: 'Inicial',
    workoutsPerWeek: 'Entren./sem.',
    avgSteps: 'Pasos promedio',
  },
  chart: {
    adherence: 'Cumplimiento %',
    weight: 'Peso (kg)',
    steps: 'Pasos',
    workouts: 'Entrenamientos',
  },
  summary: {
    title: 'Tu semana',
    adherence: 'Cumplimiento',
    plannedDone: 'Planificados hechos',
    activeDays: 'Días activos',
    insight: 'Observación del coach:',
    nextFocus: 'Enfoque de la próxima semana:',
    asOf: 'Al {date}',
  },
}
