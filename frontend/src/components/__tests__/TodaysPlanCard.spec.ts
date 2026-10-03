import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import TodaysPlanCard from '@/components/TodaysPlanCard.vue'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { WorkoutPlan } from '@/types'

vi.mock('@/services/exerciseImages', () => ({
  requestExerciseImage: vi.fn(async () => {
    throw new Error('503')
  }),
}))

vi.mock('@/services/workoutPlans', () => ({
  fetchPlanForDate: vi.fn(),
  updateExerciseStatus: vi.fn(async () => plan),
}))

let plan: WorkoutPlan

beforeEach(() => {
  setActivePinia(createPinia())
  plan = {
    id: 1,
    planned_date: '2026-09-15',
    activity_type: 'strength',
    title: 'Upper Body',
    source: 'ai',
    status: 'planned',
    duration_minutes: 45,
    reasoning: 'Keeping legs fresh.',
    reasoning_factors: ['Football tomorrow'],
    training_schedule_id: null,
    notes: null,
    exercises: [
      {
        id: 10,
        exercise_name: 'Bench Press',
        planned_sets: 3,
        planned_reps: 10,
        planned_weight_kg: null,
        planned_duration_seconds: null,
        position: 0,
        status: 'pending',
        actual_sets: null,
        actual_reps: null,
        actual_weight_kg: null,
        actual_duration_seconds: null,
        completed_at: null,
        notes: null,
        image_url: null,
        image_status: null,
      },
    ],
    updated_at: null,
  }
})

describe('TodaysPlanCard', () => {
  it('renders nothing when there is no plan loaded', () => {
    const wrapper = mount(TodaysPlanCard)

    expect(wrapper.text()).toBe('')
  })

  it('renders the plan title and each exercise row', () => {
    const store = useWorkoutPlanStore()
    store.today = plan

    const wrapper = mount(TodaysPlanCard)

    expect(wrapper.text()).toContain('Upper Body')
    expect(wrapper.text()).toContain('Bench Press')
    expect(wrapper.text()).toContain('3 × 10')
    expect(wrapper.text()).toContain('Football tomorrow')
  })

  it('clicking an exercise row calls the store to mark it completed', async () => {
    const store = useWorkoutPlanStore()
    store.today = plan
    const spy = vi.spyOn(store, 'updateExerciseStatus')

    const wrapper = mount(TodaysPlanCard)
    await wrapper.find('button').trigger('click')

    expect(spy).toHaveBeenCalledWith(10, 'completed')
  })

  it('keeps the status toggle as the first control, ahead of the demo control', () => {
    const store = useWorkoutPlanStore()
    store.today = plan

    const wrapper = mount(TodaysPlanCard)
    const buttons = wrapper.findAll('button')

    expect(buttons[0].text()).toContain('Bench Press')
    expect(wrapper.text()).toContain('Show demo')
  })

  it('never blocks completing an exercise when the demo image fails', async () => {
    const store = useWorkoutPlanStore()
    store.today = plan
    const spy = vi.spyOn(store, 'updateExerciseStatus')
    const wrapper = mount(TodaysPlanCard)

    const demoButton = wrapper.findAll('button').find((b) => b.text().includes('Show demo'))!
    await demoButton.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Demo unavailable right now.')

    await wrapper.find('button').trigger('click')

    expect(spy).toHaveBeenCalledWith(10, 'completed')
    expect(store.error).toBeNull()
  })
})

