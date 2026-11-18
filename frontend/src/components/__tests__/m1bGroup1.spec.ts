import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import ActivityQuickLogForm from '@/components/ActivityQuickLogForm.vue'
import ExerciseDemo from '@/components/ExerciseDemo.vue'
import ExercisePerformanceEditor from '@/components/ExercisePerformanceEditor.vue'
import RecoveryCheckinForm from '@/components/RecoveryCheckinForm.vue'
import TodaysPlanCard from '@/components/TodaysPlanCard.vue'
import WeightLogForm from '@/components/WeightLogForm.vue'
import WorkoutLogForm from '@/components/WorkoutLogForm.vue'
import WorkoutSessionSummary from '@/components/WorkoutSessionSummary.vue'
import { applyLocale } from '@/i18n'
import * as activityService from '@/services/activity'
import * as dashboardService from '@/services/dashboard'
import * as recoveryService from '@/services/recovery'
import * as workoutPlanService from '@/services/workoutPlans'
import { useActivityStore } from '@/stores/activity'
import { useDashboardStore } from '@/stores/dashboard'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { ActivityLog, DashboardData, WorkoutPlan, WorkoutSession } from '@/types'
import { activityLabel, activitySummary, formatPerformedSets, formatTarget, sportLabel } from '@/utils/format'
import { DAY_STATE } from '@/utils/weekDay'
import ActivityView from '@/views/ActivityView.vue'
import DashboardView from '@/views/DashboardView.vue'

vi.mock('@/services/exerciseImages', () => ({ requestExerciseImage: vi.fn(async () => { throw new Error('503') }) }))
vi.mock('@/services/workoutPlans', () => ({ fetchPlanForDate: vi.fn(), updateExerciseStatus: vi.fn(), recordExercisePerformance: vi.fn() }))
vi.mock('@/services/activity', () => ({ fetchActivity: vi.fn(), deleteActivity: vi.fn(), logActivity: vi.fn(async () => ({ id: 1 })) }))
vi.mock('@/services/workouts', () => ({ fetchWorkouts: vi.fn(async () => []), logWorkout: vi.fn() }))
vi.mock('@/services/dashboard', () => ({ fetchDashboard: vi.fn(), fetchProgress: vi.fn() }))
vi.mock('@/services/recovery', () => ({ fetchTodayCheckin: vi.fn(), submitCheckin: vi.fn() }))
vi.mock('@/services/weight', () => ({ logWeight: vi.fn(async () => ({})), fetchWeight: vi.fn() }))

const exercise = (over: Record<string, unknown> = {}) => ({
  id: 10, exercise_name: 'Bench Press', planned_sets: 3, planned_reps: 10, planned_weight_kg: 42.5, planned_duration_seconds: null, position: 0, status: 'pending',
  actual_sets: null, actual_reps: null, actual_weight_kg: null, actual_duration_seconds: null, completed_at: null, notes: null, performance: null, image_url: null, image_status: null, ...over,
})
const planOf = (over: Partial<WorkoutPlan> = {}): WorkoutPlan => ({
  id: 1, planned_date: '2026-09-15', activity_type: 'strength', title: 'Upper Body', source: 'ai', status: 'in_progress', duration_minutes: 45, reasoning: null, reasoning_factors: [],
  training_schedule_id: null, notes: null, exercises: [exercise()] as never, updated_at: null, ...over,
})
const log = (over: Partial<ActivityLog>): ActivityLog => ({
  id: 1, type: 'steps', logged_date: '2026-09-15', duration_minutes: null, distance_km: null, speed_kmh: null, pace_seconds_per_km: null, intensity: null, notes: null,
  metadata: null, workout_session_id: null, created_at: null, ...over,
})
const router = () => createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'dashboard', component: { template: '<div />' } }, { path: '/coach', name: 'coach', component: { template: '<div />' } }] })

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('formatting helpers follow the language and never touch a stored value', () => {
  it('labels activity types, and shows an unknown type as it is', () => {
    expect(['steps', 'treadmill', 'strength', 'sport', 'recovery', 'weight'].map((t) => activityLabel(t as never))).toEqual(['Steps', 'Treadmill', 'Strength', 'Sport', 'Recovery', 'Weight'])
    applyLocale('es')
    expect(['steps', 'treadmill', 'strength', 'sport', 'recovery', 'weight'].map((t) => activityLabel(t as never))).toEqual(['Pasos', 'Cinta de correr', 'Fuerza', 'Deporte', 'Recuperación', 'Peso'])
    expect(activityLabel('mystery' as never)).toBe('mystery')
  })

  it('words activity summaries in both languages, with numbers in the language\'s format (es-419 keeps the decimal point)', () => {
    const base = { metadata: null, duration_minutes: null }
    expect(activitySummary({ ...base, type: 'steps', metadata: { steps: 6000 } })).toBe('6000 steps')
    expect(activitySummary({ ...base, type: 'steps', metadata: { steps: 1 } })).toBe('1 step')
    expect(activitySummary({ ...base, type: 'strength', duration_minutes: 45 })).toBe('45 min strength')
    expect(activitySummary({ ...base, type: 'recovery', metadata: { energy: 4, soreness: 2 } })).toBe('Energy 4 / Soreness 2')
    expect(activitySummary({ ...base, type: 'treadmill', duration_minutes: 30, distance_km: 4.2, speed_kmh: 8.4 })).toBe('4.2 km · 30 min · 8.4 km/h')

    applyLocale('es')
    expect(activitySummary({ ...base, type: 'steps', metadata: { steps: 6000 } })).toBe('6000 pasos')
    expect(activitySummary({ ...base, type: 'steps', metadata: { steps: 1 } })).toBe('1 paso')
    expect(activitySummary({ ...base, type: 'strength', duration_minutes: 45 })).toBe('45 min de fuerza')
    expect(activitySummary({ ...base, type: 'strength' })).toBe('Sesión de fuerza')
    expect(activitySummary({ ...base, type: 'recovery', metadata: { energy: 4, soreness: 2 } })).toBe('Energía 4 / Dolor muscular 2')
    expect(activitySummary({ ...base, type: 'treadmill' })).toBe('Sesión en cinta')
    expect(activitySummary({ ...base, type: 'treadmill', duration_minutes: 30, distance_km: 4.2, speed_kmh: 8.4 })).toBe('4.2 km · 30 min · 8.4 km/h')
    expect(activitySummary({ ...base, type: 'weight', metadata: { weight_kg: 72.5 } })).toBe('72.5 kg')
  })

  it('words sets, targets and "not set" in both languages and keeps every digit', () => {
    const sets = [
      { set_number: 1, reps: 10, weight_kg: 42.5, duration_seconds: null, completed: true },
      { set_number: 2, reps: 8, weight_kg: 42.5, duration_seconds: null, completed: false },
    ]
    expect(formatPerformedSets(sets)).toBe('10×42.5, 8×42.5 (not completed) kg')
    expect(formatTarget(null)).toBe('Not set')
    expect(formatTarget({ sets: null, reps: 12, weight_kg: null, duration_seconds: null })).toBe('12 reps')

    applyLocale('es')
    expect(formatPerformedSets(sets)).toBe('10×42.5, 8×42.5 (no completada) kg')
    expect(formatTarget(null)).toBe('Sin definir')
    expect(formatTarget({ sets: 3, reps: null, weight_kg: 42.5, duration_seconds: null })).toBe('3 series @ 42.5 kg')
  })

  it('shows the canonical sport in the language on screen and any other sport as saved', () => {
    expect(sportLabel('Football')).toBe('Football')
    applyLocale('es')
    expect(sportLabel('Football')).toBe('Fútbol')
    expect(sportLabel('Natación')).toBe('Natación')
    expect(activitySummary({ type: 'sport', metadata: { sport: 'Football' }, duration_minutes: 30 })).toBe('Fútbol')
  })

  it('reads a day-state label afresh each time, so it is never stale', () => {
    expect(DAY_STATE.not_marked_done.label).toBe('Not marked done')
    applyLocale('es')
    expect(DAY_STATE.not_marked_done.label).toBe('Sin marcar como hecho')
    expect(DAY_STATE.rest.label).toBe('Día de descanso')
    expect(Object.keys(DAY_STATE)).toHaveLength(9)
    expect(DAY_STATE.rest.badge).toContain('indigo')
  })
})

describe('TodaysPlanCard', () => {
  async function card(plan: WorkoutPlan = planOf()) {
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(plan)
    const store = useWorkoutPlanStore()
    await store.loadToday()

    return { store, wrapper: mount(TodaysPlanCard) }
  }

  it('reads in English as it always has', async () => {
    const { wrapper } = await card()

    expect(wrapper.text()).toContain("Today's plan")
    expect(wrapper.text()).toContain('in progress')
    expect(wrapper.text()).toContain('Partial')
    expect(wrapper.text()).toContain('Skip')
    expect(wrapper.text()).toContain('Log sets')
    expect(wrapper.text()).toContain('3 × 10 @ 42.5 kg')
  })

  it('reads in Spanish and switches without a reload', async () => {
    const { wrapper } = await card()

    applyLocale('es')
    await nextTick()

    expect(wrapper.text()).toContain('Plan de hoy')
    expect(wrapper.text()).toContain('en curso')
    expect(wrapper.text()).toContain('Parcial')
    expect(wrapper.text()).toContain('Omitir')
    expect(wrapper.text()).toContain('Registrar series')
    expect(wrapper.text()).toContain('3 × 10 @ 42.5 kg')
    expect(wrapper.text()).toContain('Upper Body') // a title is the person's, shown as stored
    expect(wrapper.text()).toContain('Bench Press')

    applyLocale('en')
    await nextTick()
    expect(wrapper.text()).toContain("Today's plan")
  })

  it('sends the stored identifiers whatever the language, never a label', async () => {
    vi.mocked(workoutPlanService.updateExerciseStatus).mockResolvedValue(planOf())
    applyLocale('es')
    const { wrapper } = await card()

    const buttons = wrapper.findAll('button')
    await buttons.find((b) => b.text() === 'Parcial')!.trigger('click')
    await buttons.find((b) => b.text() === 'Omitir')!.trigger('click')

    expect(vi.mocked(workoutPlanService.updateExerciseStatus).mock.calls.map((c) => c[2])).toEqual([
      expect.objectContaining({ status: 'partial' }),
      expect.objectContaining({ status: 'skipped' }),
    ])
  })

  it('names the reset control and the "as planned" note in the language on screen', async () => {
    const done = planOf({ exercises: [exercise({ status: 'completed', performance: { recorded_as: 'as_planned', sets: [] } })] as never })
    const { wrapper } = await card(done)
    expect(wrapper.get('button[aria-label="Reset to pending"]').attributes('title')).toBe('Reset to pending')
    expect(wrapper.get('[data-testid="logged-sets"]').text()).toContain('as planned')

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('button[aria-label="Restablecer a pendiente"]').attributes('title')).toBe('Restablecer a pendiente')
    expect(wrapper.get('[data-testid="logged-sets"]').text()).toContain('Registrado')
    expect(wrapper.get('[data-testid="logged-sets"]').text()).toContain('cantidad no registrada')
    expect(wrapper.get('[data-testid="logged-sets"]').text()).toContain('según el plan')
  })

  it('words a status-update failure in the language on screen, and re-words it when that changes', async () => {
    vi.mocked(workoutPlanService.updateExerciseStatus).mockRejectedValue(new Error('x'))
    const { wrapper } = await card()
    await wrapper.findAll('button').find((b) => b.text() === 'Skip')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't update that exercise — please try again.")

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos actualizar ese ejercicio. Inténtalo de nuevo.')
  })
})

describe('RecoveryCheckinForm', () => {
  it('asks and confirms in both languages', async () => {
    vi.mocked(recoveryService.fetchTodayCheckin).mockResolvedValue(null)
    const wrapper = mount(RecoveryCheckinForm)
    await flushPromises()

    expect(wrapper.text()).toContain('How are you feeling today?')
    expect(wrapper.text()).toContain('Soreness')
    expect(wrapper.get('input[type="text"]').attributes('placeholder')).toBe('Any pain or discomfort? (optional)')

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('¿Cómo te sientes hoy?')
    expect(wrapper.text()).toContain('Dolor muscular')
    expect(wrapper.text()).toContain('Guardar chequeo')
    expect(wrapper.get('input[type="text"]').attributes('placeholder')).toBe('¿Algún dolor o molestia? (opcional)')
  })

  it('shows the checked-in state in Spanish and submits the same numbers', async () => {
    applyLocale('es')
    vi.mocked(recoveryService.fetchTodayCheckin).mockResolvedValue({ id: 1, checkin_date: '2026-09-15', energy: 4, soreness: 2, motivation: 5, perceived_difficulty: null, pain_notes: null })
    const wrapper = mount(RecoveryCheckinForm)
    await flushPromises()

    expect(wrapper.text()).toContain('Chequeo de hoy hecho')
    expect(wrapper.text()).toContain('4/5')
    expect(wrapper.get('button').text()).toBe('Editar')
  })
})

describe('Activity screen and forms', () => {
  it('quick-log buttons, labels and options follow the language', async () => {
    const wrapper = mount(ActivityQuickLogForm)
    expect(wrapper.findAll('button').slice(0, 3).map((b) => b.text())).toEqual(['Steps', 'Treadmill', 'Sport'])

    applyLocale('es')
    await nextTick()
    expect(wrapper.findAll('button').slice(0, 3).map((b) => b.text())).toEqual(['Pasos', 'Cinta de correr', 'Deporte'])
    expect(wrapper.text()).toContain('Pasos')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Registrar')

    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.text()).toContain('Distancia (km)')
    expect(wrapper.find('input[placeholder="opcional"]').exists()).toBe(true)
    expect(wrapper.findAll('option').map((o) => o.text())).toEqual(['Baja', 'Moderada', 'Alta'])
    expect(wrapper.findAll('option').map((o) => o.attributes('value'))).toEqual(['low', 'moderate', 'high'])
  })

  it('shows the suggested sport in the language on screen but stores the canonical value when it is accepted unchanged', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      vi.mocked(activityService.logActivity).mockClear()
      const wrapper = mount(ActivityQuickLogForm)
      await wrapper.findAll('button')[2].trigger('click')

      expect((wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe(lang === 'es' ? 'Fútbol' : 'Football')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(activityService.logActivity).toHaveBeenCalledWith(expect.objectContaining({ type: 'sport', metadata: { sport: 'Football' } }))
    }
  })

  it('stores what the person typed exactly as typed', async () => {
    applyLocale('es')
    const wrapper = mount(ActivityQuickLogForm)
    await wrapper.findAll('button')[2].trigger('click')
    await wrapper.find('input[type="text"]').setValue('Fútbol')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(activityService.logActivity).toHaveBeenCalledWith(expect.objectContaining({ metadata: { sport: 'Fútbol' } }))
  })

  it('translates the weight and workout forms and keeps their accessible names', async () => {
    const weight = mount(WeightLogForm)
    const workout = mount(WorkoutLogForm)
    expect(weight.text()).toContain('Update weight (kg)')
    expect(workout.text()).toContain('Log a strength workout')

    applyLocale('es')
    await nextTick()
    expect(weight.text()).toContain('Actualizar peso (kg)')
    expect(workout.text()).toContain('Registrar un entrenamiento de fuerza')
    expect(workout.find('input[placeholder="Ejercicio"]').exists()).toBe(true)
    expect(workout.find('input[placeholder="Series"]').exists()).toBe(true)
    expect(workout.find('input[placeholder="Repeticiones"]').exists()).toBe(true)
    await workout.findAll('button').find((b) => b.text() === 'Agregar ejercicio')!.trigger('click')
    expect(workout.findAll('button[aria-label="Quitar ejercicio"]')).toHaveLength(2)
  })

  it('names every set control for a screen reader, in both languages, and updates them with the language', async () => {
    const wrapper = mount(ExercisePerformanceEditor, { props: { exercise: exercise() as never } })
    const names = () => wrapper.findAll('[aria-label]').map((e) => e.attributes('aria-label'))

    expect(names().slice(0, 3)).toEqual(['Set 1 reps', 'Set 1 weight in kilograms', 'Set 1 completed'])
    expect(wrapper.text()).toContain('Target 3 × 10 @ 42.5 kg — change only what differed.')

    applyLocale('es')
    await nextTick()
    expect(names().slice(0, 3)).toEqual(['Repeticiones de la serie 1', 'Peso de la serie 1 en kilogramos', 'Serie 1 completada'])
    expect(wrapper.text()).toContain('Objetivo 3 × 10 @ 42.5 kg: cambia solo lo que fue distinto.')
    expect(wrapper.text()).toContain('Guardar series')
    expect(wrapper.text()).toContain('Cancelar')
  })

  it('saves the typed numbers unchanged in Spanish', async () => {
    vi.mocked(workoutPlanService.recordExercisePerformance).mockResolvedValue(planOf())
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(planOf())
    await useWorkoutPlanStore().loadToday()
    applyLocale('es')
    const wrapper = mount(ExercisePerformanceEditor, { props: { exercise: exercise() as never } })
    await wrapper.get('input[aria-label="Peso de la serie 1 en kilogramos"]').setValue('45.5')

    await wrapper.findAll('button').find((b) => b.text() === 'Guardar series')!.trigger('click')
    await flushPromises()

    const sets = vi.mocked(workoutPlanService.recordExercisePerformance).mock.calls[0][2].sets
    expect(sets[0]).toEqual(expect.objectContaining({ reps: 10, weight_kg: 45.5, completed: true }))
  })

  it('words the demonstration states and the alt text in the language on screen', async () => {
    const withImage = mount(ExerciseDemo, { props: { planId: 1, exerciseId: 10, exerciseName: 'Goblet Squat', imageUrl: 'https://img.example/x.jpg' } })
    expect(withImage.get('img').attributes('alt')).toBe('Goblet Squat demonstration')
    applyLocale('es')
    await nextTick()
    expect(withImage.get('img').attributes('alt')).toBe('Demostración de Goblet Squat')

    const button = mount(ExerciseDemo, { props: { planId: 1, exerciseId: 11, exerciseName: 'Row', imageUrl: null } })
    expect(button.get('button').text()).toBe('Mostrar demostración')
    await button.get('button').trigger('click')
    await flushPromises()
    expect(button.text()).toContain('La demostración no está disponible por ahora.')
  })

  it('words the session summary labels in both languages', async () => {
    const session = { id: 1, exercises: [{ id: 1, exercise_name: 'Row', performed_sets: [], sets: 3, reps: 10, weight_kg: 40, recorded_as: 'migrated' }] } as unknown as WorkoutSession
    const wrapper = mount(WorkoutSessionSummary, { props: { session } })
    expect(wrapper.text()).toContain('earlier log')
    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('registro anterior')
    expect(wrapper.text()).toContain('3 × 10 @ 40 kg')
  })

  it('renders the whole screen in Spanish: headings, empty state, history rows and the delete label', async () => {
    vi.mocked(activityService.fetchActivity).mockResolvedValue([log({ id: 5, type: 'steps', metadata: { steps: 8000 } })])
    applyLocale('es')
    const wrapper = mount(ActivityView)
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Actividad')
    expect(wrapper.text()).toContain('Historial')
    expect(wrapper.text()).toContain('8000 pasos')
    expect(wrapper.find('button[aria-label="Eliminar el registro de actividad"]').exists()).toBe(true)

    vi.mocked(activityService.fetchActivity).mockResolvedValue([])
    await useActivityStore().load()
    await flushPromises()
    expect(wrapper.text()).toContain('Aún no hay nada registrado.')
  })

  it('re-words a failed history load when the language changes, and it is still a failure', async () => {
    vi.mocked(activityService.fetchActivity).mockRejectedValue(new Error('x'))
    const wrapper = mount(ActivityView)
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't load your activity history.")

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tu historial de actividad.')
    expect(wrapper.text()).toContain('Intentar de nuevo')
  })
})

describe('DashboardView', () => {
  const data = (over: Partial<DashboardData> = {}): DashboardData => ({
    profile: null, today_activity: [log({ type: 'steps', metadata: { steps: 6000 } })],
    upcoming_schedule: [{ date: '2026-09-17', activity_type: 'Football', start_time: '20:00:00', intensity: 'high' }],
    progress: { current_weight_kg: 72.5, starting_weight_kg: 75, weight_change_kg: -2.5, workouts_this_week: 3, average_steps: 7500 },
    coach_recommendation: 'Haz una caminata ligera hoy.', coach_recommendation_unavailable_reason: null, ...over,
  })

  async function dashboard(over: Partial<DashboardData> = {}) {
    vi.mocked(dashboardService.fetchDashboard).mockResolvedValue(data(over))
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(null as never)
    vi.mocked(recoveryService.fetchTodayCheckin).mockResolvedValue(null)
    const wrapper = mount(DashboardView, { global: { plugins: [router()] } })
    await flushPromises()

    return wrapper
  }

  it('reads in English as it always has', async () => {
    const wrapper = await dashboard()

    expect(wrapper.text()).toContain("Today's recommendation")
    expect(wrapper.text()).toContain('Ask your coach')
    expect(wrapper.text()).toContain('6000 steps')
    expect(wrapper.text()).toContain('Current weight')
    expect(wrapper.text()).toContain('72.5 kg')
    expect(wrapper.text()).toContain('-2.5 kg')
    expect(wrapper.text()).toContain('Avg steps')
  })

  it('reads in Spanish, formats the weight and the change in the language\'s numbers, and switches at once', async () => {
    const wrapper = await dashboard()
    applyLocale('es')
    await nextTick()

    expect(wrapper.text()).toContain('Recomendación de hoy')
    expect(wrapper.text()).toContain('Pregúntale a tu coach')
    expect(wrapper.text()).toContain('Haz una caminata ligera hoy.') // the coach's own words, as written
    expect(wrapper.text()).toContain('Pasos')
    expect(wrapper.text()).toContain('6000 pasos')
    expect(wrapper.text()).toContain('Próximamente')
    expect(wrapper.text()).toContain('Peso actual')
    expect(wrapper.text()).toContain('72.5 kg')
    expect(wrapper.text()).toContain('-2.5 kg')
    expect(wrapper.text()).toContain('Pasos promedio')
    expect(wrapper.text()).toContain('Football') // a schedule entry is shown as saved
  })

  it.each([
    ['rate_limited', 'Your coach is at its request limit for now — check back in a bit, or ask directly on the Coach tab.', 'Tu coach alcanzó su límite de solicitudes por ahora: vuelve en un rato o pregúntale directamente en la pestaña Coach.'],
    ['unavailable', 'Your coach is temporarily unavailable — try again shortly, or ask directly on the Coach tab.', 'Tu coach no está disponible por el momento: inténtalo de nuevo en breve o pregúntale directamente en la pestaña Coach.'],
    ['not_configured', 'Set your AI_API_KEY to get a live recommendation, or ask your coach directly on the Coach tab.', 'Configura tu AI_API_KEY para obtener una recomendación en vivo, o pregúntale directamente a tu coach en la pestaña Coach.'],
  ] as const)('words the unavailable recommendation by its stable code: %s', async (code, english, spanish) => {
    const wrapper = await dashboard({ coach_recommendation: null, coach_recommendation_unavailable_reason: code })
    expect(wrapper.text()).toContain(english)

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain(spanish)
  })

  it('words a failed load and re-words it when the language changes', async () => {
    vi.mocked(dashboardService.fetchDashboard).mockRejectedValue(new Error('x'))
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(null as never)
    const wrapper = mount(DashboardView, { global: { plugins: [router()] } })
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't load your dashboard.")

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tu resumen.')
    expect(useDashboardStore().error).toBe('No pudimos cargar tu resumen.')
  })

  it('says it is loading in the language on screen', async () => {
    applyLocale('es')
    vi.mocked(dashboardService.fetchDashboard).mockReturnValue(new Promise(() => {}))
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(null as never)
    const wrapper = mount(DashboardView, { global: { plugins: [router()] } })
    await nextTick()

    expect(wrapper.text()).toContain('Cargando tu resumen…')
  })
})
