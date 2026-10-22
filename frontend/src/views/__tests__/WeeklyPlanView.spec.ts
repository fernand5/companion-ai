import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { makeItem, makeList } from '@/components/__tests__/progressionFixtures'
import * as progressionService from '@/services/progression'
import * as weeklyPlanService from '@/services/weeklyPlan'
import { getCurrentWeekRange } from '@/utils/week'
import WeeklyPlanView from '@/views/WeeklyPlanView.vue'

vi.mock('@/services/weeklyPlan', () => ({ fetchWeek: vi.fn(), generateWeek: vi.fn(), submitAdaptation: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))

const week = getCurrentWeekRange(undefined).dates

function plan(date: string, title: string) {
  return {
    id: Number(date.replaceAll('-', '')), planned_date: date, activity_type: 'strength', title, source: 'ai', status: 'planned', duration_minutes: null,
    reasoning: null, reasoning_factors: [], training_schedule_id: null, notes: null, exercises: [], updated_at: null,
  }
}

async function mountView(...items: ReturnType<typeof makeItem>[]) {
  vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan(week[1], 'Upper'), plan(week[3], 'Lower')] as never)
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
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan(week[1], 'Upper')] as never)
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
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan(week[1], 'Upper')] as never)
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
