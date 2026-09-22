import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as weeklyPlanService from '@/services/weeklyPlan'
import { useWeeklyPlanStore } from '@/stores/weeklyPlan'
import type { WeeklyAdaptationResult, WorkoutPlan } from '@/types'
import { getCurrentWeekRange } from '@/utils/week'

vi.mock('@/services/weeklyPlan', () => ({
  fetchWeek: vi.fn(),
  generateWeek: vi.fn(),
  submitAdaptation: vi.fn(),
}))

function makePlan(overrides: Partial<WorkoutPlan> = {}): WorkoutPlan {
  return {
    id: 1,
    planned_date: getCurrentWeekRange(null).dates[2],
    activity_type: 'strength',
    title: 'Upper Body',
    source: 'ai',
    status: 'planned',
    duration_minutes: 45,
    reasoning: null,
    reasoning_factors: [],
    training_schedule_id: null,
    notes: null,
    exercises: [],
    updated_at: null,
    ...overrides,
  }
}

function makeResult(overrides: Partial<WeeklyAdaptationResult> = {}): WeeklyAdaptationResult {
  return {
    applied: true,
    explanation: 'Swapped Wednesday for soccer.',
    decision_summary: 'Swapped Wednesday for soccer.',
    reasoning_factors: ['User has soccer Wednesday.'],
    changes: [getCurrentWeekRange(null).dates[2]],
    ...overrides,
  }
}

describe('weeklyPlan store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('builds a 7-day shape with gaps as null', async () => {
    const plan = makePlan()
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan])

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    expect(store.days).toHaveLength(7)
    expect(store.days.filter((d) => d.plan === null)).toHaveLength(6)
    expect(store.days.find((d) => d.date === plan.planned_date)?.plan).toEqual(plan)
  })

  it('shows the existing week first, then asks the backend to fill gaps and refetches only if it generated something', async () => {
    const plan = makePlan()
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: true, explanation: null })
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan])

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    expect(weeklyPlanService.generateWeek).toHaveBeenCalledTimes(1)
    expect(weeklyPlanService.fetchWeek).toHaveBeenCalledTimes(2)
    expect(store.days.find((d) => d.date === plan.planned_date)?.plan).toEqual(plan)
    expect(store.generating).toBe(false)
  })

  it('does not refetch when the backend had nothing to generate', async () => {
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([makePlan()])

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    expect(weeklyPlanService.fetchWeek).toHaveBeenCalledTimes(1)
  })

  it('keeps the existing week visible and reports the error when gap-filling fails (e.g. provider outage)', async () => {
    const plan = makePlan()
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan])
    vi.mocked(weeklyPlanService.generateWeek).mockRejectedValue({
      response: { data: { message: 'The AI coach is temporarily unavailable — please try again in a moment.' } },
    })

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    expect(store.days).toHaveLength(7)
    expect(store.days.find((d) => d.date === plan.planned_date)?.plan).toEqual(plan)
    expect(store.error).toBe('The AI coach is temporarily unavailable — please try again in a moment.')
    expect(store.loading).toBe(false)
    expect(store.generating).toBe(false)
  })

  it('shows the plan immediately while generation is still running', async () => {
    let resolveGenerate: (v: WeeklyAdaptationResult) => void = () => {}
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([makePlan()])
    vi.mocked(weeklyPlanService.generateWeek).mockReturnValue(new Promise((resolve) => { resolveGenerate = resolve }))

    const store = useWeeklyPlanStore()
    const pending = store.loadWeek()
    await vi.waitFor(() => expect(store.generating).toBe(true))

    expect(store.loading).toBe(false)
    expect(store.days.some((d) => d.plan !== null)).toBe(true)

    resolveGenerate({ applied: false, explanation: null } as WeeklyAdaptationResult)
    await pending
    expect(store.generating).toBe(false)
  })

  it('sets loading true during loadWeek and false after, on both success and failure', async () => {
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([])
    vi.mocked(weeklyPlanService.generateWeek).mockResolvedValue({ applied: false, explanation: null })

    const store = useWeeklyPlanStore()
    const promise = store.loadWeek()
    expect(store.loading).toBe(true)
    await promise
    expect(store.loading).toBe(false)
  })

  it('sets an error and leaves days empty when the initial week load fails', async () => {
    vi.mocked(weeklyPlanService.fetchWeek).mockRejectedValue(new Error('network error'))

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    expect(store.days).toHaveLength(0)
    expect(store.loading).toBe(false)
    expect(store.error).not.toBeNull()
  })

  it('gates duplicate submits via the sending flag', async () => {
    let resolveSubmit: (value: WeeklyAdaptationResult) => void = () => {}
    vi.mocked(weeklyPlanService.submitAdaptation).mockReturnValue(
      new Promise((resolve) => {
        resolveSubmit = resolve
      }),
    )

    const store = useWeeklyPlanStore()
    const first = store.submitAdaptation('I have soccer Wednesday.')
    const second = store.submitAdaptation('I have soccer Wednesday.')

    resolveSubmit(makeResult())
    await Promise.all([first, second])

    expect(weeklyPlanService.submitAdaptation).toHaveBeenCalledTimes(1)
  })

  it('refreshes the week after a successful adaptation that applied changes', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue(makeResult({ applied: true }))
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([makePlan()])

    const store = useWeeklyPlanStore()
    await store.submitAdaptation('I have soccer Wednesday.')

    expect(weeklyPlanService.fetchWeek).toHaveBeenCalledTimes(1)
    expect(store.lastResult?.applied).toBe(true)
  })

  it('does not refresh the week when the adaptation reports no material change', async () => {
    vi.mocked(weeklyPlanService.submitAdaptation).mockResolvedValue(
      makeResult({ applied: false, explanation: "That doesn't affect your training." }),
    )

    const store = useWeeklyPlanStore()
    await store.submitAdaptation('What is the weather like?')

    expect(weeklyPlanService.fetchWeek).not.toHaveBeenCalled()
    expect(store.lastResult?.explanation).toBe("That doesn't affect your training.")
  })

  it('sets an error and preserves the previous days on submit failure', async () => {
    const plan = makePlan()
    vi.mocked(weeklyPlanService.fetchWeek).mockResolvedValue([plan])

    const store = useWeeklyPlanStore()
    await store.loadWeek()

    vi.mocked(weeklyPlanService.submitAdaptation).mockRejectedValue(new Error('network error'))
    await store.submitAdaptation('I have soccer Wednesday.')

    expect(store.error).not.toBeNull()
    expect(store.days.find((d) => d.date === plan.planned_date)?.plan).toEqual(plan)
  })
})
