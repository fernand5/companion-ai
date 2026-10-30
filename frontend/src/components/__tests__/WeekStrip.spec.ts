import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import WeekStrip from '@/components/WeekStrip.vue'

import { TODAY, WEEK, makeActivity, makeDay, makePlan, makeSteps } from './weekFixtures'

const days = () => WEEK.map((date, i) => makeDay(date, i === 1 ? makePlan({ planned_date: date, status: 'completed' }) : null, i === 4 ? [makeActivity({ logged_date: date })] : []))

describe('WeekStrip', () => {
  it('shows seven days, Monday first, in a seven-column grid that fits a narrow phone', () => {
    const wrapper = mount(WeekStrip, { props: { days: days(), selected: null } })

    expect(wrapper.findAll('button')).toHaveLength(7)
    expect(wrapper.findAll('button').map((b) => b.attributes('data-date'))).toEqual(WEEK)
    expect(wrapper.get('ul').classes()).toContain('grid-cols-7')
    expect(wrapper.findAll('button').every((b) => b.classes().includes('min-h-14'))).toBe(true)
    expect(wrapper.findAll('li').every((li) => li.classes().includes('min-w-0'))).toBe(true)
    expect(wrapper.text()).toMatch(/Mon/)
  })

  it('describes each day for a screen reader: the date, whether it is today and what it holds', () => {
    const wrapper = mount(WeekStrip, { props: { days: days(), selected: null } })
    const labels = wrapper.findAll('button').map((b) => b.attributes('aria-label'))

    expect(labels[1]).toContain('Completed')
    expect(labels[4]).toContain('Unplanned activity')
    expect(labels[0]).toContain('Nothing planned')
    expect(labels.find((l) => l?.includes('today'))).toBeDefined()
    expect(wrapper.find(`[data-date="${TODAY}"]`).attributes('aria-current')).toBe('date')
  })

  it('says which day is selected and emits the date when one is tapped', async () => {
    const wrapper = mount(WeekStrip, { props: { days: days(), selected: WEEK[2] } })

    expect(wrapper.get(`[data-date="${WEEK[2]}"]`).attributes('aria-pressed')).toBe('true')
    expect(wrapper.get(`[data-date="${WEEK[3]}"]`).attributes('aria-pressed')).toBe('false')

    await wrapper.get(`[data-date="${WEEK[5]}"]`).trigger('click')
    expect(wrapper.emitted('select')).toEqual([[WEEK[5]]])
  })

  it('marks each kind of day differently without relying on colour alone', () => {
    const wrapper = mount(WeekStrip, { props: { days: days(), selected: null } })

    const states = wrapper.findAll('button').map((b) => b.attributes('data-state'))
    expect(states[1]).toBe('completed')
    expect(states[4]).toBe('activity_only')
    expect(states[0]).toBe('nothing')
    expect(wrapper.findAll('button').every((b) => b.attributes('aria-label')?.includes(':'))).toBe(true)
  })

  it('says which days have steps logged, as text and with a dot that is not the only cue', () => {
    const withSteps = WEEK.map((date, i) => makeDay(date, null, [], { steps: i === 0 ? [makeSteps({ logged_date: date })] : [] }))
    const wrapper = mount(WeekStrip, { props: { days: withSteps, selected: null } })

    expect(wrapper.get(`[data-date="${WEEK[0]}"]`).attributes('aria-label')).toContain('steps logged')
    expect(wrapper.get(`[data-date="${WEEK[1]}"]`).attributes('aria-label')).not.toContain('steps')
    expect(wrapper.findAll('[data-testid="steps-dot"]')).toHaveLength(1)
    expect(wrapper.get(`[data-date="${WEEK[0]}"]`).attributes('data-state')).toBe('nothing') // steps do not change what the day is
  })

  it('points at the panel it controls only when it is given one', () => {
    const without = mount(WeekStrip, { props: { days: days(), selected: WEEK[0] } })
    const withPanel = mount(WeekStrip, { props: { days: days(), selected: WEEK[0], controls: 'selected-day' } })

    expect(without.get('button').attributes('aria-controls')).toBeUndefined()
    expect(withPanel.findAll('button').every((b) => b.attributes('aria-controls') === 'selected-day')).toBe(true)
  })
})
