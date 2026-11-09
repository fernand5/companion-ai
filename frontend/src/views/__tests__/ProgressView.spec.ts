import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { dismissedItem, insufficient, makeItem, makeList, manualReview } from '@/components/__tests__/progressionFixtures'
import * as adherenceService from '@/services/adherence'
import * as coachService from '@/services/coach'
import * as dashboardService from '@/services/dashboard'
import * as progressionService from '@/services/progression'
import ProgressView from '@/views/ProgressView.vue'

vi.mock('@/services/dashboard', () => ({ fetchProgress: vi.fn(), fetchDashboard: vi.fn() }))
vi.mock('@/services/adherence', () => ({ fetchAdherence: vi.fn() }))
vi.mock('@/services/coach', () => ({ fetchWeeklySummary: vi.fn() }))
vi.mock('@/services/progression', () => ({ fetchRecommendations: vi.fn(), approveProposal: vi.fn(), keepCurrentTarget: vi.fn() }))
vi.mock('vue-chartjs', () => ({ Bar: { template: '<canvas data-testid="chart" />' }, Line: { template: '<canvas data-testid="chart" />' } }))

const PROGRESS = {
  summary: { current_weight_kg: 80, starting_weight_kg: 85, weight_change_kg: -5, workouts_this_week: 3, average_steps: 6000 },
  weight_series: [{ date: '2026-10-20', weight_kg: 80 }],
  steps_series: [{ date: '2026-10-26', steps: 2000 }],
  weekly_workouts: [{ week_start: '2026-10-26', workouts: 3 }],
}

const ADHERENCE = {
  series: [{ week_start: '2026-10-19', week_end: '2026-10-25', adherence_pct: 80 }],
  summary: { start_date: '2026-10-19', end_date: '2026-10-25', adherence_pct: 80, due_exercise_count: 5, planned_workouts_completed: 4, planned_workouts_partial: 0, planned_workouts_skipped: 1, active_days: 4 },
}

async function mountProgress(items: ReturnType<typeof makeItem>[] = [makeItem()]) {
  vi.mocked(dashboardService.fetchProgress).mockResolvedValue(PROGRESS as never)
  vi.mocked(adherenceService.fetchAdherence).mockResolvedValue(ADHERENCE as never)
  vi.mocked(coachService.fetchWeeklySummary).mockRejectedValue(new Error('unavailable'))
  vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(...items))

  const wrapper = mount(ProgressView, { attachTo: document.body })
  await flushPromises()

  return wrapper
}

describe('the Progress tab: progression recommendations', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  it('shows the current recommendations, with the load change, the reason and the sessions behind it', async () => {
    const wrapper = await mountProgress()

    expect(wrapper.find('#progression-heading').exists()).toBe(true)
    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.text()).toContain('42.5')
    expect(wrapper.text()).toContain('The target was reached in each of the last 3 sessions at the same load.')
    expect(wrapper.text()).toContain('10,10,10 @ 40 kg')
    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('lets the user accept exactly the proposal the server issued, and says what was applied', async () => {
    const wrapper = await mountProgress()
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 })
    // After the approval the plan holds 42.5 kg, so the server now says this exercise is where it should be.
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(
      makeList(makeItem({ decision: 'MAINTAIN', proposed_target: null, actionable: false, proposal: null, not_actionable_reason: 'not_a_change', state: 'no_change' })),
    )

    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()

    expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')
    expect(wrapper.text()).toContain('Bench Press is now 42.5 kg for 2026-10-19.')
    wrapper.unmount()
  })

  it('keeps the current target without changing anything, and reloads what the server says', async () => {
    const wrapper = await mountProgress()
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true } as never)
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(dismissedItem()))

    await wrapper.get('[data-testid="keep"]').trigger('click')
    await flushPromises()

    expect(progressionService.keepCurrentTarget).toHaveBeenCalledWith('sealed-proposal-1')
    expect(progressionService.approveProposal).not.toHaveBeenCalled()
    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('treats a proposal the server no longer accepts as stale and asks for a fresh one, never retrying the old one', async () => {
    const wrapper = await mountProgress()
    vi.mocked(progressionService.approveProposal).mockRejectedValue({ response: { status: 409, data: { message: 'Your plan changed since this was calculated.' } } })

    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Your plan changed since this was calculated.')
    expect(progressionService.approveProposal).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('explains why there is no recommendation when the data is not enough, or a person should look first', async () => {
    const wrapper = await mountProgress([insufficient(), manualReview()])

    const noAction = wrapper.get('[data-testid="no-action"]')
    expect(noAction.text()).toContain('2 exercises need no action')
    expect(noAction.text()).toContain('Fewer than 3 measured sessions of this exercise.')
    expect(noAction.text()).toContain('A recovery check-in in the last 14 days mentions pain.')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not offer a recommendation the user already kept, and says it is kept', async () => {
    const wrapper = await mountProgress([dismissedItem()])

    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="no-action"]').text()).toContain('1 exercise needs no action')
    wrapper.unmount()
  })

  it('puts the recommendations first and keeps every chart and statistic that was already there', async () => {
    const wrapper = await mountProgress()
    const html = wrapper.html()

    const order = ['id="progression-heading"', 'data-testid="trends-heading"', 'Adherence', 'Weight', 'Steps', 'Workouts per week'].map((marker) => html.indexOf(marker))
    expect(order.every((i) => i >= 0)).toBe(true)
    expect(order).toEqual([...order].sort((a, b) => a - b))

    expect(wrapper.text()).toContain('Trends and history')
    expect(wrapper.text()).toContain('Avg steps')
    expect(wrapper.findAll('[data-testid="chart"]')).toHaveLength(4)
    wrapper.unmount()
  })

  it('still shows the recommendations when the charts cannot load, and the charts when the recommendations cannot', async () => {
    vi.mocked(dashboardService.fetchProgress).mockRejectedValue(new Error('network'))
    vi.mocked(adherenceService.fetchAdherence).mockResolvedValue(ADHERENCE as never)
    vi.mocked(coachService.fetchWeeklySummary).mockRejectedValue(new Error('unavailable'))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(makeList(makeItem()))

    const broken = mount(ProgressView, { attachTo: document.body })
    await flushPromises()
    expect(broken.text()).toContain('Bench Press')
    broken.unmount()

    setActivePinia(createPinia())
    vi.mocked(dashboardService.fetchProgress).mockResolvedValue(PROGRESS as never)
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValue(new Error('network'))

    const other = mount(ProgressView, { attachTo: document.body })
    await flushPromises()
    expect(other.find('[data-testid="error"]').exists()).toBe(true)
    expect(other.text()).toContain('Workouts per week')
    other.unmount()
  })

  it('refreshes on request', async () => {
    const wrapper = await mountProgress()

    await wrapper.get('[data-testid="reload"]').trigger('click')
    await flushPromises()

    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('has no fixed pixel widths in the new section, and tall tap targets, so it fits 320 to 744 px', async () => {
    const wrapper = await mountProgress()
    const section = wrapper.get('[aria-labelledby="progression-heading"]')

    expect(section.html()).not.toMatch(/\bw-\[\d+px\]|\bmin-w-\[\d+px\]|style="[^"]*width:\s*\d+px/)
    expect(wrapper.get('[data-testid="accept"]').classes().join(' ')).toMatch(/min-h-11/)
    expect(wrapper.get('[data-testid="reload"]').classes().join(' ')).toMatch(/min-h-11/)
    wrapper.unmount()
  })
})
