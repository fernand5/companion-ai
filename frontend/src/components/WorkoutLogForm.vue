<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { reactive } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useActivityStore } from '@/stores/activity'

const activityStore = useActivityStore()

interface ExerciseDraft {
  exercise_name: string
  sets: number | undefined
  reps: number | undefined
  weight_kg: number | undefined
}

const form = reactive({
  duration_minutes: 45,
  notes: '',
  exercises: [{ exercise_name: '', sets: undefined, reps: undefined, weight_kg: undefined }] as ExerciseDraft[],
})

function addExercise() {
  form.exercises.push({ exercise_name: '', sets: undefined, reps: undefined, weight_kg: undefined })
}

function removeExercise(index: number) {
  form.exercises.splice(index, 1)
}

async function submit() {
  await activityStore.logWorkout({
    duration_minutes: form.duration_minutes,
    notes: form.notes || undefined,
    exercises: form.exercises
      .filter((e) => e.exercise_name.trim() !== '')
      .map((e) => ({ ...e })),
  })

  form.notes = ''
  form.exercises = [{ exercise_name: '', sets: undefined, reps: undefined, weight_kg: undefined }]
}
</script>

<template>
  <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="submit">
    <p class="flex items-center gap-1.5 text-sm font-medium text-slate-700">
      <FontAwesomeIcon :icon="icons.activityType.strength" :class="[ICON_SIZE.sm, 'text-slate-400']" />
      Log a strength workout
    </p>

    <div
      v-for="(exercise, index) in form.exercises"
      :key="index"
      class="flex flex-wrap items-center gap-2"
    >
      <input
        v-model="exercise.exercise_name"
        type="text"
        placeholder="Exercise"
        class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <input
        v-model.number="exercise.sets"
        type="number"
        placeholder="Sets"
        class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <input
        v-model.number="exercise.reps"
        type="number"
        placeholder="Reps"
        class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <input
        v-model.number="exercise.weight_kg"
        type="number"
        placeholder="kg"
        class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <button
        v-if="form.exercises.length > 1"
        type="button"
        class="text-slate-400 hover:text-red-500"
        aria-label="Remove exercise"
        @click="removeExercise(index)"
      >
        <FontAwesomeIcon :icon="icons.action.delete" :class="ICON_SIZE.xs" />
      </button>
    </div>

    <button
      type="button"
      class="flex items-center gap-1 text-xs font-medium text-brand-600"
      @click="addExercise"
    >
      <FontAwesomeIcon :icon="icons.action.add" :class="ICON_SIZE.xs" />
      Add exercise
    </button>

    <div class="flex items-center gap-3">
      <label class="text-sm text-slate-600">
        Duration
        <input
          v-model.number="form.duration_minutes"
          type="number"
          class="ml-1 w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
        min
      </label>
    </div>

    <input
      v-model="form.notes"
      type="text"
      placeholder="Notes (optional)"
      class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
    />

    <button
      type="submit"
      class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
    >
      <FontAwesomeIcon :icon="icons.action.add" :class="ICON_SIZE.sm" />
      Log workout
    </button>
  </form>
</template>
