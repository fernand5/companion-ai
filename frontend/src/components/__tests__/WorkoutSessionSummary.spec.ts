import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import WorkoutSessionSummary from '@/components/WorkoutSessionSummary.vue'
import type { WorkoutExercise, WorkoutSession } from '@/types'

function exercise(overrides: Partial<WorkoutExercise>): WorkoutExercise {
  return {
    id: 1,
    exercise_name: 'Bench Press',
    exercise_slug: 'bench-press',
    recorded_as: 'entered',
    planned: null,
    performed_sets: [],
    sets: null,
    reps: null,
    weight_kg: null,
    duration_seconds: null,
    notes: null,
    ...overrides,
  }
}

const session = (exercises: WorkoutExercise[]): WorkoutSession => ({
  id: 1,
  logged_date: '2026-10-05',
  duration_minutes: null,
  notes: null,
  exercises,
})

describe('WorkoutSessionSummary', () => {
  it('lists each exercise with the sets that were actually performed', () => {
    const wrapper = mount(WorkoutSessionSummary, {
      props: {
        session: session([
          exercise({
            performed_sets: [
              { set_number: 1, reps: 8, weight_kg: 45, duration_seconds: null, completed: true },
              { set_number: 2, reps: 8, weight_kg: 45, duration_seconds: null, completed: true },
              { set_number: 3, reps: 7, weight_kg: 45, duration_seconds: null, completed: true },
            ],
          }),
        ]),
      },
    })

    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.text()).toContain('8×45, 8×45, 7×45 kg')
    expect(wrapper.text()).not.toContain('as planned')
  })

  it('labels assumed and migrated records so they are not mistaken for measurements', () => {
    const wrapper = mount(WorkoutSessionSummary, {
      props: {
        session: session([
          exercise({ id: 1, recorded_as: 'as_planned', performed_sets: [{ set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true }] }),
          exercise({ id: 2, exercise_name: 'Row', recorded_as: 'migrated', sets: 3, reps: 10, weight_kg: 30 }),
        ]),
      },
    })

    expect(wrapper.text()).toContain('as planned')
    expect(wrapper.text()).toContain('earlier log')
    expect(wrapper.text()).toContain('3 × 10 @ 30 kg')
  })

  it('shows just the name when nothing numeric was recorded', () => {
    const wrapper = mount(WorkoutSessionSummary, { props: { session: session([exercise({ recorded_as: 'as_planned' })]) } })

    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.text()).not.toContain('—')
  })
})
