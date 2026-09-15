<script setup lang="ts">
const emit = defineEmits<{ pick: [prompt: string] }>()

const actions = [
  'What should I do today?',
  'Log today’s workout',
  'Log my steps',
  'Update my weight',
  'How am I progressing?',
]
</script>

<template>
  <div class="flex gap-2 overflow-x-auto pb-1">
    <button
      v-for="action in actions"
      :key="action"
      type="button"
      class="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-brand-300 hover:text-brand-700"
      @click="emit('pick', action)"
    >
      {{ action }}
    </button>
  </div>
</template>
