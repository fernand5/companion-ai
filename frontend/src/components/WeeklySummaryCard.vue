<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'

import StatCard from '@/components/StatCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import type { WeeklySummary } from '@/types'

const props = defineProps<{
  summary: WeeklySummary | null
  loading: boolean
  error: string | null
}>()

const generatedAtLabel = computed(() => {
  if (!props.summary) return ''

  return new Date(props.summary.generated_at).toLocaleString(undefined, {
    weekday: 'short',
    hour: 'numeric',
    minute: '2-digit',
  })
})
</script>

<template>
  <div class="rounded-xl border border-slate-200 bg-white p-4">
    <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
      <FontAwesomeIcon :icon="icons.nav.progress" :class="[ICON_SIZE.sm, 'text-slate-400']" />
      Your week
    </h2>

    <p v-if="loading" class="flex items-center gap-1.5 text-sm text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
      Loading…
    </p>
    <p v-else-if="error" class="flex items-center gap-1.5 text-sm text-red-600">
      <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
      {{ error }}
    </p>

    <template v-else-if="summary">
      <div class="grid grid-cols-3 gap-3">
        <StatCard
          label="Adherence"
          :icon="icons.adherence"
          :value="summary.stats.adherence_pct !== null ? `${summary.stats.adherence_pct}%` : '—'"
        />
        <StatCard
          label="Planned done"
          :icon="icons.planStatus.completed"
          :value="String(summary.stats.planned_workouts_completed)"
        />
        <StatCard label="Active days" :icon="icons.calendarDay" :value="String(summary.stats.active_days)" />
      </div>

      <div class="mt-3 space-y-2 text-sm">
        <p><span class="font-medium text-slate-700">Coach insight:</span> {{ summary.coach_insight }}</p>
        <p><span class="font-medium text-slate-700">Next week's focus:</span> {{ summary.next_week_focus }}</p>
      </div>

      <p class="mt-2 text-xs text-slate-400">As of {{ generatedAtLabel }}</p>
    </template>
  </div>
</template>
