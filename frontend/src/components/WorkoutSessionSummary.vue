<script setup lang="ts">
import type { WorkoutSession } from '@/types'
import { formatPerformedSets } from '@/utils/format'

defineProps<{ session: WorkoutSession }>()

const LABEL = {
  as_planned: 'as planned',
  migrated: 'earlier log',
  entered: null,
} as const
</script>

<template>
  <ul
    class="mt-2 space-y-0.5 border-t border-slate-100 pt-2 text-xs text-slate-500"
    data-testid="session-summary"
  >
    <li v-for="exercise in session.exercises" :key="exercise.id">
      <span class="font-medium text-slate-700">{{ exercise.exercise_name }}</span>
      <template v-if="exercise.performed_sets.length > 0">
        — {{ formatPerformedSets(exercise.performed_sets) }}
      </template>
      <template v-else-if="exercise.sets !== null && exercise.reps !== null">
        — {{ exercise.sets }} × {{ exercise.reps
        }}<template v-if="exercise.weight_kg !== null"> @ {{ exercise.weight_kg }} kg</template>
      </template>
      <span
        v-if="LABEL[exercise.recorded_as]"
        class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-400"
      >
        {{ LABEL[exercise.recorded_as] }}
      </span>
    </li>
  </ul>
</template>
