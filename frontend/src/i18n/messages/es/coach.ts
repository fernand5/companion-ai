import type { Coach } from '@/i18n/messages/en/coach'

export const coach: Coach = {
  title: 'Coach',
  empty: 'Saluda o pregunta qué hacer hoy.',
  thinking: 'El coach está pensando…',
  placeholder: 'Cuéntale a tu coach lo que hiciste, o pregunta qué sigue…',
  send: 'Enviar',
  suggestions: {
    today: '¿Qué debo hacer hoy?',
    logWorkout: 'Registrar el entrenamiento de hoy',
    logSteps: 'Registrar mis pasos',
    updateWeight: 'Actualizar mi peso',
    progress: '¿Cómo voy con mi progreso?',
  },
  checked: 'Revisó {count} cosa | Revisó {count} cosas',
  confirm: {
    yes: 'Sí, reemplazar',
    no: 'No, conservar',
    yesMessage: 'Sí',
    noMessage: 'No',
    group: 'Responde la pregunta de arriba',
    expired: 'Esta pregunta venció. Vuelve a pedirlo si todavía quieres el cambio.',
  },
  saved: {
    title: 'Guardado en tu plan',
    added: 'Agregado',
    replaced: 'Reemplazado',
    rest: 'Día de descanso',
    dashboard: 'Abrir el Dashboard',
  },
}
