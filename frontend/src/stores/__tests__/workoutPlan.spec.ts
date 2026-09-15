import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as workoutPlanService from '@/services/workoutPlans'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { WorkoutPlan } from '@/types'

vi.mock('@/services/workoutPlans', () => ({
  fetchPlanForDate: vi.fn(),
  updateExerciseStatus: vi.fn(),
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
})
