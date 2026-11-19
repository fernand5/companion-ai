import type { Dashboard } from '@/i18n/messages/en/dashboard'

export const dashboard: Dashboard = {
  loading: 'Cargando tu resumen…',
  recommendation: 'Recomendación de hoy',
  askCoach: 'Pregúntale a tu coach',
  today: 'Hoy',
  nothingToday: 'Aún no has registrado nada hoy.',
  upcoming: 'Próximamente',
  progress: 'Progreso',
  stats: {
    currentWeight: 'Peso actual',
    change: 'Cambio',
    workoutsWeek: 'Entrenamientos esta semana',
    avgSteps: 'Pasos promedio',
  },
  unavailable: {
    rate_limited: 'Tu coach alcanzó su límite de solicitudes por ahora: vuelve en un rato o pregúntale directamente en la pestaña Coach.',
    unavailable: 'Tu coach no está disponible por el momento: inténtalo de nuevo en breve o pregúntale directamente en la pestaña Coach.',
    not_configured: 'Configura tu AI_API_KEY para obtener una recomendación en vivo, o pregúntale directamente a tu coach en la pestaña Coach.',
  },
}
