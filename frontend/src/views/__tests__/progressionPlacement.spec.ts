import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

import { makeItem, makeList } from '@/components/__tests__/progressionFixtures'
import { TODAY, makeExercise, makePlan, overviewOf } from '@/components/__tests__/weekFixtures'
import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import * as progressionService from '@/services/progression'
import * as weeklyPlanService from '@/services/weeklyPlan'
import ProgressView from '@/views/ProgressView.vue'
import WeeklyPlanView from '@/views/WeeklyPlanView.vue'

vi.mock('@/services/weeklyPlan', () => ({ fetchOverview: vi.fn(), generateWeek: vi.fn(), submitAdaptation: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))
vi.mock('@/services/dashboard', () => ({ fetchProgress: vi.fn(), fetchDashboard: vi.fn() }))
vi.mock('@/services/adherence', () => ({ fetchAdherence: vi.fn() }))
vi.mock('@/services/coach', () => ({ fetchWeeklySummary: vi.fn() }))
vi.mock('vue-chartjs', () => ({ Bar: { template: '<canvas />' }, Line: { template: '<canvas />' } }))

describe('where progression lives', () => {
  it('is mounted by the Progress tab only: no other view or component renders the recommendations', () => {
    const sources = import.meta.glob('/src/**/*.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
    const mounting = Object.entries(sources)
      .filter(([, source]) => /<ProgressionRecommendations\b/.test(source))
      .map(([path]) => path)

    expect(mounting).toEqual(['/src/views/ProgressView.vue'])
  })
})

describe('approving a load on the Progress tab, then going back to the week', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('shows the load the server now holds, because the week is read again on arrival', async () => {
    const withLoad = (kg: number) => overviewOf([makePlan({ id: 3, planned_date: TODAY, title: 'Lower Body', exercises: [makeExercise({ planned_weight_kg: kg })] })])
    const item = makeItem({ applies_to: { date: TODAY, title: 'Lower Body', plan_status: 'planned' } })

    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(withLoad(40))
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(item))
    vi.mocked(dashboardService.fetchProgress).mockRejectedValue(new Error('not needed here'))
    vi.mocked(adherenceService.fetchAdherence).mockRejectedValue(new Error('not needed here'))
    vi.mocked(coachService.fetchWeeklySummary).mockRejectedValue(new Error('not needed here'))

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/weekly-plan', name: 'weekly-plan', component: WeeklyPlanView },
        { path: '/progress', name: 'progress', component: ProgressView },
      ],
    })
    await router.push('/weekly-plan')
    const wrapper = mount({ components: { RouterView }, template: '<RouterView />' }, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    expect(wrapper.get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 40 kg')

    // From the marker on the day to the recommendation.
    await wrapper.get('[data-testid="show-suggestions"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/progress#progression-heading')

    // The approval is made there; the server then holds 42.5 kg and has nothing more to suggest.
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: TODAY, from_kg: 40, to_kg: 42.5 })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList())
    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()
    expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')

    vi.mocked(weeklyPlanService.fetchOverview).mockResolvedValue(withLoad(42.5))
    await router.push('/weekly-plan')
    await flushPromises()

    expect(wrapper.get('[data-testid="exercise-planned"]').text()).toBe('3×10 @ 42.5 kg')
    expect(wrapper.find('[data-testid="day-suggestions"]').exists()).toBe(false)
    expect(weeklyPlanService.fetchOverview).toHaveBeenCalledTimes(2) // once on each arrival at the week
    wrapper.unmount()
  })
})
