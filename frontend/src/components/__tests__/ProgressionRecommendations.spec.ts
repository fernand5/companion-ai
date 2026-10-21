import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import ProgressionRecommendations from '@/components/ProgressionRecommendations.vue'
import * as progressionService from '@/services/progression'

import { dismissedItem, insufficient, makeItem, makeList, manualReview } from './progressionFixtures'

vi.mock('@/services/progression', () => ({
  fetchRecommendations: vi.fn(),
  approveProposal: vi.fn(),
  keepCurrentTarget: vi.fn(),
}))

const list = makeList

async function mountWith(...items: ReturnType<typeof makeItem>[]) {
  vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(...items))
  const wrapper = mount(ProgressionRecommendations)
  await flushPromises()

  return wrapper
}

describe('ProgressionRecommendations', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('shows a loading state, then the recommendations', async () => {
    let resolve!: (value: ReturnType<typeof list>) => void
    vi.mocked(progressionService.fetchRecommendations).mockReturnValue(new Promise((r) => (resolve = r)))

    const wrapper = mount(ProgressionRecommendations)
    await flushPromises()
    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(true)

    resolve(list(makeItem()))
    await flushPromises()
    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Bench Press')
  })

  it('shows an error with a retry that loads again', async () => {
    vi.mocked(progressionService.fetchRecommendations).mockRejectedValueOnce({ response: { data: { message: 'Server down' } } })
    const wrapper = mount(ProgressionRecommendations)
    await flushPromises()

    expect(wrapper.get('[data-testid="error"]').text()).toContain('Server down')

    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem()))
    await wrapper.get('[data-testid="error"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="error"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Bench Press')
  })

  it('shows an empty state when there is nothing yet', async () => {
    const wrapper = await mountWith()

    expect(wrapper.get('[data-testid="empty"]').text()).toContain('Record the sets you do')
  })

  it('puts the recommendations you can act on first and gives the others no actions', async () => {
    const wrapper = await mountWith(insufficient(), manualReview(), makeItem())

    const names = wrapper.findAll('h4').map((h) => h.text())
    expect(names).toEqual(['Bench Press', 'Overhead Press', 'Pull-up'])
    expect(wrapper.findAll('[data-testid="accept"]')).toHaveLength(1)
  })

  it('accepting sends only the server-issued proposal, reloads, and shows the result', async () => {
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 })
    const wrapper = await mountWith(makeItem())

    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem({ decision: 'MAINTAIN', proposed_target: null, actionable: false, proposal: null, not_actionable_reason: 'not_a_change' })))
    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()

    expect(progressionService.approveProposal).toHaveBeenCalledTimes(1)
    expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')
    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('Bench Press is now 42.5 kg for 2026-10-19.')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
  })

  it('a stale proposal shows the server message and a fresh recommendation replaces it', async () => {
    vi.mocked(progressionService.approveProposal).mockRejectedValue({ response: { status: 409, data: { code: 'stale', message: 'Your plan changed. Refresh to get a fresh one.' } } })
    const wrapper = await mountWith(makeItem())

    await wrapper.get('[data-testid="accept"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('Your plan changed')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)

    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem({ proposal: { id: 'sealed-proposal-2', expires_at: '2026-10-17T13:00:00+00:00' } })))
    await wrapper.get('[data-testid="refresh"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="status-note"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(true)
  })

  it('keeping the current target is remembered: the card shows it and offers no approval again', async () => {
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true })
    const wrapper = await mountWith(makeItem())

    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(dismissedItem()))
    await wrapper.get('[data-testid="keep"]').trigger('click')
    await flushPromises()

    expect(progressionService.keepCurrentTarget).toHaveBeenCalledWith('sealed-proposal-1')
    expect(progressionService.approveProposal).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="decision"]').text()).toBe('Kept current target')
    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('You kept your current target')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="keep"]').exists()).toBe(false)
  })

  it('after a page refresh a previously kept recommendation is still shown as kept, not offered again', async () => {
    const wrapper = await mountWith(dismissedItem())

    expect(wrapper.get('[data-testid="kept-card"]').text()).toContain('Bench Press')
    expect(wrapper.get('[data-testid="decision"]').text()).toBe('Kept current target')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="proposed"]').exists()).toBe(false)
  })

  it('a fresh recommendation after new sets is offered again', async () => {
    const wrapper = await mountWith(dismissedItem())
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)

    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem({ proposal: { id: 'sealed-proposal-2', expires_at: '2026-10-17T13:00:00+00:00' } })))
    await wrapper.get('[data-testid="reload"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kept-card"]').exists()).toBe(false)
  })

  it('lists kept recommendations after the ones you can act on', async () => {
    const wrapper = await mountWith(dismissedItem(), makeItem({ exercise: { name: 'Squat', slug: 'squat' } }))

    expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Squat', 'Bench Press'])
  })

  it('groups the cards by the upcoming session they apply to, soonest first, with the unscheduled last', async () => {
    const thursday = makeItem({ exercise: { name: 'Squat', slug: 'squat' }, applies_to: { date: '2026-10-22', title: 'Lower body', plan_status: 'planned' } })
    const wrapper = await mountWith(insufficient(), thursday, makeItem())

    const headings = wrapper.findAll('[data-testid="session-heading"]').map((h) => h.text())
    expect(headings).toHaveLength(3)
    expect(headings[0]).toContain('Upper body')
    expect(headings[1]).toContain('Lower body')
    expect(headings[2]).toBe('Not in an upcoming session')
    expect(wrapper.find('[data-session="2026-10-19"]').text()).toContain('Bench Press')
    expect(wrapper.find('[data-session="2026-10-22"]').text()).toContain('Squat')
    expect(wrapper.find('[data-session="unscheduled"]').text()).toContain('Pull-up')
  })

  it('summarises how many adjustments are suggested and says accepting one never accepts another', async () => {
    const wrapper = await mountWith(makeItem(), makeItem({ exercise: { name: 'Deadlift', slug: 'deadlift' } }))

    expect(wrapper.get('[data-testid="summary"]').text()).toContain('2 suggested adjustments')
    expect(wrapper.get('[data-testid="summary"]').text()).toContain('accepting one never accepts another')
  })

  it('says the plan stays as it is when nothing is suggested', async () => {
    const wrapper = await mountWith(insufficient(), manualReview())

    expect(wrapper.get('[data-testid="summary"]').text()).toContain('No adjustments suggested right now. Your plan stays as it is.')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
  })

  it('accepting one exercise sends only that exercise\'s proposal', async () => {
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 })
    const wrapper = await mountWith(makeItem(), makeItem({ exercise: { name: 'Deadlift', slug: 'deadlift' }, proposal: { id: 'sealed-proposal-deadlift', expires_at: 'x' } }))

    await wrapper.findAll('[data-testid="accept"]')[0].trigger('click')
    await flushPromises()

    expect(progressionService.approveProposal).toHaveBeenCalledTimes(1)
    expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')
  })

  it('refreshing reloads the list', async () => {
    const wrapper = await mountWith(makeItem())

    await wrapper.get('[data-testid="reload"]').trigger('click')
    await flushPromises()

    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
  })

  it('the refresh control is a full-height tap target', async () => {
    const wrapper = await mountWith(makeItem())

    expect(wrapper.get('[data-testid="reload"]').classes()).toContain('min-h-11')
  })
})
