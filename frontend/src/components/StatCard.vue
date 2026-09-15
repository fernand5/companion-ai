<script setup lang="ts">
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

import { ICON_SIZE } from '@/constants/icons'

defineProps<{
  label: string
  value: string
  hint?: string
  icon?: IconDefinition
}>()
</script>

<template>
  <div class="rounded-xl border border-slate-200 bg-white p-4">
    <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">
      <FontAwesomeIcon v-if="icon" :icon="icon" :class="ICON_SIZE.xs" />
      {{ label }}
    </p>
    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ value }}</p>
    <p v-if="hint" class="mt-0.5 text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
