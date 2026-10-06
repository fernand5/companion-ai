import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as workoutPlanService from '@/services/workoutPlans'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { WorkoutPlan } from '@/types'

vi.mock('@/services/workoutPlans', () => ({
  fetchPlanForDate: vi.fn(),
  updateExerciseStatus: vi.fn(),
  recordExercisePerformance: vi.fn(),
}))

function makePlan(overrides: Partial<WorkoutPlan> = {}): WorkoutPlan {
  return {
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
        performance: null,
        image_url: null,
        image_status: null,
      },
    ],
    updated_at: null,
    ...overrides,
  }
}

describe('workout plan store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads today\'s plan', async () => {
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())

    const store = useWorkoutPlanStore()
    await store.loadToday()

    expect(store.today?.title).toBe('Upper Body')
  })

  it('optimistically updates exercise status then applies the server response', async () => {
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())
    vi.mocked(workoutPlanService.updateExerciseStatus).mockResolvedValue(
      makePlan({ status: 'completed', exercises: [{ ...makePlan().exercises[0], status: 'completed' }] }),
    )

    const store = useWorkoutPlanStore()
    await store.loadToday()
    await store.updateExerciseStatus(10, 'completed')

    expect(store.today?.exercises[0].status).toBe('completed')
    expect(store.today?.status).toBe('completed')
  })

  it('rolls back the optimistic update and sets an error when the request fails', async () => {
    vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())
    vi.mocked(workoutPlanService.updateExerciseStatus).mockRejectedValue(new Error('network error'))

    const store = useWorkoutPlanStore()
    await store.loadToday()
    await store.updateExerciseStatus(10, 'completed')

    expect(store.today?.exercises[0].status).toBe('pending')
    expect(store.error).not.toBeNull()
  })

  it('sets an error instead of leaving today null and silent when the initial load fails', async () => {
    vi.mocked(workoutPlanService.fetchPlanForDate).mockRejectedValue(new Error('network error'))

    const store = useWorkoutPlanStore()
    await store.loadToday()

    expect(store.today).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.error).not.toBeNull()
  })

  describe('recordPerformance', () => {
    const sets = [
      { reps: 10, weight_kg: 40 },
      { reps: 8, weight_kg: 40 },
    ]

    function planWithPerformance(): WorkoutPlan {
      const plan = makePlan({ status: 'completed' })
      plan.exercises[0].status = 'completed'
      plan.exercises[0].performance = {
        recorded_as: 'entered',
        sets: [
          { set_number: 1, reps: 10, weight_kg: 40, duration_seconds: null, completed: true },
          { set_number: 2, reps: 8, weight_kg: 40, duration_seconds: null, completed: true },
        ],
      }

      return plan
    }

    it('sends the sets for the loaded plan and replaces it with the server response', async () => {
      vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())
      vi.mocked(workoutPlanService.recordExercisePerformance).mockResolvedValue(planWithPerformance())
      const store = useWorkoutPlanStore()
      await store.loadToday()

      const saved = await store.recordPerformance(10, sets)

      expect(saved).toBe(true)
      expect(workoutPlanService.recordExercisePerformance).toHaveBeenCalledWith(1, 10, { sets })
      expect(store.today?.exercises[0].performance?.sets).toHaveLength(2)
      expect(store.today?.exercises[0].status).toBe('completed')
      expect(store.savingPerformanceFor).toBeNull()
      expect(store.performanceError).toBeNull()
    })

    it('marks the exercise as saving while the request is in flight', async () => {
      vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())
      let resolve: (plan: WorkoutPlan) => void = () => {}
      vi.mocked(workoutPlanService.recordExercisePerformance).mockReturnValue(new Promise((r) => (resolve = r)))
      const store = useWorkoutPlanStore()
      await store.loadToday()

      const pending = store.recordPerformance(10, sets)
      expect(store.savingPerformanceFor).toBe(10)

      resolve(planWithPerformance())
      await pending
      expect(store.savingPerformanceFor).toBeNull()
    })

    it('ignores a second save while one is in flight', async () => {
      vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(makePlan())
      let resolve: (plan: WorkoutPlan) => void = () => {}
      vi.mocked(workoutPlanService.recordExercisePerformance).mockReturnValue(new Promise((r) => (resolve = r)))
      const store = useWorkoutPlanStore()
      await store.loadToday()

      const first = store.recordPerformance(10, sets)
      const second = await store.recordPerformance(10, sets)
      resolve(planWithPerformance())
      await first

      expect(second).toBe(false)
      expect(workoutPlanService.recordExercisePerformance).toHaveBeenCalledTimes(1)
    })

    it('keeps the plan, reports the server message and resolves false on a rejected save', async () => {
      const plan = makePlan()
      vi.mocked(workoutPlanService.fetchPlanForDate).mockResolvedValue(plan)
      vi.mocked(workoutPlanService.recordExercisePerformance).mockRejectedValue({
        response: { data: { message: 'Record at least one completed set, or skip the exercise instead.' } },
      })
      const store = useWorkoutPlanStore()
      await store.loadToday()

      const saved = await store.recordPerformance(10, sets)

      expect(saved).toBe(false)
      expect(store.today).toEqual(plan)
      expect(store.performanceError).toBe('Record at least one completed set, or skip the exercise instead.')
      expect(store.error).toBeNull()
      expect(store.savingPerformanceFor).toBeNull()
    })

    it('does nothing without a loaded plan', async () => {
      const store = useWorkoutPlanStore()

      expect(await store.recordPerformance(10, sets)).toBe(false)
      expect(workoutPlanService.recordExercisePerformance).not.toHaveBeenCalled()
    })
  })
})
