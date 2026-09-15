<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

import { ICON_SIZE, icons } from '@/constants/icons'

withDefaults(
  defineProps<{
    reasoning: string | null
    reasoningFactors?: string[]
  }>(),
  { reasoningFactors: () => [] },
)
</script>

<template>
  <div
    v-if="reasoning || reasoningFactors.length > 0"
    class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
  >
    <p class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase">
      <FontAwesomeIcon :icon="icons.info" :class="ICON_SIZE.xs" />
      Why this changed
    </p>
    <ul v-if="reasoningFactors.length > 0" class="mt-1.5 list-disc space-y-0.5 pl-4">
      <li v-for="(factor, idx) in reasoningFactors" :key="idx">{{ factor }}</li>
    </ul>
    <p v-if="reasoning" class="mt-1.5">{{ reasoning }}</p>
  </div>
</template>
