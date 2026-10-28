import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { makeItem, makeList } from '@/components/__tests__/progressionFixtures'
import { TODAY, WEEK, makeActivity, makeExercise, makePlan, overviewOf } from '@/components/__tests__/weekFixtures'
import * as progressionService from '@/services/progression'
import * as weeklyPlanService from '@/services/weeklyPlan'
import WeeklyPlanView from '@/views/WeeklyPlanView.vue'

vi.mock('@/services/weeklyPlan', () => ({ fetchOverview: vi.fn(), generateWeek: vi.fn(), submitAdaptation: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))

const week = WEEK

const plan = (date: string, title: string) => makePlan({ id: Number(date.replaceAll('-', '')), planned_date: date, title })

async function mountView(...items: ReturnType<typeof makeItem>[]) {
  vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([plan(week[1], 'Upper'), plan(week[3], 'Lower')]))
  vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
  vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(...items))

  const wrapper = mount(WeeklyPlanView)
  await flushPromises()

  return wrapper
}

describe('WeeklyPlanView and progression', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('marks the day a suggestion applies to, and only that day', async () => {
    const wrapper = await mountView(makeItem({ applies_to: { date: week[1], title: 'Upper', plan_status: 'planned' } }))

    const markers = wrapper.findAll('[data-testid="day-suggestions"]')
    expect(markers).toHaveLength(1)
    expect(markers[0].text()).toBe('1 suggested adjustment to review below')
  })

  it('counts several suggestions on one day, and none for kept or informational ones', async () => {
    const wrapper = await mountView(
      makeItem({ applies_to: { date: week[1], title: 'Upper', plan_status: 'planned' } }),
      makeItem({ exercise: { name: 'Deadlift', slug: 'deadlift' }, applies_to: { date: week[1], title: 'Upper', plan_status: 'planned' } }),
      makeItem({ exercise: { name: 'Squat', slug: 'squat' }, state: 'kept', actionable: false, proposal: null, dismissed: { at: 'x' }, applies_to: { date: week[3], title: 'Lower', plan_status: 'planned' } }),
    )

    const markers = wrapper.findAll('[data-testid="day-suggestions"]')
    expect(markers.map((m) => m.text())).toEqual(['2 suggested adjustments to review below'])
  })

  it('shows no day markers when nothing is suggested, and loads the recommendations once', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="day-suggestions"]').exists()).toBe(false)
    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(1)
  })
})

describe('refreshing suggestions after the week is filled in', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reloads the recommendations once when generation added plans', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([plan(week[1], 'Upper')]))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: true, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())

    mount(WeeklyPlanView)
    await flushPromises()

    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
  })
})

describe('notices about loads the application left for the user to choose', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('shows them when generating the week left a weight unset, and nothing otherwise', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([plan(week[1], 'Upper')]))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())

    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    const quiet = mount(WeeklyPlanView)
    await flushPromises()
    expect(quiet.find('[data-testid="plan-notices"]').exists()).toBe(false)

    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null, notices: ['I left the starting weight for Goblet Squat unset: choose a weight you can lift comfortably.'] })
    const noted = mount(WeeklyPlanView)
    await flushPromises()
    expect(noted.get('[data-testid="plan-notices"]').text()).toContain('I left the starting weight for Goblet Squat unset')
  })
})

async function mountWeek(plans: ReturnType<typeof makePlan>[] = [], activities: Record<string, ReturnType<typeof makeActivity>[]> = {}, ...items: ReturnType<typeof makeItem>[]) {
  vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf(plans, activities))
  vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
  vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(...items))

  const wrapper = mount(WeeklyPlanView, { attachTo: document.body })
  await flushPromises()

  return wrapper
}

describe('the week comes first', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('puts the week, then the coach box, then the progression section, in that order', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')], {}, makeItem())
    const html = wrapper.html()

    const order = ['data-testid="week-strip"', 'data-testid="week-days"', '<textarea', 'id="progression-heading"'].map((marker) => html.indexOf(marker))

    expect(order.every((i) => i >= 0)).toBe(true)
    expect(order).toEqual([...order].sort((a, b) => a - b))
    wrapper.unmount()
  })

  it('shows the week range under the heading, from the week the server chose', async () => {
    const wrapper = await mountWeek()

    expect(wrapper.get('h1').text()).toBe('Weekly Plan')
    expect(wrapper.get('[data-testid="week-range"]').text()).toContain('This week')
    expect(wrapper.get('[data-testid="week-range"]').text()).toMatch(/\d/)
    wrapper.unmount()
  })

  it('shows seven days in a strip and seven day cards, Monday first', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper'), plan(WEEK[3], 'Lower')])

    expect(wrapper.findAll('[data-testid="week-strip"] button')).toHaveLength(7)
    expect(wrapper.findAll('[data-testid="day-card"]').map((c) => c.attributes('data-date'))).toEqual(WEEK)
    wrapper.unmount()
  })

  it('a complete seven-day plan shows every workout title', async () => {
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))

    expect(wrapper.findAll('[data-testid="day-heading"]').map((h) => h.text())).toEqual(Array.from({ length: 7 }, (_, i) => `Session ${i + 1}`))
    wrapper.unmount()
  })

  it('a week with one or two workouts shows the other days as empty, never as rest', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper'), plan(WEEK[4], 'Lower')])

    const headings = wrapper.findAll('[data-testid="day-heading"]').map((h) => h.text())
    expect(headings.filter((h) => h === 'Upper' || h === 'Lower')).toHaveLength(2)
    expect(headings.filter((h) => /^(Nothing planned|No plan or activity recorded)$/.test(h))).toHaveLength(5)
    expect(wrapper.text()).not.toContain('Rest day')
    wrapper.unmount()
  })

  it('an unplanned workout appears on its own day', async () => {
    const wrapper = await mountWeek([], { [WEEK[1]]: [makeActivity({ logged_date: WEEK[1] })] })

    const card = wrapper.get(`[data-testid="day-card"][data-date="${WEEK[1]}"]`)
    expect(card.get('[data-testid="day-heading"]').text()).toBe('Soccer')
    expect(card.get('[data-testid="day-state"]').text()).toBe('Unplanned activity')
    wrapper.unmount()
  })

  it('opens today\'s details first and the rest on tap', async () => {
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))
    const isOpen = (date: string) => wrapper.get(`[data-testid="day-card"][data-date="${date}"] [data-testid="day-toggle"]`).attributes('aria-expanded')

    expect(isOpen(TODAY)).toBe('true')
    const other = WEEK.find((d) => d !== TODAY)!
    expect(isOpen(other)).toBe('false')

    await wrapper.get(`[data-testid="day-card"][data-date="${other}"] [data-testid="day-toggle"]`).trigger('click')
    expect(isOpen(other)).toBe('true')
    expect(isOpen(TODAY)).toBe('true')

    await wrapper.get(`[data-testid="day-card"][data-date="${other}"] [data-testid="day-toggle"]`).trigger('click')
    expect(isOpen(other)).toBe('false')
    wrapper.unmount()
  })

  it('tapping a day in the strip opens it and selects it', async () => {
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))
    const target = WEEK.find((d) => d !== TODAY)!
    Element.prototype.scrollIntoView = vi.fn()

    await wrapper.get(`[data-testid="week-strip"] [data-date="${target}"]`).trigger('click')
    await flushPromises()

    expect(wrapper.get(`[data-testid="day-card"][data-date="${target}"] [data-testid="day-toggle"]`).attributes('aria-expanded')).toBe('true')
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${target}"]`).attributes('aria-pressed')).toBe('true')
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('still lets the user tell the coach about a change to their week', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue({ applied: false, explanation: "That doesn't affect your training." })
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')])

    await wrapper.get('textarea').setValue('I have soccer on Wednesday')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(weeklyPlanService.submitAdaptation).toHaveBeenCalledWith('I have soccer on Wednesday')
    expect(wrapper.text()).toContain("That doesn't affect your training.")
    wrapper.unmount()
  })

  it('shows the week even when the progression recommendations cannot load', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([plan(WEEK[1], 'Upper')]))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValue(new Error('network'))

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()

    expect(wrapper.findAll('[data-testid="day-card"]')).toHaveLength(7)
    expect(wrapper.find('[data-testid="error"]').exists()).toBe(true)
  })

  it('shows what is planned and what was recorded for an opened past day', async () => {
    const monday = makePlan({
      id: 9, planned_date: WEEK[0], title: 'Chest', status: 'completed',
      exercises: [makeExercise({ status: 'completed', performance: { recorded_as: 'entered', sets: [{ set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true }] } })],
    })
    const wrapper = await mountWeek([monday])

    await wrapper.get(`[data-testid="day-card"][data-date="${WEEK[0]}"] [data-testid="day-toggle"]`).trigger('click')
    if (WEEK[0] === TODAY) await wrapper.get(`[data-testid="day-card"][data-date="${WEEK[0]}"] [data-testid="day-toggle"]`).trigger('click')

    const card = wrapper.get(`[data-testid="day-card"][data-date="${WEEK[0]}"]`)
    expect(card.get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 40 kg')
    expect(card.get('[data-testid="exercise-recorded"]').text()).toBe('10×40 kg')
    wrapper.unmount()
  })
})

describe('the week from the server decides what today is', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('uses the server\'s today, not the browser clock', async () => {
    const overview = overviewOf([])
    overview.week.today = WEEK[5]
    overview.days = overview.days.map((d) => ({ ...d, is_today: d.date === WEEK[5], is_past: d.date < WEEK[5] }))
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overview)
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()

    expect(wrapper.findAll('[data-testid="today-chip"]')).toHaveLength(1)
    expect(wrapper.find(`[data-testid="day-card"][data-date="${WEEK[5]}"] [data-testid="today-chip"]`).exists()).toBe(true)
    expect(wrapper.get(`[data-testid="day-card"][data-date="${WEEK[5]}"] [data-testid="day-toggle"]`).attributes('aria-expanded')).toBe('true')
  })

  it('asks the server for the week once (plus once more only if filling it added plans)', async () => {
    await mountWeek([plan(WEEK[1], 'Upper')])

    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(1)
  })
})

describe('an approved progression is visible in the week', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('re-reads the week after an approval, so the day shows the new load, and does not refill the week', async () => {
    const withLoad = (kg: number) => overviewOf([makePlan({ id: 3, planned_date: TODAY, title: 'Lower Body', exercises: [makeExercise({ planned_weight_kg: kg })] })])
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValueOnce(withLoad(40))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValueOnce(makeList(makeItem({ applies_to: { date: TODAY, title: 'Lower Body', plan_status: 'planned' } })))
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: TODAY, from_kg: 40, to_kg: 42.5 })

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()
    const today = () => wrapper.get(`[data-testid="day-card"][data-date="${TODAY}"]`)
    expect(today().get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 40 kg')

    // After the approval the server holds 42.5 kg and has nothing more to suggest.
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(withLoad(42.5))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()

    expect(today().get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 42.5 kg')
    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(2)
    expect(weeklyPlanService.generateWeek).toHaveBeenCalledTimes(1)
  })

  it('keeping the current target does not change or reload the week', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([makePlan({ id: 3, planned_date: TODAY })]))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(makeItem({ applies_to: { date: TODAY, title: 'Upper', plan_status: 'planned' } })))
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true } as never)

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()
    await wrapper.get('[data-testid="keep"]').trigger('click')
    await flushPromises()

    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(1)
  })
})

