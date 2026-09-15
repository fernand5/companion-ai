import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import WeeklySummaryCard from '@/components/WeeklySummaryCard.vue'
import type { WeeklySummary } from '@/types'

function makeSummary(): WeeklySummary {
  return {
    week_start: '2026-09-08',
    stats: {
      start_date: '2026-09-08',
      end_date: '2026-09-14',
      adherence_pct: 82,
      due_exercise_count: 5,
      planned_workouts_completed: 4,
      planned_workouts_partial: 0,
      planned_workouts_skipped: 1,
      active_days: 3,
    },
    coach_insight: 'Your consistency improved this week.',
    next_week_focus: 'Keep workouts under 45 minutes.',
    generated_at: '2026-09-15T09:00:00Z',
  }
}

describe('WeeklySummaryCard', () => {
  it('shows a loading state', () => {
    const wrapper = mount(WeeklySummaryCard, { props: { summary: null, loading: true, error: null } })

    expect(wrapper.text()).toContain('Loading')
  })

  it('shows an error state', () => {
    const wrapper = mount(WeeklySummaryCard, {
      props: { summary: null, loading: false, error: 'Rate limited.' },
    })

    expect(wrapper.text()).toContain('Rate limited.')
  })

  it('renders the summary stats and coach text', () => {
    const wrapper = mount(WeeklySummaryCard, {
      props: { summary: makeSummary(), loading: false, error: null },
    })

    expect(wrapper.text()).toContain('82%')
    expect(wrapper.text()).toContain('Your consistency improved this week.')
    expect(wrapper.text()).toContain('Keep workouts under 45 minutes.')
  })
})
