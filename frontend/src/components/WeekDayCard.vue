<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'

import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'
import WorkoutSessionSummary from '@/components/WorkoutSessionSummary.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import type { ActivityType, WeekActivity, WeeklyPlanDay, WorkoutPlanExercise } from '@/types'
import { activityIcon, activityLabel, activitySummary, formatDate, formatPerformedSets, formatTarget } from '@/utils/format'
import { DAY_STATE } from '@/utils/weekDay'

const props = defineProps<{
  day: WeeklyPlanDay
  expanded: boolean
  /** How many calculated adjustments wait for approval on this day (shown in the Progression section). */
  suggestions: number
}>()

const emit = defineEmits<{ toggle: []; 'show-suggestions': [] }>()

const state = computed(() => DAY_STATE[props.day.state])
const plan = computed(() => props.day.plan)
const bodyId = computed(() => `day-body-${props.day.date}`)

/** What the day is called when there is no plan to name it. */
const heading = computed(() => {
  if (plan.value) return plan.value.title
  if (props.day.state === 'activity_only') return activityName(props.day.activities[0])
  if (props.day.state === 'rest') return 'Rest day'

  return props.day.is_past ? 'No plan or activity recorded' : 'Nothing planned'
})

const headingType = computed<ActivityType | null>(() => {
  if (plan.value) return plan.value.activity_type as ActivityType
  if (props.day.activities[0]) return props.day.activities[0].type

  return null
})

/** Soccer logged as sport/"Soccer" reads as "Soccer", not "Sport". */
function activityName(activity: WeekActivity | undefined): string {
  if (!activity) return ''

  const sport = activity.type === 'sport' ? String((activity.metadata ?? {}).sport ?? '') : ''

  return sport || activityLabel(activity.type)
}

function activityDetail(activity: WeekActivity): string {
  // Cardio reads best as distance, time and speed; anything else as how long it lasted.
  if (activity.type === 'treadmill') return activitySummary(activity)

  return activity.duration_minutes ? `${activity.duration_minutes} min` : ''
}

function target(exercise: WorkoutPlanExercise): string {
  return formatTarget({
    sets: exercise.planned_sets,
    reps: exercise.planned_reps,
    weight_kg: exercise.planned_weight_kg,
    duration_seconds: exercise.planned_duration_seconds,
  })
}

/**
 * What is actually stored for the exercise. A one-tap "done" is a completion acknowledgment, not measured
 * performance, and an exercise nobody touched is "not recorded": never shown as done or as missed.
 */
function recorded(exercise: WorkoutPlanExercise): { text: string; tone: 'measured' | 'weak' | 'none' } {
  const sets = exercise.performance?.sets ?? []

  if (sets.length > 0 && exercise.performance?.recorded_as !== 'as_planned') {
    const earlier = exercise.performance?.recorded_as === 'migrated' ? ' (from an earlier log)' : ''

    return { text: `${formatPerformedSets(sets)}${earlier}`, tone: 'measured' }
  }

  if (exercise.status === 'completed' || exercise.status === 'partial' || sets.length > 0) {
    return { text: `Marked ${exercise.status === 'partial' ? 'partly done' : 'done'}, amounts not recorded`, tone: 'weak' }
  }

  if (exercise.status === 'skipped') return { text: 'Skipped', tone: 'none' }

  return { text: 'Not recorded', tone: 'none' }
}

const explanation = computed(() => {
  switch (props.day.state) {
    case 'not_marked_done':
      return "This session wasn't marked done and no sets were recorded. That doesn't mean it was missed."
    case 'rest':
      return 'A planned rest day.'
    case 'nothing':
      return props.day.is_past ? 'Nothing was planned or logged for this day.' : 'No workout is planned for this day yet.'
    case 'activity_only':
      return 'You logged this without a plan for the day.'
    default:
      return null
  }
})
</script>

<template>
  <article
    :id="`day-${day.date}`"
    class="scroll-mt-4 rounded-xl border"
    :class="day.is_today ? 'border-brand-300 bg-brand-50' : 'border-slate-200 bg-white'"
    :data-date="day.date"
    :data-state="day.state"
    data-testid="day-card"
  >
    <button
      type="button"
      class="flex min-h-14 w-full items-start justify-between gap-2 rounded-xl p-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
      :aria-expanded="expanded"
      :aria-controls="bodyId"
      data-testid="day-toggle"
      @click="emit('toggle')"
    >
      <span class="min-w-0 space-y-0.5">
        <span class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase">
          {{ formatDate(day.date) }}
          <span v-if="day.is_today" class="rounded-full bg-brand-600 px-1.5 py-0.5 text-[10px] font-semibold text-white normal-case" data-testid="today-chip">Today</span>
        </span>
        <span class="flex items-start gap-1.5 font-semibold break-words text-slate-900" :class="plan || day.state === 'activity_only' ? '' : 'text-sm font-normal text-slate-500'">
          <FontAwesomeIcon v-if="headingType" :icon="activityIcon(headingType)" :class="[ICON_SIZE.sm, 'mt-1 shrink-0']" />
          <span class="min-w-0" data-testid="day-heading">{{ heading }}</span>
        </span>
        <span v-if="plan" class="block text-xs text-slate-500" data-testid="day-type">
          {{ activityLabel(plan.activity_type as ActivityType) }}<template v-if="plan.duration_minutes"> · {{ plan.duration_minutes }} min</template>
        </span>
        <span v-if="suggestions > 0" class="block pt-0.5 text-xs font-medium text-brand-700" data-testid="day-suggestions">
          {{ suggestions }} suggested {{ suggestions === 1 ? 'adjustment' : 'adjustments' }} to review below
        </span>
      </span>

      <span class="flex shrink-0 items-center gap-2">
        <span v-if="day.state !== 'nothing'" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="state.badge" data-testid="day-state">
          {{ state.label }}
        </span>
        <FontAwesomeIcon :icon="icons.chevronDown" :class="[ICON_SIZE.xs, 'text-slate-400 transition-transform', expanded ? 'rotate-180' : '']" />
      </span>
    </button>

    <div v-show="expanded" :id="bodyId" class="space-y-3 px-4 pb-4" data-testid="day-body">
      <p v-if="explanation" class="text-sm text-slate-500" data-testid="day-explanation">{{ explanation }}</p>

      <WhyThisChangedCard v-if="plan" :reasoning="plan.reasoning" :reasoning-factors="plan.reasoning_factors" />

      <section v-if="plan && plan.exercises.length > 0" aria-label="Planned exercises">
        <ul class="space-y-2">
          <li v-for="exercise in plan.exercises" :key="exercise.id" class="rounded-lg bg-white/70 p-2 text-sm ring-1 ring-slate-100" data-testid="exercise">
            <p class="font-medium break-words text-slate-900">{{ exercise.exercise_name }}</p>
            <dl class="mt-0.5 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs">
              <dt class="text-slate-400">Planned</dt>
              <dd class="break-words text-slate-700" data-testid="exercise-planned">{{ target(exercise) }}</dd>
              <dt class="text-slate-400">Recorded</dt>
              <dd
                class="break-words"
                :class="recorded(exercise).tone === 'measured' ? 'text-slate-900' : 'text-slate-500'"
                data-testid="exercise-recorded"
              >
                {{ recorded(exercise).text }}
              </dd>
            </dl>
          </li>
        </ul>
      </section>

      <section v-if="day.activities.length > 0" class="space-y-1.5" :aria-label="plan ? 'Also logged' : 'Logged activity'">
        <h3 class="text-xs font-medium tracking-wide text-slate-400 uppercase" data-testid="activities-heading">
          {{ plan ? 'Also logged this day' : 'Logged activity' }}
        </h3>
        <ul class="space-y-1.5">
          <li v-for="activity in day.activities" :key="activity.id" class="rounded-lg bg-white/70 p-2 text-sm ring-1 ring-slate-100" data-testid="activity">
            <p class="flex items-start gap-1.5 font-medium break-words text-slate-900">
              <FontAwesomeIcon :icon="activityIcon(activity.type)" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
              <span class="min-w-0">{{ activityName(activity) }}</span>
            </p>
            <p v-if="activityDetail(activity)" class="text-xs break-words text-slate-500">{{ activityDetail(activity) }}</p>
            <p v-if="activity.notes" class="text-xs break-words text-slate-500">{{ activity.notes }}</p>
            <WorkoutSessionSummary v-if="activity.session && activity.session.exercises.length > 0" :session="activity.session" />
          </li>
        </ul>
      </section>

      <button
        v-if="suggestions > 0"
        type="button"
        class="min-h-11 w-full rounded-xl border border-brand-300 bg-white px-4 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-50"
        data-testid="show-suggestions"
        @click="emit('show-suggestions')"
      >
        Review {{ suggestions === 1 ? 'the adjustment' : 'the adjustments' }}
      </button>
    </div>
  </article>
</template>
