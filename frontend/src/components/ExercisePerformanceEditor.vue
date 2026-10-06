<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, ref } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import type { WorkoutPlanExercise } from '@/types'

const props = defineProps<{ exercise: WorkoutPlanExercise }>()
const emit = defineEmits<{ close: [] }>()

interface Row {
  reps: number | null
  weight_kg: number | null
  duration_seconds: number | null
  completed: boolean
}

const MAX_PREFILLED_SETS = 10

const store = useWorkoutPlanStore()

function initialRows(): Row[] {
  const recorded = props.exercise.performance

  // Re-opening numbers the user typed (or converted from an earlier log) edits
  // those. A one-tap "done" only assumed the plan's target, so it starts over
  // from the target — the user is now entering what really happened.
  if (recorded && recorded.recorded_as !== 'as_planned' && recorded.sets.length > 0) {
    return recorded.sets.map((set) => ({
      reps: set.reps,
      weight_kg: set.weight_kg,
      duration_seconds: set.duration_seconds,
      completed: set.completed,
    }))
  }

  const e = props.exercise
  const hasTarget =
    e.planned_reps !== null || e.planned_weight_kg !== null || e.planned_duration_seconds !== null

  if (!hasTarget) {
    return [{ reps: null, weight_kg: null, duration_seconds: null, completed: true }]
  }

  return Array.from({ length: Math.min(e.planned_sets ?? 1, MAX_PREFILLED_SETS) }, () => ({
    reps: e.planned_reps,
    weight_kg: e.planned_weight_kg,
    duration_seconds: e.planned_duration_seconds,
    completed: true,
  }))
}

const rows = ref<Row[]>(initialRows())

const showDuration = computed(
  () =>
    props.exercise.planned_duration_seconds !== null ||
    rows.value.some((row) => row.duration_seconds !== null),
)

const target = computed(() => {
  const e = props.exercise
  const parts: string[] = []

  if (e.planned_sets && e.planned_reps) parts.push(`${e.planned_sets} × ${e.planned_reps}`)
  if (e.planned_weight_kg !== null) parts.push(`@ ${e.planned_weight_kg} kg`)
  if (e.planned_duration_seconds) parts.push(`${e.planned_duration_seconds}s`)

  return parts.join(' ')
})

const saving = computed(() => store.savingPerformanceFor === props.exercise.id)

const valid = computed(
  () =>
    rows.value.length > 0 &&
    rows.value.every((row) => row.reps !== null || row.weight_kg !== null || row.duration_seconds !== null) &&
    rows.value.some((row) => row.completed),
)

function numberFrom(event: Event): number | null {
  const raw = (event.target as HTMLInputElement).value.trim()

  return raw === '' || Number.isNaN(Number(raw)) ? null : Number(raw)
}

function addSet() {
  const last = rows.value[rows.value.length - 1]
  rows.value.push({
    reps: last?.reps ?? null,
    weight_kg: last?.weight_kg ?? null,
    duration_seconds: last?.duration_seconds ?? null,
    completed: true,
  })
}

function removeSet(index: number) {
  rows.value.splice(index, 1)
}

async function save() {
  if (!valid.value || saving.value) return

  const saved = await store.recordPerformance(
    props.exercise.id,
    rows.value.map((row) => ({ ...row })),
  )

  if (saved) emit('close')
}
</script>

<template>
  <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3" data-testid="performance-editor">
    <p v-if="target" class="text-xs text-slate-500">
      Target <span class="font-medium text-slate-700">{{ target }}</span> — change only what differed.
    </p>

    <div v-for="(row, index) in rows" :key="index" class="flex flex-wrap items-center gap-2">
      <span class="w-10 shrink-0 text-xs text-slate-400">Set {{ index + 1 }}</span>
      <input
        :value="row.reps ?? ''"
        type="number"
        inputmode="numeric"
        min="0"
        max="200"
        placeholder="reps"
        :aria-label="`Set ${index + 1} reps`"
        class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        @input="row.reps = numberFrom($event)"
      />
      <span class="text-xs text-slate-400">×</span>
      <input
        :value="row.weight_kg ?? ''"
        type="number"
        inputmode="decimal"
        min="0"
        max="500"
        step="0.5"
        placeholder="kg"
        :aria-label="`Set ${index + 1} weight in kilograms`"
        class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        @input="row.weight_kg = numberFrom($event)"
      />
      <input
        v-if="showDuration"
        :value="row.duration_seconds ?? ''"
        type="number"
        inputmode="numeric"
        min="0"
        placeholder="sec"
        :aria-label="`Set ${index + 1} duration in seconds`"
        class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        @input="row.duration_seconds = numberFrom($event)"
      />
      <label class="flex items-center gap-1 text-xs text-slate-500">
        <input v-model="row.completed" type="checkbox" :aria-label="`Set ${index + 1} completed`" />
        done
      </label>
      <button
        v-if="rows.length > 1"
        type="button"
        class="text-slate-300 hover:text-red-500"
        :aria-label="`Remove set ${index + 1}`"
        @click="removeSet(index)"
      >
        <FontAwesomeIcon :icon="icons.action.delete" :class="ICON_SIZE.xs" />
      </button>
    </div>

    <button
      type="button"
      class="flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600"
      @click="addSet"
    >
      <FontAwesomeIcon :icon="icons.action.add" :class="ICON_SIZE.xs" />
      Add set
    </button>

    <p v-if="store.performanceError" class="flex items-center gap-1.5 text-xs text-red-600">
      <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.xs" />
      {{ store.performanceError }}
    </p>

    <div class="flex items-center gap-2">
      <button
        type="button"
        :disabled="!valid || saving"
        class="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
        @click="save"
      >
        <FontAwesomeIcon
          :icon="saving ? icons.loading : icons.action.save"
          :class="ICON_SIZE.xs"
          :spin="saving"
        />
        Save sets
      </button>
      <button type="button" class="text-xs text-slate-400 hover:text-slate-600" @click="emit('close')">
        Cancel
      </button>
    </div>
  </div>
</template>
