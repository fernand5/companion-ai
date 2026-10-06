<script setup lang="ts">
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, ref } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import ExercisePerformanceEditor from '@/components/ExercisePerformanceEditor.vue'
import ExerciseDemo from '@/components/ExerciseDemo.vue'
import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { PlanExerciseStatus, WorkoutPlanExercise } from '@/types'
import { formatPerformedSets } from '@/utils/format'

const store = useWorkoutPlanStore()
const plan = computed(() => store.today)
/** The exercise whose set-by-set editor is open (one at a time keeps the card short). */
const editingId = ref<number | null>(null)

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
    const target = `${exercise.planned_sets} × ${exercise.planned_reps}`

    return exercise.planned_weight_kg !== null ? `${target} @ ${exercise.planned_weight_kg} kg` : target
  }
  if (exercise.planned_duration_seconds) {
    return `${Math.round(exercise.planned_duration_seconds / 60)} min`
  }
  return ''
}

function performanceText(exercise: WorkoutPlanExercise): string {
  const sets = exercise.performance?.sets ?? []

  return sets.length > 0 ? formatPerformedSets(sets) : 'amount not recorded'
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
      <li v-for="exercise in plan.exercises" :key="exercise.id" class="rounded-lg px-2 py-1.5 hover:bg-slate-50">
        <div class="flex items-center justify-between gap-2">
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
        </div>

        <!-- Actual performance, kept apart from the target shown on the row above. -->
        <div class="mt-1.5 space-y-1.5 pl-6">
          <p v-if="exercise.performance" class="text-xs text-slate-500" data-testid="logged-sets">
            <span class="font-medium text-slate-600">Logged</span> {{ performanceText(exercise) }}
            <span
              v-if="exercise.performance.recorded_as === 'as_planned'"
              class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-400"
              title="Marked done without entering numbers — the plan's target is assumed."
            >
              as planned
            </span>
          </p>
          <button
            type="button"
            class="flex items-center gap-1 text-xs text-slate-400 hover:text-brand-600"
            @click="editingId = editingId === exercise.id ? null : exercise.id"
          >
            <FontAwesomeIcon :icon="icons.action.edit" :class="ICON_SIZE.xs" />
            {{ exercise.performance?.recorded_as === 'entered' ? 'Edit sets' : 'Log sets' }}
          </button>
          <ExercisePerformanceEditor v-if="editingId === exercise.id" :exercise="exercise" @close="editingId = null" />
        </div>

        <!-- After the status controls in DOM order: a failed or slow image can never get in the way of completing the exercise. -->
        <ExerciseDemo
          class="mt-1.5 pl-6"
          :plan-id="plan.id"
          :exercise-id="exercise.id"
          :exercise-name="exercise.exercise_name"
          :image-url="exercise.image_url"
        />
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
