<script setup lang="ts">
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { PlanExerciseStatus, WorkoutPlanExercise } from '@/types'

const store = useWorkoutPlanStore()
const plan = computed(() => store.today)

function icon(status: PlanExerciseStatus): IconDefinition {
  return icons.planStatus[status] ?? icons.planStatus.pending
}

const STATUS_COLOR: Record<PlanExerciseStatus, string> = {
  completed: 'text-brand-600',
  partial: 'text-amber-500',
  skipped: 'text-red-400',
  pending: 'text-slate-300',
}

function summary(exercise: WorkoutPlanExercise): string {
  if (exercise.planned_sets && exercise.planned_reps) {
    return `${exercise.planned_sets} × ${exercise.planned_reps}`
  }
  if (exercise.planned_duration_seconds) {
    return `${Math.round(exercise.planned_duration_seconds / 60)} min`
  }
  return ''
}

function setStatus(exercise: WorkoutPlanExercise, status: PlanExerciseStatus) {
  store.updateExerciseStatus(exercise.id, status)
}
</script>

<template>
  <div v-if="plan" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase">
          <FontAwesomeIcon :icon="icons.nav.activity" :class="ICON_SIZE.xs" />
          Today's plan
        </p>
        <h2 class="font-semibold text-slate-900">{{ plan.title }}</h2>
      </div>
      <span
        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
        :class="{
          'bg-brand-100 text-brand-700': plan.status === 'completed',
          'bg-amber-100 text-amber-700': plan.status === 'partial' || plan.status === 'in_progress',
          'bg-slate-100 text-slate-500': plan.status === 'planned' || plan.status === 'skipped',
        }"
      >
        {{ plan.status.replace('_', ' ') }}
      </span>
    </div>

    <WhyThisChangedCard :reasoning="plan.reasoning" :reasoning-factors="plan.reasoning_factors" />

    <ul v-if="plan.exercises.length > 0" class="space-y-1.5">
      <li
        v-for="exercise in plan.exercises"
        :key="exercise.id"
        class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50"
      >
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
          @click="setStatus(exercise, exercise.status === 'completed' ? 'pending' : 'completed')"
        >
          <FontAwesomeIcon
            :icon="icon(exercise.status)"
            :class="[ICON_SIZE.sm, STATUS_COLOR[exercise.status]]"
            fixed-width
          />
          <span
            class="truncate"
            :class="exercise.status === 'skipped' ? 'text-slate-400 line-through' : 'text-slate-800'"
          >
            {{ exercise.exercise_name }}
          </span>
          <span class="shrink-0 text-xs text-slate-400">{{ summary(exercise) }}</span>
        </button>

        <div class="flex shrink-0 items-center gap-1 text-xs">
          <button
            v-if="exercise.status !== 'pending'"
            type="button"
            class="p-1 text-slate-400 hover:text-slate-600"
            aria-label="Reset to pending"
            title="Reset to pending"
            @click="setStatus(exercise, 'pending')"
          >
            <FontAwesomeIcon :icon="icons.action.reset" :class="ICON_SIZE.sm" />
          </button>
          <template v-else>
            <button
              type="button"
              class="flex items-center gap-1 text-slate-400 hover:text-amber-600"
              @click="setStatus(exercise, 'partial')"
            >
              <FontAwesomeIcon :icon="icons.planStatus.partial" :class="ICON_SIZE.xs" />
              Partial
            </button>
            <button
              type="button"
              class="flex items-center gap-1 text-slate-400 hover:text-red-500"
              @click="setStatus(exercise, 'skipped')"
            >
              <FontAwesomeIcon :icon="icons.planStatus.skipped" :class="ICON_SIZE.xs" />
              Skip
            </button>
          </template>
        </div>
      </li>
    </ul>

    <p v-if="store.error" class="flex items-center gap-1.5 text-xs text-red-600">
      <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.xs" />
      {{ store.error }}
    </p>
  </div>
  <div v-else-if="store.error" class="rounded-xl border border-slate-200 bg-white p-4">
    <ErrorNotice :message="store.error" :on-retry="store.loadToday" />
  </div>
</template>
