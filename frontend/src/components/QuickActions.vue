<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const emit = defineEmits<{ pick: [prompt: string] }>()

// The key identifies the suggestion; the words are looked up when drawn, and picking one sends those words.
const actions = ['today', 'logWorkout', 'logSteps', 'updateWeight', 'progress'] as const
</script>

<template>
  <div class="flex gap-2 overflow-x-auto pb-1">
    <button
      v-for="action in actions"
      :key="action"
      type="button"
      class="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-brand-300 hover:text-brand-700"
      @click="emit('pick', t(`coach.suggestions.${action}`))"
    >
      {{ t(`coach.suggestions.${action}`) }}
    </button>
  </div>
</template>
