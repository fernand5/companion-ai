import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import ProgressionCard from '@/components/ProgressionCard.vue'
import ProgressionRecommendations from '@/components/ProgressionRecommendations.vue'
import WeekDayCard from '@/components/WeekDayCard.vue'
import WeekStrip from '@/components/WeekStrip.vue'
import WeeklySummaryCard from '@/components/WeeklySummaryCard.vue'
import { applyLocale } from '@/i18n'
import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import * as progressionService from '@/services/progression'
import * as weeklyPlanService from '@/services/weeklyPlan'
import { useProgressionStore, type ProgressionActionState } from '@/stores/progression'
import type { WeeklyPlanDay } from '@/types'
import ProgressView from '@/views/ProgressView.vue'
import WeeklyPlanView from '@/views/WeeklyPlanView.vue'

import { dismissedItem, insufficient, makeItem, makeList, manualReview } from './progressionFixtures'
import { WEEK, makeActivity, makeDay, makeExercise, makePlan, makeSteps, overviewOf } from './weekFixtures'

vi.mock('@/services/weeklyPlan', () => ({ fetchOverview: vi.fn(), generateWeek: vi.fn(), submitAdaptation: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))
vi.mock('@/services/dashboard', () => ({ fetchProgress: vi.fn(), fetchDashboard: vi.fn() }))
vi.mock('@/services/adherence', () => ({ fetchAdherence: vi.fn() }))
vi.mock('@/services/coach', () => ({ fetchWeeklySummary: vi.fn() }))
vi.mock('vue-chartjs', () => ({
  Bar: { props: ['data', 'options'], template: '<canvas data-testid="chart" :data-locale="options.locale" :data-label="data.datasets[0].label" />' },
  Line: { props: ['data', 'options'], template: '<canvas data-testid="chart" :data-locale="options.locale" :data-label="data.datasets[0].label" />' },
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

const FUTURE = '2999-01-04'
const PAST = '2000-01-03'
const card = (day: WeeklyPlanDay, props: Record<string, unknown> = {}) => mount(WeekDayCard, { props: { day, expanded: true, suggestions: 0, collapsible: true, ...props } })
const futureDay = (plan: ReturnType<typeof makePlan> | null, extra: Partial<WeeklyPlanDay> = {}) => makeDay(FUTURE, plan && { ...plan, planned_date: FUTURE }, [], { is_past: false, is_today: false, ...extra })
const pastDay = (plan: ReturnType<typeof makePlan> | null, activities = [] as ReturnType<typeof makeActivity>[], extra: Partial<WeeklyPlanDay> = {}) =>
  makeDay(PAST, plan && { ...plan, planned_date: PAST }, activities, { is_past: true, is_today: false, ...extra })
const router = () =>
  createRouter({ history: createMemoryHistory(), routes: [{ path: '/weekly-plan', name: 'weekly-plan', component: { template: '<div />' } }, { path: '/progress', name: 'progress', component: { template: '<div />' } }] })

describe('WeekDayCard in both languages', () => {
  it('words the status badge, type, duration and exercise rows, and switches at once', async () => {
    const wrapper = card(futureDay(makePlan({ title: 'Upper Body', activity_type: 'strength', duration_minutes: 45, exercises: [makeExercise({ exercise_name: 'Bench Press' })] })))

    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Planned')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('Strength')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('45 min')

    applyLocale('es')
    await nextTick()

    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Planificado')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('Fuerza')
    expect(wrapper.get('[data-testid="day-type"]').text()).toContain('45 min')
    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper Body') // the plan's own title
    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.text()).toContain('Planificado')
    expect(wrapper.text()).toContain('Registrado')
  })

  it.each([
    ['completed', 'Completed', 'Completado'], ['partial', 'Partly done', 'Hecho en parte'], ['in_progress', 'In progress', 'En curso'],
    ['planned', 'Planned', 'Planificado'], ['not_marked_done', 'Not marked done', 'Sin marcar como hecho'], ['skipped', 'Skipped', 'Omitido'],
    ['rest', 'Rest day', 'Día de descanso'], ['activity_only', 'Unplanned activity', 'Actividad no planificada'],
  ] as const)('shows the %s state in both languages without changing the stored state', async (state, english, spanish) => {
    const wrapper = card(futureDay(makePlan(), { state }))
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe(english)
    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe(spanish)
    expect(wrapper.get('[data-testid="day-card"]').attributes('data-state')).toBe(state)
  })

  it('words the empty-day headings and explanations', async () => {
    const nothing = card(pastDay(null))
    expect(nothing.get('[data-testid="day-heading"]').text()).toBe('No plan or activity recorded')
    expect(nothing.get('[data-testid="day-explanation"]').text()).toBe('Nothing was planned or logged for this day.')
    const rest = card(futureDay(null, { state: 'rest' }))
    expect(rest.get('[data-testid="day-heading"]').text()).toBe('Rest day')

    applyLocale('es')
    await nextTick()
    expect(nothing.get('[data-testid="day-heading"]').text()).toBe('Sin plan ni actividad registrados')
    expect(nothing.get('[data-testid="day-explanation"]').text()).toBe('No se planificó ni se registró nada para este día.')
    expect(rest.get('[data-testid="day-heading"]').text()).toBe('Día de descanso')
    expect(rest.get('[data-testid="day-explanation"]').text()).toBe('Un día de descanso planificado.')
    expect(card(futureDay(null)).get('[data-testid="day-explanation"]').text()).toBe('Aún no hay un entrenamiento planificado para este día.')
    expect(card(futureDay(null)).get('[data-testid="day-heading"]').text()).toBe('Nada planificado')
  })

  it('words what is recorded for an exercise without calling a one-tap "done" measured or an untouched one missed', async () => {
    const day = pastDay(makePlan({ exercises: [
      makeExercise({ id: 1, exercise_name: 'A', status: 'completed', performance: { recorded_as: 'as_planned', sets: [] } as never }),
      makeExercise({ id: 2, exercise_name: 'B', status: 'partial', performance: null }),
      makeExercise({ id: 3, exercise_name: 'C', status: 'skipped', performance: null }),
      makeExercise({ id: 4, exercise_name: 'D', status: 'pending', performance: null }),
    ] }))
    const wrapper = card(day)
    const recorded = () => wrapper.findAll('[data-testid="exercise-recorded"]').map((e) => e.text())

    expect(recorded()).toEqual(['Marked done, amounts not recorded', 'Marked partly done, amounts not recorded', 'Skipped', 'Not recorded'])
    applyLocale('es')
    await nextTick()
    expect(recorded()).toEqual(['Marcado como hecho, cantidades sin registrar', 'Marcado como hecho en parte, cantidades sin registrar', 'Omitido', 'Sin registrar'])
  })

  it('pluralises the suggestions and the review button naturally in Spanish, and keeps the stored count', async () => {
    const one = card(futureDay(makePlan()), { suggestions: 1 })
    const many = card(futureDay(makePlan()), { suggestions: 3 })
    expect(one.get('[data-testid="day-suggestions"]').text()).toBe('1 suggested adjustment to review in Progress')
    expect(many.get('[data-testid="day-suggestions"]').text()).toBe('3 suggested adjustments to review in Progress')

    applyLocale('es')
    await nextTick()
    expect(one.get('[data-testid="day-suggestions"]').text()).toBe('1 ajuste sugerido para revisar en Progreso')
    expect(many.get('[data-testid="day-suggestions"]').text()).toBe('3 ajustes sugeridos para revisar en Progreso')
    expect(one.get('[data-testid="show-suggestions"]').text()).toBe('Revisar el ajuste en Progreso')
    expect(many.get('[data-testid="show-suggestions"]').text()).toBe('Revisar los ajustes en Progreso')
  })

  it('words steps in both languages: separate entries are listed and never summed', async () => {
    const day = pastDay(null, [], { steps: [makeSteps({ id: 1, steps: 6000 }), makeSteps({ id: 2, steps: 1500 }), makeSteps({ id: 3, steps: null })] })
    const wrapper = card(day)
    expect(wrapper.get('[data-testid="day-steps-summary"]').text()).toBe('3 step entries')
    expect(wrapper.findAll('[data-testid="steps-count"]').map((e) => e.text())).toEqual(['6,000 steps', '1,500 steps', 'Steps logged (count not recorded)'])

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[data-testid="day-steps-summary"]').text()).toBe('3 registros de pasos')
    expect(wrapper.findAll('[data-testid="steps-count"]').map((e) => e.text())).toEqual(['6,000 pasos', '1,500 pasos', 'Pasos registrados (cantidad no registrada)'])
    expect(wrapper.get('[data-testid="steps-note"]').text()).toBe('3 registros por separado, mostrados tal como se registraron y sin sumarlos.')
    expect(wrapper.text()).toContain('Los pasos son una medición diaria.')
    expect(wrapper.get('[data-testid="steps"]').attributes('aria-label')).toBe('Pasos')
  })

  it('shows the canonical sport in the language on screen and a typed sport as saved', async () => {
    const wrapper = card(pastDay(null, [makeActivity({ id: 1, type: 'sport', metadata: { sport: 'Football' } }), makeActivity({ id: 2, type: 'sport', metadata: { sport: 'Tenis' } })]))
    expect(wrapper.findAll('[data-testid="activity"]').map((a) => a.find('p span').text())).toEqual(['Football', 'Tenis'])
    applyLocale('es')
    await nextTick()
    expect(wrapper.findAll('[data-testid="activity"]').map((a) => a.find('p span').text())).toEqual(['Fútbol', 'Tenis'])
  })
})

describe('WeekStrip', () => {
  it('names each day for a screen reader from its date, in the language on screen', async () => {
    const days = [makeDay(WEEK[0], null, [], { is_today: true, steps: [makeSteps()] }), makeDay(WEEK[1])]
    const wrapper = mount(WeekStrip, { props: { days, selected: WEEK[0] } })
    const names = () => wrapper.findAll('button').map((b) => b.attributes('aria-label')!)

    expect(wrapper.get('nav').attributes('aria-label')).toBe('Days of this week')
    expect(names()[0]).toMatch(/^Monday, .+, today: .+, steps logged$/)

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('nav').attributes('aria-label')).toBe('Días de esta semana')
    expect(names()[0]).toMatch(/^lunes, .+, hoy: .+, pasos registrados$/)
    expect(names()[0]).not.toContain('Monday')
  })
})

describe('WeeklyPlanView in both languages', () => {
  async function view() {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([makePlan({ id: 7, planned_date: WEEK[1], title: 'Upper' })]))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    const wrapper = mount(WeeklyPlanView, { global: { plugins: [router()] } })
    await flushPromises()

    return wrapper
  }

  it('reads in English as it always has', async () => {
    const wrapper = await view()
    expect(wrapper.get('h1').text()).toBe('Weekly Plan')
    expect(wrapper.text()).toContain('This week')
    expect(wrapper.get('[data-testid="toggle-full-week"]').text()).toBe('Show full week')
    expect(wrapper.get('textarea').attributes('placeholder')).toContain('e.g. "I have soccer on Wednesday"')
  })

  it('reads in Spanish and switches without a reload, naming the selected day from its date', async () => {
    const wrapper = await view()
    applyLocale('es')
    await nextTick()

    expect(wrapper.get('h1').text()).toBe('Plan semanal')
    expect(wrapper.text()).toContain('Esta semana')
    expect(wrapper.get('[data-testid="toggle-full-week"]').text()).toBe('Mostrar la semana completa')
    expect(wrapper.get('textarea').attributes('placeholder')).toContain('El miércoles tengo fútbol')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Enviar')
    // The day's name comes from its date, never from the server's English weekday.
    expect(wrapper.get('[data-testid="selected-day"]').attributes('aria-label')).toMatch(/^Detalles de [a-záéíóú]+$/)

    await wrapper.get('[data-testid="toggle-full-week"]').trigger('click')
    expect(wrapper.get('[data-testid="toggle-full-week"]').text()).toBe('Mostrar solo el día seleccionado')
  })

  it('words a failed load and re-words it when the language changes', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockRejectedValue(new Error('x'))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    const wrapper = mount(WeeklyPlanView, { global: { plugins: [router()] } })
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't load your weekly plan.")

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tu plan semanal.')
    expect(wrapper.text()).toContain('Intentar de nuevo')
  })

  it('shows the coach\'s explanation and the notices the server wrote as they came', async () => {
    const wrapper = await view()
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue({ applied: false, explanation: 'No hace falta ningún cambio.', notices: ['Dejé sin definir el peso inicial.'] } as never)
    await wrapper.get('textarea').setValue('x')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    applyLocale('es')
    await nextTick()

    expect(wrapper.text()).toContain('No hace falta ningún cambio.')
    expect(wrapper.get('[data-testid="plan-notices"]').text()).toContain('Dejé sin definir el peso inicial.')
  })
})

describe('WeeklySummaryCard', () => {
  const summary = { week_start: '2026-10-19', stats: { adherence_pct: 80, planned_workouts_completed: 4, active_days: 4 }, coach_insight: 'Buen ritmo.', next_week_focus: 'Más sueño.', generated_at: '2026-10-25T15:00:00Z' } as never

  it('words its labels in both languages and shows the coach text as written', async () => {
    const wrapper = mount(WeeklySummaryCard, { props: { summary, loading: false, error: null } })
    expect(wrapper.text()).toContain('Your week')
    expect(wrapper.text()).toContain('Coach insight:')
    expect(wrapper.text()).toMatch(/As of /)

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('Tu semana')
    expect(wrapper.text()).toContain('Planificados hechos')
    expect(wrapper.text()).toContain('Observación del coach: Buen ritmo.')
    expect(wrapper.text()).toContain('Enfoque de la próxima semana: Más sueño.')
    expect(wrapper.text()).toMatch(/Al /)
  })
})

describe('ProgressView in both languages', () => {
  const PROGRESS = {
    summary: { current_weight_kg: 80.5, starting_weight_kg: 85, weight_change_kg: -4.5, workouts_this_week: 3, average_steps: 6000 },
    weight_series: [{ date: '2026-10-20', weight_kg: 80.5 }], steps_series: [], weekly_workouts: [{ week_start: '2026-10-26', workouts: 3 }],
  }
  const ADHERENCE = {
    series: [{ week_start: '2026-10-19', week_end: '2026-10-25', adherence_pct: 80 }],
    summary: { start_date: '2026-10-19', end_date: '2026-10-25', adherence_pct: 80, due_exercise_count: 5, planned_workouts_completed: 4, planned_workouts_partial: 0, planned_workouts_skipped: 1, active_days: 1 },
  }

  async function view() {
    vi.mocked(dashboardService.fetchProgress).mockResolvedValue(PROGRESS as never)
    vi.mocked(adherenceService.fetchAdherence).mockResolvedValue(ADHERENCE as never)
    vi.mocked(coachService.fetchWeeklySummary).mockRejectedValue(new Error('x'))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    const wrapper = mount(ProgressView)
    await flushPromises()

    return wrapper
  }

  it('reads in English as it always has', async () => {
    const wrapper = await view()
    expect(wrapper.get('h1').text()).toBe('Progress')
    expect(wrapper.get('[data-testid="trends-heading"]').text()).toBe('Trends and history')
    expect(wrapper.text()).toContain('Last 7 days: 1 active day(s)')
    expect(wrapper.text()).toContain('No steps logged yet.')
    expect(wrapper.findAll('[data-testid="chart"]').map((c) => c.attributes('data-label'))).toEqual(['Adherence %', 'Weight (kg)', 'Workouts'])
  })

  it('reads in Spanish, pluralises the active days, relabels and re-locales the charts without a reload', async () => {
    const wrapper = await view()
    applyLocale('es')
    await nextTick()

    expect(wrapper.get('h1').text()).toBe('Progreso')
    expect(wrapper.get('[data-testid="trends-heading"]').text()).toBe('Tendencias e historial')
    expect(wrapper.text()).toContain('Últimos 7 días: 1 día activo')
    expect(wrapper.text()).toContain('Aún no hay pasos registrados.')
    expect(wrapper.text()).toContain('80.5 kg')
    expect(wrapper.findAll('[data-testid="chart"]').map((c) => c.attributes('data-label'))).toEqual(['Cumplimiento %', 'Peso (kg)', 'Entrenamientos'])
    expect(wrapper.findAll('[data-testid="chart"]').every((c) => c.attributes('data-locale') === 'es-419')).toBe(true)

    applyLocale('en')
    await nextTick()
    expect(wrapper.findAll('[data-testid="chart"]').every((c) => c.attributes('data-locale') === 'en-US')).toBe(true)
  })

  it('words the failures of each section and re-words them when the language changes', async () => {
    vi.mocked(dashboardService.fetchProgress).mockRejectedValue(new Error('x'))
    vi.mocked(adherenceService.fetchAdherence).mockRejectedValue(new Error('x'))
    vi.mocked(coachService.fetchWeeklySummary).mockRejectedValue(new Error('x'))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    const wrapper = mount(ProgressView)
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't load your progress.")

    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tu progreso.')
  })
})

describe('ProgressionCard in both languages', () => {
  const idle: ProgressionActionState = { status: 'idle', message: null }
  const mountCard = (item = makeItem(), state: ProgressionActionState = idle, props: Record<string, unknown> = {}) => mount(ProgressionCard, { props: { item, state, ...props } })

  it.each([
    ['INCREASE', 'Increase', 'Aumentar'], ['DECREASE', 'Decrease', 'Disminuir'], ['MAINTAIN', 'Keep as is', 'Mantener'],
    ['INSUFFICIENT_DATA', 'Not enough data', 'Datos insuficientes'], ['MANUAL_REVIEW', 'Needs your review', 'Requiere tu revisión'],
  ] as const)('words the %s decision and leaves the stored decision alone', async (decision, english, spanish) => {
    const wrapper = mountCard(makeItem({ decision }))
    expect(wrapper.get('[data-testid="decision"]').text()).toBe(english)
    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[data-testid="decision"]').text()).toBe(spanish)
    expect(wrapper.get('article').attributes('data-decision')).toBe(decision)
  })

  it('words the card in Spanish while the loads, dates and the server\'s own reasons are shown as they came', async () => {
    const wrapper = mountCard()
    applyLocale('es')
    await nextTick()

    expect(wrapper.get('[data-testid="current"]').text()).toBe('3×10 @ 40 kg')
    expect(wrapper.get('[data-testid="proposed"]').text()).toContain('3×10 @ 42.5 kg')
    expect(wrapper.get('[data-testid="proposed"]').text()).toContain('+6.3%')
    expect(wrapper.text()).toContain('Objetivo actual')
    expect(wrapper.text()).toContain('Propuesto')
    expect(wrapper.get('[data-testid="applies-to"]').text()).toContain('Se aplica solo a tu próxima sesión del')
    expect(wrapper.get('[data-testid="evidence"]').text()).toBe('Evidencia sólida')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('objetivo no alcanzado')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('(2 de 3 series)')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('objetivo alcanzado')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('10,10,10 @ 40 kg')
    expect(wrapper.get('[data-testid="reasons"]').text()).toContain('The target was reached in each of the last 3 sessions') // the server's text, as it came
    expect(wrapper.get('[data-testid="accept"]').text()).toBe('Aceptar recomendación')
    expect(wrapper.get('[data-testid="keep"]').text()).toBe('Mantener el objetivo actual')
    expect(wrapper.text()).toContain('Nada cambia hasta que aceptes.')
  })

  it.each([
    ['no_upcoming_session', 'There is no upcoming session to apply this to.', 'No hay una próxima sesión a la que aplicar esto.'],
    ['workout_already_started', "That session has already been started, so its target can't be changed.", 'Esa sesión ya se inició, así que su objetivo no se puede cambiar.'],
    ['no_load_in_plan', 'The upcoming session has no load set to change.', 'La próxima sesión no tiene una carga que cambiar.'],
    ['something_new', "This can't be applied right now.", 'Esto no se puede aplicar en este momento.'],
  ])('explains "%s" from the server\'s reason code, in the language on screen', async (reason, english, spanish) => {
    const wrapper = mountCard(makeItem({ actionable: false, proposal: null, not_actionable_reason: reason, state: 'no_change' }))
    expect(wrapper.get('[data-testid="unavailable"]').text()).toBe(english)
    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[data-testid="unavailable"]').text()).toBe(spanish)
  })

  it('offers an action only when the server issued a proposal, whatever the language', async () => {
    applyLocale('es')
    expect(mountCard().find('[data-testid="actions"]').exists()).toBe(true)
    expect(mountCard(makeItem({ actionable: false, proposal: null })).find('[data-testid="actions"]').exists()).toBe(false)
    expect(mountCard(insufficient()).text()).toContain('No se hará ningún cambio en tu plan.')
    expect(mountCard(manualReview()).find('[data-testid="actions"]').exists()).toBe(false)
  })

  it('words the kept card and the status notes, and emits the same events', async () => {
    applyLocale('es')
    const kept = mountCard(dismissedItem())
    expect(kept.get('[data-testid="decision"]').text()).toBe('Objetivo actual mantenido')
    expect(kept.get('[data-testid="status-note"]').text()).toBe('Mantuviste tu objetivo actual. Aparecerá una recomendación nueva si registras series nuevas o cambias tu plan.')

    const stale = mountCard(makeItem(), { status: 'stale', message: 'La recomendación venció.' })
    expect(stale.get('[data-testid="refresh"]').text()).toBe('Obtener una recomendación nueva')
    await stale.get('[data-testid="refresh"]').trigger('click')
    expect(stale.emitted('refresh')).toHaveLength(1)

    const live = mountCard()
    await live.get('[data-testid="accept"]').trigger('click')
    await live.get('[data-testid="keep"]').trigger('click')
    expect(live.emitted('accept')).toHaveLength(1)
    expect(live.emitted('keep')).toHaveLength(1)
  })
})

describe('ProgressionRecommendations and the progression store', () => {
  async function recommendations(...items: ReturnType<typeof makeItem>[]) {
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(...items))
    const wrapper = mount(ProgressionRecommendations, { attachTo: document.body })
    await flushPromises()

    return wrapper
  }

  it('words the section, the plural summary and the collapsed group in both languages', async () => {
    const wrapper = await recommendations(makeItem(), makeItem({ exercise: { name: 'Squat', slug: 'squat' }, proposal: { id: 'sealed-2', expires_at: '2026-10-17T12:30:00+00:00' } }), insufficient())
    expect(wrapper.get('[data-testid="summary"]').text()).toBe('2 suggested adjustments. Review each one; accepting one never accepts another.')
    expect(wrapper.get('[data-testid="no-action"]').text()).toContain('1 exercise needs no action')

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('h2').text()).toBe('Progresión')
    expect(wrapper.get('[data-testid="reload"]').text()).toBe('Actualizar')
    expect(wrapper.get('[data-testid="summary"]').text()).toBe('2 ajustes sugeridos. Revisa cada uno; aceptar uno nunca acepta otro.')
    expect(wrapper.get('[data-testid="no-action"]').text()).toContain('1 ejercicio no requiere acción')
  })

  it('words the empty state in both languages', async () => {
    const wrapper = await recommendations()
    expect(wrapper.get('[data-testid="empty"]').text()).toContain('Nothing to show yet.')
    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[data-testid="empty"]').text()).toContain('Aún no hay nada que mostrar.')
  })

  it('sends the same sealed proposal to accept and to keep in either language', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      vi.mocked(progressionService.approveProposal).mockReset()
      vi.mocked(progressionService.keepCurrentTarget).mockReset()
      vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 } as never)
      vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true } as never)
      const store = useProgressionStore()
      const item = makeItem()

      await store.accept(item)
      await store.keep(item)

      expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')
      expect(progressionService.keepCurrentTarget).toHaveBeenCalledWith('sealed-proposal-1')
    }
  })

  it('words the applied and kept notes in the language on screen, and re-words them when it changes', async () => {
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 } as never)
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(makeItem()))
    const store = useProgressionStore()
    await store.accept(makeItem())
    expect(store.stateFor('Bench Press')).toEqual({ status: 'applied', message: 'Bench Press is now 42.5 kg for 2026-10-19.' })

    applyLocale('es')
    expect(store.stateFor('Bench Press')).toEqual({ status: 'applied', message: 'Bench Press ahora está en 42.5 kg para el 2026-10-19.' })

    // (The list is read again after "keep"; a read that fails leaves the note on screen, which is what is checked here.)
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValue(new Error('x'))
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true } as never)
    await store.keep(makeItem({ exercise: { name: 'Squat', slug: 'squat' } }))
    expect(store.stateFor('Squat').message).toBe('Se mantiene Squat como estaba planeado.')
    applyLocale('en')
    expect(store.stateFor('Squat').message).toBe('Keeping Squat as planned.')
  })

  it('keeps a stale proposal stale and a failure a failure when the language changes, wording only the fallback', async () => {
    const store = useProgressionStore()
    vi.mocked(progressionService.approveProposal).mockRejectedValue({ response: { status: 409, data: { message: 'Esa recomendación venció.' } } })
    await store.accept(makeItem())
    expect(store.stateFor('Bench Press')).toEqual({ status: 'stale', message: 'Esa recomendación venció.' })

    vi.mocked(progressionService.approveProposal).mockRejectedValue(new Error('Network Error'))
    await store.accept(makeItem({ exercise: { name: 'Squat', slug: 'squat' } }))
    expect(store.stateFor('Squat')).toEqual({ status: 'failed', message: "Couldn't apply that recommendation — please try again." })

    applyLocale('es')
    expect(store.stateFor('Squat')).toEqual({ status: 'failed', message: 'No pudimos aplicar esa recomendación. Inténtalo de nuevo.' })
    expect(store.stateFor('Bench Press').message).toBe('Esa recomendación venció.') // the server's words are not replaced
    expect(store.stateFor('Bench Press').status).toBe('stale')
  })

  it('re-words a failed load of the list when the language changes', async () => {
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValue(new Error('x'))
    const wrapper = mount(ProgressionRecommendations)
    await flushPromises()
    expect(wrapper.text()).toContain("Couldn't load your progression recommendations.")
    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tus recomendaciones de progresión.')
  })
})
