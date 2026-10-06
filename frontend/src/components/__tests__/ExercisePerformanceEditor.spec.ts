import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import ExercisePerformanceEditor from '@/components/ExercisePerformanceEditor.vue'
import * as workoutPlanService from '@/services/workoutPlans'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { WorkoutPlan, WorkoutPlanExercise } from '@/types'

vi.mock('@/services/workoutPlans', () => ({
  fetchPlanForDate: vi.fn(),
  updateExerciseStatus: vi.fn(),
  recordExercisePerformance: vi.fn(),
}))

function makeExercise(overrides: Partial<WorkoutPlanExercise> = {}): WorkoutPlanExercise {
  return {
    id: 10,
    exercise_name: 'Bench Press',
    planned_sets: 3,
    planned_reps: 10,
    planned_weight_kg: 40,
    planned_duration_seconds: null,
    position: 0,
    status: 'pending',
    actual_sets: null,
    actual_reps: null,
    actual_weight_kg: null,
    actual_duration_seconds: null,
    completed_at: null,
    notes: null,
    performance: null,
    image_url: null,
    image_status: null,
    ...overrides,
  }
}

function makePlan(exercise: WorkoutPlanExercise): WorkoutPlan {
  return {
    id: 1,
    planned_date: '2026-10-05',
    activity_type: 'strength',
    title: 'Upper Body',
    source: 'manual',
    status: 'planned',
    duration_minutes: null,
    reasoning: null,
    reasoning_factors: [],
    training_schedule_id: null,
    notes: null,
    exercises: [exercise],
    updated_at: null,
  }
}

function mountEditor(exercise: WorkoutPlanExercise) {
  const store = useWorkoutPlanStore()
  store.today = makePlan(exercise)

  return { store, wrapper: mount(ExercisePerformanceEditor, { props: { exercise } }) }
}

const reps = (wrapper: ReturnType<typeof mountEditor>['wrapper'], set: number) =>
  wrapper.find<HTMLInputElement>(`input[aria-label="Set ${set} reps"]`)
const weight = (wrapper: ReturnType<typeof mountEditor>['wrapper'], set: number) =>
  wrapper.find<HTMLInputElement>(`input[aria-label="Set ${set} weight in kilograms"]`)
const saveButton = (wrapper: ReturnType<typeof mountEditor>['wrapper']) =>
  wrapper.findAll('button').find((b) => b.text().includes('Save sets'))!

describe('ExercisePerformanceEditor', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('starts from the plan target, one row per planned set', () => {
    const { wrapper } = mountEditor(makeExercise())

    expect(wrapper.text()).toContain('Target 3 × 10 @ 40 kg')
    expect(wrapper.findAll('input[type="number"][aria-label$="reps"]')).toHaveLength(3)
    expect(reps(wrapper, 2).element.value).toBe('10')
    expect(weight(wrapper, 3).element.value).toBe('40')
  })

  it('shows a single blank row when the plan has no target', () => {
    const { wrapper } = mountEditor(
      makeExercise({ planned_sets: null, planned_reps: null, planned_weight_kg: null }),
    )

    expect(wrapper.findAll('input[aria-label$="reps"]')).toHaveLength(1)
    expect(reps(wrapper, 1).element.value).toBe('')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('shows a duration column for time-based exercises', () => {
    const { wrapper } = mountEditor(
      makeExercise({ exercise_name: 'Plank', planned_reps: null, planned_weight_kg: null, planned_duration_seconds: 60 }),
    )

    expect(wrapper.find('input[aria-label="Set 1 duration in seconds"]').exists()).toBe(true)
  })

  it('starts over from the target when the previous record was only assumed', () => {
    const { wrapper } = mountEditor(
      makeExercise({
        performance: {
          recorded_as: 'as_planned',
          sets: [{ set_number: 1, reps: 99, weight_kg: 99, duration_seconds: null, completed: true }],
        },
      }),
    )

    expect(reps(wrapper, 1).element.value).toBe('10')
  })

  it('edits what the user previously entered', () => {
    const { wrapper } = mountEditor(
      makeExercise({
        performance: {
          recorded_as: 'entered',
          sets: [
            { set_number: 1, reps: 10, weight_kg: 42.5, duration_seconds: null, completed: true },
            { set_number: 2, reps: 8, weight_kg: 42.5, duration_seconds: null, completed: true },
          ],
        },
      }),
    )

    expect(wrapper.findAll('input[aria-label$="reps"]')).toHaveLength(2)
    expect(reps(wrapper, 2).element.value).toBe('8')
    expect(weight(wrapper, 1).element.value).toBe('42.5')
  })

  it('records exactly what was typed, set by set', async () => {
    vi.mocked(workoutPlanService.recordExercisePerformance).mockResolvedValue(makePlan(makeExercise()))
    const { store, wrapper } = mountEditor(makeExercise())
    store.today = makePlan(makeExercise())

    await reps(wrapper, 3).setValue('8')
    await weight(wrapper, 1).setValue('42.5')
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(workoutPlanService.recordExercisePerformance).toHaveBeenCalledWith(1, 10, {
      sets: [
        { reps: 10, weight_kg: 42.5, duration_seconds: null, completed: true },
        { reps: 10, weight_kg: 40, duration_seconds: null, completed: true },
        { reps: 8, weight_kg: 40, duration_seconds: null, completed: true },
      ],
    })
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('can add and remove sets', async () => {
    const { wrapper } = mountEditor(makeExercise())

    const add = wrapper.findAll('button').find((b) => b.text().includes('Add set'))!
    await add.trigger('click')
    expect(wrapper.findAll('input[aria-label$="reps"]')).toHaveLength(4)
    expect(reps(wrapper, 4).element.value).toBe('10')

    await wrapper.find('button[aria-label="Remove set 1"]').trigger('click')
    expect(wrapper.findAll('input[aria-label$="reps"]')).toHaveLength(3)
  })

  it('records a set the user did not complete as not completed', async () => {
    vi.mocked(workoutPlanService.recordExercisePerformance).mockResolvedValue(makePlan(makeExercise()))
    const { store, wrapper } = mountEditor(makeExercise())
    store.today = makePlan(makeExercise())

    await wrapper.find('input[aria-label="Set 3 completed"]').setValue(false)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    const sent = vi.mocked(workoutPlanService.recordExercisePerformance).mock.calls[0][2].sets
    expect(sent?.[2].completed).toBe(false)
    expect(sent?.[0].completed).toBe(true)
  })

  it('cannot be saved with an empty set or with nothing completed', async () => {
    const { wrapper } = mountEditor(makeExercise({ planned_sets: 1 }))
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    await reps(wrapper, 1).setValue('')
    await weight(wrapper, 1).setValue('')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

    await reps(wrapper, 1).setValue('5')
    await wrapper.find('input[aria-label="Set 1 completed"]').setValue(false)
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('stays open and shows the server message when saving fails', async () => {
    vi.mocked(workoutPlanService.recordExercisePerformance).mockRejectedValue({
      response: { data: { message: 'Something was wrong with those sets.' } },
    })
    const { wrapper } = mountEditor(makeExercise())

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Something was wrong with those sets.')
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('closes without saving on cancel', async () => {
    const { wrapper } = mountEditor(makeExercise())

    await wrapper.findAll('button').find((b) => b.text() === 'Cancel')!.trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(workoutPlanService.recordExercisePerformance).not.toHaveBeenCalled()
  })
})
