import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ProgressionCard from '@/components/ProgressionCard.vue'
import type { ProgressionActionState } from '@/stores/progression'

import { dismissedItem, insufficient, makeItem, manualReview } from './progressionFixtures'

const idle: ProgressionActionState = { status: 'idle', message: null }

function card(item = makeItem(), state: ProgressionActionState = idle) {
  return mount(ProgressionCard, { props: { item, state } })
}

describe('ProgressionCard', () => {
  it('shows the exercise, both targets, the decision and the grounding for it', () => {
    const wrapper = card()

    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.get('[data-testid="decision"]').text()).toBe('Increase')
    expect(wrapper.get('[data-testid="current"]').text()).toBe('3×10 @ 40 kg')
    expect(wrapper.get('[data-testid="proposed"]').text()).toContain('3×10 @ 42.5 kg')
    expect(wrapper.get('[data-testid="proposed"]').text()).toContain('+6.3%')
    expect(wrapper.get('[data-testid="reasons"]').text()).toContain('reached in each of the last 3 sessions')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('10,10,10 @ 40 kg')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('target missed')
    expect(wrapper.get('[data-testid="sessions"]').text()).toContain('2 of 3 sets')
    expect(wrapper.get('[data-testid="evidence"]').text()).toBe('Strong evidence')
    expect(wrapper.get('[data-testid="caveats"]').text()).toContain('Effort (RPE)')
    expect(wrapper.get('[data-testid="applies-to"]').text()).toContain('next session')
    expect(wrapper.get('[data-testid="applies-to"]').text()).toContain('only')
  })

  it('offers Accept and Keep for an actionable recommendation, and says nothing changes until accepted', async () => {
    const wrapper = card()

    expect(wrapper.get('[data-testid="accept"]').text()).toBe('Accept recommendation')
    expect(wrapper.get('[data-testid="keep"]').text()).toBe('Keep current target')
    expect(wrapper.text()).toContain('Nothing changes until you accept.')

    await wrapper.get('[data-testid="accept"]').trigger('click')
    await wrapper.get('[data-testid="keep"]').trigger('click')

    expect(wrapper.emitted('accept')).toHaveLength(1)
    expect(wrapper.emitted('keep')).toHaveLength(1)
  })

  it('shows a decrease with its own label and no approval for anything that is not a change', () => {
    const decrease = card(makeItem({ decision: 'DECREASE', proposed_target: { sets: 3, reps: 8, weight_kg: 57.5, duration_seconds: null, change_percent: -4.2, step_kg: 2.5, from: 60, to: 57.5 } }))
    expect(decrease.get('[data-testid="decision"]').text()).toBe('Decrease')
    expect(decrease.get('[data-testid="proposed"]').text()).toContain('-4.2%')
    expect(decrease.find('[data-testid="accept"]').exists()).toBe(true)

    const maintain = card(makeItem({ decision: 'MAINTAIN', proposed_target: null, actionable: false, proposal: null, not_actionable_reason: 'not_a_change' }))
    expect(maintain.get('[data-testid="decision"]').text()).toBe('Keep as is')
    expect(maintain.find('[data-testid="proposed"]').exists()).toBe(false)
    expect(maintain.find('[data-testid="actions"]').exists()).toBe(false)
  })

  it.each([
    ['insufficient data', insufficient(), 'Not enough data'],
    ['manual review', manualReview(), 'Needs your review'],
  ])('has no approval action for %s, and says no change will be made', (_name, item, label) => {
    const wrapper = card(item)

    expect(wrapper.get('[data-testid="decision"]').text()).toBe(label)
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="keep"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="no-change"]').text()).toContain('No change will be made')
    expect(wrapper.find('[data-testid="proposed"]').exists()).toBe(false)
  })

  it('keeps the limitations visible for a manual review', () => {
    const wrapper = card(manualReview())

    expect(wrapper.get('[data-testid="caveats"]').text()).toContain('pain')
    expect(wrapper.get('[data-testid="evidence"]').text()).toBe('Limited evidence')
  })

  it('never offers an action the server did not issue a proposal for, whatever the flags say', () => {
    const wrapper = card(makeItem({ actionable: true, proposal: null }))

    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
  })

  it('explains why an increase cannot be applied instead of offering to', () => {
    const wrapper = card(makeItem({ actionable: false, proposal: null, not_actionable_reason: 'no_upcoming_session', applies_to: null }))

    expect(wrapper.get('[data-testid="unavailable"]').text()).toContain('no upcoming session')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
  })

  it('disables both actions and shows progress while a request is in flight', () => {
    const wrapper = card(makeItem(), { status: 'working', message: null })

    expect(wrapper.get('[data-testid="accept"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="keep"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="accept"]').find('svg').exists()).toBe(true)
  })

  it('shows success after an approval and removes the actions', () => {
    const wrapper = card(makeItem(), { status: 'applied', message: 'Bench Press is now 42.5 kg for 2026-10-19.' })

    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('Bench Press is now 42.5 kg')
    expect(wrapper.get('[data-testid="status-note"]').attributes('role')).toBe('status')
    expect(wrapper.find('[data-testid="actions"]').exists()).toBe(false)
  })

  it('confirms a kept target without changing anything else', () => {
    const wrapper = card(makeItem(), { status: 'kept', message: 'Keeping Bench Press as planned.' })

    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('Keeping Bench Press as planned.')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)
  })

  it('shows a recommendation the user declined earlier as kept, without the approval or the pitch', () => {
    const wrapper = card(dismissedItem())

    expect(wrapper.get('[data-testid="decision"]').text()).toBe('Kept current target')
    expect(wrapper.get('[data-testid="current"]').text()).toBe('3×10 @ 40 kg')
    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('A new recommendation appears if you log new sets or change your plan.')
    for (const id of ['accept', 'keep', 'proposed', 'reasons', 'sessions', 'actions']) {
      expect(wrapper.find(`[data-testid="${id}"]`).exists(), id).toBe(false)
    }
  })

  it('says which later sessions with the exercise are NOT being changed', () => {
    const wrapper = card(makeItem({ later_sessions: [{ date: '2026-10-22', title: 'Lower body' }] }))

    expect(wrapper.get('[data-testid="later-sessions"]').text()).toContain('stay as they are')
    expect(wrapper.get('[data-testid="later-sessions"]').text()).toContain('reviewed after this one')
    expect(card(makeItem()).find('[data-testid="later-sessions"]').exists()).toBe(false)
  })

  it('handles a stale proposal: says so, hides the old action and offers a fresh recommendation', async () => {
    const wrapper = card(makeItem(), { status: 'stale', message: 'Your plan or recent history changed since this recommendation was made.' })

    expect(wrapper.get('[data-testid="status-note"]').attributes('role')).toBe('alert')
    expect(wrapper.get('[data-testid="status-note"]').text()).toContain('changed since this recommendation')
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false)

    await wrapper.get('[data-testid="refresh"]').trigger('click')
    expect(wrapper.emitted('refresh')).toHaveLength(1)
  })

  it('shows an error and lets the user try again', () => {
    const wrapper = card(makeItem(), { status: 'failed', message: "Couldn't apply that recommendation — please try again." })

    expect(wrapper.get('[data-testid="status-note"]').text()).toContain("Couldn't apply")
    expect(wrapper.find('[data-testid="accept"]').exists()).toBe(true)
  })

  it('is laid out for a phone: stacked full-width tap targets that sit side by side from sm up', () => {
    const wrapper = card()
    const actions = wrapper.get('[data-testid="actions"]')

    expect(actions.classes()).toEqual(expect.arrayContaining(['flex', 'flex-col', 'sm:flex-row']))
    for (const id of ['accept', 'keep']) {
      expect(wrapper.get(`[data-testid="${id}"]`).classes()).toEqual(expect.arrayContaining(['w-full', 'sm:w-auto', 'min-h-11']))
    }
    expect(wrapper.get('h4').classes()).toContain('break-words')
  })
})
