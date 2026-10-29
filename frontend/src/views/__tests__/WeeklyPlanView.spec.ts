import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { makeItem, makeList } from '@/components/__tests__/progressionFixtures'
import { TODAY, WEEK, makeActivity, makeExercise, makePlan, makeSteps, overviewOf } from '@/components/__tests__/weekFixtures'
import * as progressionService from '@/services/progression'
import * as weeklyPlanService from '@/services/weeklyPlan'
import { formatDate } from '@/utils/format'
import WeeklyPlanView from '@/views/WeeklyPlanView.vue'

vi.mock('@/services/weeklyPlan', () => ({ fetchOverview: vi.fn(), generateWeek: vi.fn(), submitAdaptation: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))

const week = WEEK

/** Switch to the all-seven-days view. */
async function showFullWeek(wrapper: ReturnType<typeof mount>) {
  await wrapper.get('[data-testid="toggle-full-week"]').trigger('click')
}

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
    await showFullWeek(wrapper)

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
    await showFullWeek(wrapper)

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

  it('puts the week, then the selected day, then the coach box, then the progression section, in that order', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')], {}, makeItem())
    const html = wrapper.html()

    const order = ['data-testid="week-strip"', 'data-testid="selected-day"', '<textarea', 'id="progression-heading"'].map((marker) => html.indexOf(marker))

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

  it('shows seven days in the strip, Monday first', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper'), plan(WEEK[3], 'Lower')])

    expect(wrapper.findAll('[data-testid="week-strip"] button')).toHaveLength(7)
    expect(wrapper.findAll('[data-testid="week-strip"] button').map((b) => b.attributes('data-date'))).toEqual(WEEK)
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

  it('shows the selected day even when the progression recommendations cannot load', async () => {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf([plan(WEEK[1], 'Upper')]))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValue(new Error('network'))

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()

    expect(wrapper.findAll('[data-testid="day-card"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="error"]').exists()).toBe(true)
  })
})

describe('one day on show', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const strip = (wrapper: ReturnType<typeof mount>, date: string) => wrapper.get(`[data-testid="week-strip"] [data-date="${date}"]`)
  const shown = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('[data-testid="day-card"]').map((c) => c.attributes('data-date'))

  it('starts on today, with only today\'s details on screen', async () => {
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))

    expect(shown(wrapper)).toEqual([TODAY])
    expect(strip(wrapper, TODAY).attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('[data-testid="day-heading"]')).toHaveLength(1)
    expect(wrapper.get('[data-testid="day-body"]').isVisible()).toBe(true)
    expect(wrapper.find('[data-testid="day-toggle"]').exists()).toBe(false) // nothing to open or close
    wrapper.unmount()
  })

  it('switches to another weekday immediately when it is tapped, and shows only that day', async () => {
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))

    for (const [i, date] of WEEK.entries()) {
      await strip(wrapper, date).trigger('click') // no waiting: nothing is fetched

      expect(shown(wrapper)).toEqual([date])
      expect(wrapper.get('[data-testid="day-heading"]').text()).toBe(`Session ${i + 1}`)
      expect(strip(wrapper, date).attributes('aria-pressed')).toBe('true')
      expect(wrapper.findAll('[data-testid="week-strip"] [aria-pressed="true"]')).toHaveLength(1)
    }

    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('shows the planned and recorded exercises of just the selected day', async () => {
    const monday = makePlan({ id: 9, planned_date: WEEK[0], title: 'Chest', status: 'completed', exercises: [makeExercise({ status: 'completed', performance: { recorded_as: 'entered', sets: [{ set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true }] } })] })
    const wrapper = await mountWeek([monday, plan(WEEK[3], 'Lower')])

    await strip(wrapper, WEEK[0]).trigger('click')

    const card = wrapper.get('[data-testid="day-card"]')
    expect(card.attributes('data-date')).toBe(WEEK[0])
    expect(card.get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 40 kg')
    expect(card.get('[data-testid="exercise-recorded"]').text()).toBe('10×40 kg')
    expect(wrapper.text()).not.toContain('Lower')
    wrapper.unmount()
  })

  it('an unplanned workout appears on its own day when that day is selected', async () => {
    const wrapper = await mountWeek([], { [WEEK[1]]: [makeActivity({ logged_date: WEEK[1] })] })

    await strip(wrapper, WEEK[1]).trigger('click')

    const card = wrapper.get('[data-testid="day-card"]')
    expect(card.get('[data-testid="day-heading"]').text()).toBe('Soccer')
    expect(card.get('[data-testid="day-state"]').text()).toBe('Unplanned activity')
    wrapper.unmount()
  })

  it('an empty day is empty, never rest', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')])
    const empty = WEEK.find((d) => d !== WEEK[1])!

    await strip(wrapper, empty).trigger('click')

    expect(wrapper.get('[data-testid="day-heading"]').text()).toMatch(/^(Nothing planned|No plan or activity recorded)$/)
    expect(wrapper.text()).not.toContain('Rest day')
    wrapper.unmount()
  })

  it('"Show full week" shows all seven days, Monday first, and "Show selected day only" goes back', async () => {
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))
    const toggle = wrapper.get('[data-testid="toggle-full-week"]')

    expect(toggle.text()).toBe('Show full week')
    expect(toggle.attributes('aria-pressed')).toBe('false')

    await toggle.trigger('click')

    expect(shown(wrapper)).toEqual(WEEK)
    expect(wrapper.findAll('[data-testid="day-heading"]').map((h) => h.text())).toEqual(Array.from({ length: 7 }, (_, i) => `Session ${i + 1}`))
    expect(toggle.text()).toBe('Show selected day only')
    expect(toggle.attributes('aria-pressed')).toBe('true')

    await toggle.trigger('click')

    expect(shown(wrapper)).toEqual([TODAY])
    wrapper.unmount()
  })

  it('in the full week, the selected day is open, the rest on tap, and tapping a day in the strip opens and selects it', async () => {
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))
    Element.prototype.scrollIntoView = vi.fn()
    const target = WEEK.find((d) => d !== TODAY)!
    const isOpen = (date: string) => wrapper.get(`[data-testid="day-card"][data-date="${date}"] [data-testid="day-toggle"]`).attributes('aria-expanded')

    await showFullWeek(wrapper)
    expect(isOpen(TODAY)).toBe('true')
    expect(isOpen(target)).toBe('false')

    await wrapper.get(`[data-testid="day-card"][data-date="${target}"] [data-testid="day-toggle"]`).trigger('click')
    expect(isOpen(target)).toBe('true')
    expect(isOpen(TODAY)).toBe('true')

    const another = WEEK.find((d) => d !== TODAY && d !== target)!
    await strip(wrapper, another).trigger('click')
    await flushPromises()
    expect(isOpen(another)).toBe('true')
    expect(strip(wrapper, another).attributes('aria-pressed')).toBe('true')
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('going back to the single day shows the day that was last selected, even after browsing the full week', async () => {
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))
    const picked = WEEK.find((d) => d !== TODAY)!

    await showFullWeek(wrapper)
    await strip(wrapper, picked).trigger('click')
    await showFullWeek(wrapper)

    expect(shown(wrapper)).toEqual([picked])
    wrapper.unmount()
  })

  it('keeps the selected day when the week is read again, and keeps the view the user chose', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue({ applied: true, explanation: null, decision_summary: 'x', reasoning_factors: [] })
    const wrapper = await mountWeek(WEEK.map((date, i) => plan(date, `Session ${i + 1}`)))
    const picked = WEEK.find((d) => d !== TODAY)!
    await strip(wrapper, picked).trigger('click')

    // A change to the week re-reads it (with new content for the day).
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overviewOf(WEEK.map((date, i) => plan(date, `Updated ${i + 1}`))))
    await wrapper.get('textarea').setValue('I have soccer')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(shown(wrapper)).toEqual([picked])
    expect(wrapper.get('[data-testid="day-heading"]').text()).toMatch(/^Updated /)
    expect(vi.mocked(weeklyPlanService.fetchOverview).mock.calls.length).toBeGreaterThanOrEqual(2)

    await showFullWeek(wrapper)
    await wrapper.get('textarea').setValue('again')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(shown(wrapper)).toEqual(WEEK)
    wrapper.unmount()
  })

  it('keeps the selected day when the days that come back differ but it is still among them, and leaves it when it is not', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue({ applied: true, explanation: null, decision_summary: 'x', reasoning_factors: [] })
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))
    const picked = WEEK.find((d) => d !== TODAY && d !== WEEK[6])!
    await strip(wrapper, picked).trigger('click')

    // The same week with its last day missing: the picked day is still there.
    const shorter = overviewOf([])
    shorter.days = shorter.days.slice(0, 6)
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(shorter)
    await wrapper.get('textarea').setValue('x')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(shown(wrapper)).toEqual([picked])

    // The picked day is gone: back to today.
    const without = overviewOf([])
    without.days = without.days.filter((d) => d.date !== picked)
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(without)
    await wrapper.get('textarea').setValue('y')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(shown(wrapper)).toEqual([TODAY])
    wrapper.unmount()
  })

  it('starts again from today when the week it was showing is no longer the week', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue({ applied: true, explanation: null, decision_summary: 'x', reasoning_factors: [] })
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))
    const picked = WEEK.find((d) => d !== TODAY)!
    await strip(wrapper, picked).trigger('click')

    // Next week: none of the old dates exist any more.
    const later = overviewOf([])
    const shift = (date: string) => new Date(new Date(`${date}T00:00:00Z`).getTime() + 7 * 86_400_000).toISOString().slice(0, 10)
    later.week = { start: shift(WEEK[0]), end: shift(WEEK[6]), today: shift(WEEK[3]), timezone: 'UTC' }
    later.days = later.days.map((d) => ({ ...d, date: shift(d.date), is_today: shift(d.date) === later.week.today, is_past: shift(d.date) < later.week.today }))
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(later)
    await wrapper.get('textarea').setValue('x')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(shown(wrapper)).toEqual([shift(WEEK[3])])
    wrapper.unmount()
  })

  it('does not ask the server for anything when days are switched (the week came in one response)', async () => {
    const wrapper = await mountWeek(WEEK.map((date) => plan(date, 'Session')))

    for (const date of WEEK) await strip(wrapper, date).trigger('click')
    await showFullWeek(wrapper)
    await showFullWeek(wrapper)

    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(1)
    expect(weeklyPlanService.generateWeek).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('keeps the progression section below the day, in either view', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')], {}, makeItem())

    for (let pass = 0; pass < 2; pass++) {
      const html = wrapper.html()
      expect(html.indexOf('data-testid="day-card"')).toBeGreaterThan(-1)
      expect(html.lastIndexOf('data-testid="day-card"')).toBeLessThan(html.indexOf('id="progression-heading"'))
      await showFullWeek(wrapper)
    }

    wrapper.unmount()
  })

  it('has suggestions point at the day they apply to, which shows when it is selected', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')], {}, makeItem({ applies_to: { date: WEEK[1], title: 'Upper', plan_status: 'planned' } }))

    expect(wrapper.find('[data-testid="day-suggestions"]').exists()).toBe(WEEK[1] === TODAY)

    await strip(wrapper, WEEK[1]).trigger('click')

    expect(wrapper.get('[data-testid="day-suggestions"]').text()).toBe('1 suggested adjustment to review below')
    wrapper.unmount()
  })
})

describe('steps in the selected day', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  const pick = async (wrapper: ReturnType<typeof mount>, date: string) => wrapper.get(`[data-testid="week-strip"] [data-date="${date}"]`).trigger('click')

  async function mountSteps(overview: ReturnType<typeof overviewOf>) {
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overview)
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())

    const wrapper = mount(WeeklyPlanView, { attachTo: document.body })
    await flushPromises()

    return wrapper
  }

  it('shows the production week: steps on Monday and Tuesday, the treadmill on Wednesday, nothing invented elsewhere', async () => {
    const treadmill = makeActivity({ id: 7, type: 'treadmill', logged_date: WEEK[2], duration_minutes: 27, distance_km: 3.4, speed_kmh: 7.6, metadata: {} })
    const wrapper = await mountSteps(overviewOf([], { [WEEK[2]]: [treadmill] }, { [WEEK[0]]: [makeSteps({ id: 1, logged_date: WEEK[0], steps: 2000 })], [WEEK[1]]: [makeSteps({ id: 2, logged_date: WEEK[1], steps: 6000 })] }))

    await pick(wrapper, WEEK[0])
    expect(wrapper.get('[data-testid="steps-count"]').text()).toBe('2,000 steps')
    expect(wrapper.get('[data-testid="steps-date"]').text()).toBe(`Logged for ${formatDate(WEEK[0])}`)
    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('No workout planned or logged')
    expect(wrapper.text()).not.toContain('No plan or activity recorded')

    await pick(wrapper, WEEK[1])
    expect(wrapper.get('[data-testid="steps-count"]').text()).toBe('6,000 steps')

    await pick(wrapper, WEEK[2])
    expect(wrapper.find('[data-testid="steps"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="activity"]').text()).toContain('3.4')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe('Unplanned activity')

    await pick(wrapper, WEEK[3])
    expect(wrapper.find('[data-testid="steps"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('lists steps beside a planned workout without changing what the workout shows', async () => {
    const wrapper = await mountSteps(overviewOf([plan(WEEK[0], 'Upper')], {}, { [WEEK[0]]: [makeSteps({ steps: 12000, logged_date: WEEK[0] })] }))

    await pick(wrapper, WEEK[0])

    expect(wrapper.get('[data-testid="day-heading"]').text()).toBe('Upper')
    expect(wrapper.get('[data-testid="day-state"]').text()).toBe(WEEK[0] < TODAY ? 'Not marked done' : 'Planned')
    expect(wrapper.get('[data-testid="steps-count"]').text()).toBe('12,000 steps')
    expect(wrapper.get('[data-testid="steps"]').text()).toContain("don't count as a workout or complete a planned session")
    wrapper.unmount()
  })

  it('shows several entries one by one, with a note that they are not added together, and no total', async () => {
    const wrapper = await mountSteps(overviewOf([], {}, { [WEEK[1]]: [makeSteps({ id: 1, steps: 4000, logged_date: WEEK[1] }), makeSteps({ id: 2, steps: 2500, logged_date: WEEK[1] })] }))

    await pick(wrapper, WEEK[1])

    expect(wrapper.findAll('[data-testid="steps-count"]').map((e) => e.text())).toEqual(['4,000 steps', '2,500 steps'])
    expect(wrapper.get('[data-testid="steps-note"]').text()).toContain('not added together')
    expect(wrapper.text()).not.toContain('6,500')
    expect(wrapper.get('[data-testid="day-steps-summary"]').text()).toBe('2 step entries')
    wrapper.unmount()
  })

  it('shows an entry without a count as exactly that, not as zero', async () => {
    const wrapper = await mountSteps(overviewOf([], {}, { [WEEK[1]]: [makeSteps({ steps: null, logged_date: WEEK[1] })] }))

    await pick(wrapper, WEEK[1])

    expect(wrapper.get('[data-testid="steps-count"]').text()).toBe('Steps logged (count not recorded)')
    expect(wrapper.text()).not.toMatch(/\b0 steps\b/)
    wrapper.unmount()
  })

  it('says nothing about steps on a day that has none', async () => {
    const wrapper = await mountSteps(overviewOf([plan(WEEK[1], 'Upper')]))

    await pick(wrapper, WEEK[1])

    expect(wrapper.find('[data-testid="steps"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="day-steps-summary"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the steps summary in the full week and marks the strip', async () => {
    const wrapper = await mountSteps(overviewOf([], {}, { [WEEK[0]]: [makeSteps({ steps: 2000, logged_date: WEEK[0] })] }))

    await showFullWeek(wrapper)

    expect(wrapper.get(`[data-testid="day-card"][data-date="${WEEK[0]}"] [data-testid="day-steps-summary"]`).text()).toBe('2,000 steps')
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${WEEK[0]}"]`).attributes('aria-label')).toContain('steps logged')
    expect(wrapper.find(`[data-testid="week-strip"] [data-date="${WEEK[0]}"] [data-testid="steps-dot"]`).exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('on a phone and for assistive technology', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('has a seven-column strip of tall buttons, a full-width tap target for the toggle, and one labelled live region for the day', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')])

    expect(wrapper.get('[data-testid="week-strip"]').classes()).toContain('grid-cols-7')
    expect(wrapper.findAll('[data-testid="week-strip"] button').every((b) => b.classes().includes('min-h-14'))).toBe(true)
    expect(wrapper.get('[data-testid="toggle-full-week"]').classes()).toContain('min-h-11')

    const panel = wrapper.get('[data-testid="selected-day"]')
    expect(panel.attributes('role')).toBe('region')
    expect(panel.attributes('aria-live')).toBe('polite')
    expect(panel.attributes('aria-label')).toMatch(/^Details for /)
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${TODAY}"]`).attributes('aria-controls')).toBe(panel.attributes('id'))
    wrapper.unmount()
  })

  it('leaves out the live-region link in the full week, where every day is on screen', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')])

    await showFullWeek(wrapper)

    expect(wrapper.find('[data-testid="selected-day"]').exists()).toBe(false)
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${TODAY}"]`).attributes('aria-controls')).toBeUndefined()
    wrapper.unmount()
  })

  it('never fixes a width in pixels on the new controls, so nothing can push a narrow screen sideways', async () => {
    const wrapper = await mountWeek([plan(WEEK[1], 'Upper')], {}, makeItem())
    const html = wrapper.html()

    expect(html).not.toMatch(/\b(?:min-w|w)-\[\d+px\]/)
    wrapper.unmount()
  })
})

describe('the week from the server decides what today is', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('selects the server\'s today, not the browser clock', async () => {
    const overview = overviewOf([])
    overview.week.today = WEEK[5]
    overview.days = overview.days.map((d) => ({ ...d, is_today: d.date === WEEK[5], is_past: d.date < WEEK[5] }))
    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(overview)
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())

    const wrapper = mount(WeeklyPlanView)
    await flushPromises()

    expect(wrapper.findAll('[data-testid="day-card"]').map((c) => c.attributes('data-date'))).toEqual([WEEK[5]])
    expect(wrapper.find('[data-testid="today-chip"]').exists()).toBe(true)
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${WEEK[5]}"]`).attributes('aria-pressed')).toBe('true')
    expect(wrapper.get(`[data-testid="week-strip"] [data-date="${WEEK[5]}"]`).attributes('aria-current')).toBe('date')
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

