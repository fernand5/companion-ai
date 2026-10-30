import type { WeekActivity, WeekDayState, WeekOverview, WeekSteps, WeeklyPlanDay, WorkoutPlan, WorkoutPlanExercise } from '@/types'
import { getCurrentWeekRange, todayIn } from '@/utils/week'

/** The week the fixtures describe: Monday to Sunday around today (UTC, as the specs run). */
export const WEEK = getCurrentWeekRange(null).dates
export const TODAY = todayIn(null)

export function makeExercise(overrides: Partial<WorkoutPlanExercise> = {}): WorkoutPlanExercise {
  return {
    id: 1,
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

export function makePlan(overrides: Partial<WorkoutPlan> = {}): WorkoutPlan {
  return {
    id: 1,
    planned_date: WEEK[2],
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

export function makeActivity(overrides: Partial<WeekActivity> = {}): WeekActivity {
  return {
    id: 1,
    type: 'sport',
    logged_date: WEEK[1],
    duration_minutes: 90,
    distance_km: null,
    speed_kmh: null,
    pace_seconds_per_km: null,
    intensity: 'high',
    notes: null,
    metadata: { sport: 'Soccer' },
    workout_session_id: null,
    created_at: null,
    session: null,
    ...overrides,
  }
}

export function makeSteps(overrides: Partial<WeekSteps> = {}): WeekSteps {
  return { id: 1, logged_date: WEEK[0], steps: 6000, created_at: null, ...overrides }
}

function stateOf(plan: WorkoutPlan | null, activities: WeekActivity[], isPast: boolean): WeekDayState {
  if (!plan) return activities.length > 0 ? 'activity_only' : 'nothing'
  if (plan.status === 'planned') return isPast ? 'not_marked_done' : 'planned'

  return plan.status as WeekDayState
}

export function makeDay(date: string, plan: WorkoutPlan | null = null, activities: WeekActivity[] = [], overrides: Partial<WeeklyPlanDay> = {}): WeeklyPlanDay {
  const isPast = date < TODAY

  return {
    date,
    weekday: new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', { weekday: 'long', timeZone: 'UTC' }),
    is_today: date === TODAY,
    is_past: isPast,
    state: stateOf(plan, activities, isPast),
    plan,
    activities,
    steps: [],
    ...overrides,
  }
}

/** A server-shaped overview: seven days, the given plans placed on their dates, optional activities by date. */
export function overviewOf(
  plans: WorkoutPlan[] = [],
  activitiesByDate: Record<string, WeekActivity[]> = {},
  stepsByDate: Record<string, WeekSteps[]> = {},
): WeekOverview {
  return {
    week: { start: WEEK[0], end: WEEK[6], today: TODAY, timezone: 'UTC' },
    days: WEEK.map((date) =>
      makeDay(date, plans.find((plan) => plan.planned_date === date) ?? null, activitiesByDate[date] ?? [], { steps: stepsByDate[date] ?? [] }),
    ),
  }
}
