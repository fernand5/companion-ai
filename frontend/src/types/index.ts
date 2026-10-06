export interface User {
  id: number
  name: string
  email: string
  timezone: string | null
}

export interface FitnessProfile {
  id: number
  height_cm: number | null
  weight_kg: number | null
  age: number | null
  sex: string | null
  fitness_level: string | null
  primary_goal: string | null
  secondary_goal: string | null
  equipment: string[]
  preferred_training_days: number[]
  preferred_training_duration_minutes: number | null
  updated_at: string | null
}

export interface TrainingSchedule {
  id: number
  activity_type: string
  day_of_week: number
  start_time: string
  expected_duration_minutes: number
  intensity: string | null
  notes: string | null
}

export type ActivityType = 'steps' | 'treadmill' | 'strength' | 'sport' | 'recovery' | 'weight'

export interface ActivityLog {
  id: number
  type: ActivityType
  logged_date: string
  duration_minutes: number | null
  distance_km: number | null
  /** Derived by the server from distance and duration; never entered. */
  speed_kmh: number | null
  pace_seconds_per_km: number | null
  intensity: string | null
  notes: string | null
  metadata: Record<string, unknown> | null
  workout_session_id: number | null
  created_at: string | null
}

/**
 * How much to trust recorded numbers: `entered` was typed by the user,
 * `as_planned` is the plan's target assumed after a one-tap "done" (weak
 * evidence, not measured performance), `migrated` was converted from older logs.
 */
export type PerformanceRecordedAs = 'entered' | 'as_planned' | 'migrated'

export interface PerformedSet {
  set_number: number
  reps: number | null
  weight_kg: number | null
  duration_seconds: number | null
  completed: boolean
}

export interface PlannedTarget {
  sets: number | null
  reps: number | null
  weight_kg: number | null
  duration_seconds: number | null
}

/** What the user actually did for a planned exercise — kept apart from the plan's targets. */
export interface ExercisePerformance {
  recorded_as: PerformanceRecordedAs
  sets: PerformedSet[]
}

export interface WorkoutExercise {
  id: number
  exercise_name: string
  exercise_slug: string | null
  recorded_as: PerformanceRecordedAs
  planned: PlannedTarget | null
  performed_sets: PerformedSet[]
  sets: number | null
  reps: number | null
  weight_kg: number | null
  duration_seconds: number | null
  notes: string | null
}

export interface WorkoutSession {
  id: number
  logged_date: string
  duration_minutes: number | null
  notes: string | null
  exercises: WorkoutExercise[]
}

export interface Conversation {
  id: number
  title: string | null
  updated_at: string | null
  created_at: string | null
}

export type MessageRole = 'user' | 'assistant' | 'system'

export interface ToolCallTrace {
  name: string
  arguments: Record<string, unknown>
  result: unknown
}

export interface Message {
  id: number
  role: MessageRole
  content: string
  meta: { tool_calls?: ToolCallTrace[] } | null
  created_at: string | null
}

export interface ProgressSummary {
  current_weight_kg: number | null
  starting_weight_kg: number | null
  weight_change_kg: number | null
  workouts_this_week: number
  average_steps: number
}

export interface DashboardData {
  profile: FitnessProfile | null
  today_activity: ActivityLog[]
  upcoming_schedule: Array<{
    date: string
    activity_type: string
    start_time: string
    intensity: string | null
  }>
  progress: ProgressSummary
  coach_recommendation: string | null
  coach_recommendation_unavailable_reason: 'not_configured' | 'rate_limited' | 'unavailable' | null
}

export interface ProgressData {
  summary: ProgressSummary
  weight_series: Array<{ date: string; weight_kg: number }>
  steps_series: Array<{ date: string; steps: number }>
  weekly_workouts: Array<{ week_start: string; workouts: number }>
}

export type PlanExerciseStatus = 'pending' | 'completed' | 'partial' | 'skipped'

export type WorkoutPlanStatus = 'planned' | 'in_progress' | 'completed' | 'partial' | 'skipped'

export type WorkoutPlanSource = 'ai' | 'manual' | 'schedule'

export type ExerciseImageStatus = 'pending' | 'generating' | 'ready' | 'failed'

export interface WorkoutPlanExercise {
  id: number
  exercise_name: string
  planned_sets: number | null
  planned_reps: number | null
  planned_weight_kg: number | null
  planned_duration_seconds: number | null
  position: number
  status: PlanExerciseStatus
  actual_sets: number | null
  actual_reps: number | null
  actual_weight_kg: number | null
  actual_duration_seconds: number | null
  completed_at: string | null
  notes: string | null
  /** What the user actually did (separate from the planned_* targets above); null until recorded. */
  performance: ExercisePerformance | null
  /** Global demonstration image; only set once it has been generated. */
  image_url: string | null
  image_status: ExerciseImageStatus | null
}

export interface WorkoutPlan {
  id: number
  planned_date: string
  activity_type: string
  title: string
  source: WorkoutPlanSource
  status: WorkoutPlanStatus
  duration_minutes: number | null
  reasoning: string | null
  reasoning_factors: string[]
  training_schedule_id: number | null
  notes: string | null
  exercises: WorkoutPlanExercise[]
  updated_at: string | null
}

export interface RecoveryCheckin {
  id: number
  checkin_date: string
  energy: number
  soreness: number
  motivation: number
  perceived_difficulty: number | null
  pain_notes: string | null
}

export interface AdherencePoint {
  week_start: string
  week_end: string
  adherence_pct: number | null
}

export interface AdherenceSummary {
  start_date: string
  end_date: string
  adherence_pct: number | null
  due_exercise_count: number
  planned_workouts_completed: number
  planned_workouts_partial: number
  planned_workouts_skipped: number
  active_days: number
}

export interface AdherenceData {
  series: AdherencePoint[]
  summary: AdherenceSummary
}

export interface WeeklyPlanDay {
  date: string
  plan: WorkoutPlan | null
}

export interface WeeklyAdaptationResult {
  applied: boolean
  explanation: string | null
  decision_summary?: string
  reasoning_factors?: string[]
  changes?: string[]
  skipped?: Array<{ date: string; reason: string }>
  cleared?: string[]
  error?: string
}

export interface WeeklySummary {
  week_start: string
  stats: AdherenceSummary
  coach_insight: string
  next_week_focus: string
  generated_at: string
}
