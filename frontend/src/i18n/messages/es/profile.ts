import type { Profile } from '@/i18n/messages/en/profile'

export const profile: Profile = {
  title: 'Perfil',
  loading: 'Cargando perfil…',
  height: 'Estatura (cm)',
  weight: 'Peso (kg)',
  age: 'Edad',
  fitnessLevel: 'Nivel de condición física',
  levels: {
    beginner: 'Principiante',
    intermediate: 'Intermedio',
    advanced: 'Avanzado',
  },
  primaryGoal: 'Objetivo principal',
  primaryGoalPlaceholder: 'p. ej., Perder grasa y mantener el músculo',
  secondaryGoal: 'Objetivo secundario',
  equipment: 'Equipamiento (separado por comas)',
  equipmentPlaceholder: 'mancuernas, colchoneta, cinta de correr',
  sessionLength: 'Duración preferida de la sesión (minutos)',
  save: 'Guardar perfil',
  saved: 'Guardado.',
  schedule: {
    title: 'Horario recurrente',
    empty: 'Aún no hay actividades recurrentes.',
    activityPlaceholder: 'Actividad (p. ej., Fútbol)',
    defaultActivity: 'Fútbol',
    intensity: {
      low: 'Baja',
      moderate: 'Moderada',
      high: 'Alta',
    },
    add: 'Agregar',
  },
}
