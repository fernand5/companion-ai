<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import StatCard from '@/components/StatCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { formatDateTime } from '@/i18n/format'
import type { WeeklySummary } from '@/types'

const { t } = useI18n()

const props = defineProps<{
  summary: WeeklySummary | null
  loading: boolean
  error: string | null
}>()

const generatedAtLabel = computed(() => {
  if (!props.summary) return ''

  return formatDateTime(props.summary.generated_at, {
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
      {{ t('progress.summary.title') }}
    </h2>

    <p v-if="loading" class="flex items-center gap-1.5 text-sm text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
      {{ t('common.loading') }}
    </p>
    <p v-else-if="error" class="flex items-center gap-1.5 text-sm text-red-600">
      <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
      {{ error }}
    </p>

    <template v-else-if="summary">
      <div class="grid grid-cols-3 gap-3">
        <StatCard
          :label="t('progress.summary.adherence')"
          :icon="icons.adherence"
          :value="summary.stats.adherence_pct !== null ? `${summary.stats.adherence_pct}%` : '—'"
        />
        <StatCard
          :label="t('progress.summary.plannedDone')"
          :icon="icons.planStatus.completed"
          :value="String(summary.stats.planned_workouts_completed)"
        />
        <StatCard :label="t('progress.summary.activeDays')" :icon="icons.calendarDay" :value="String(summary.stats.active_days)" />
      </div>

      <div class="mt-3 space-y-2 text-sm">
        <p><span class="font-medium text-slate-700">{{ t('progress.summary.insight') }}</span> {{ summary.coach_insight }}</p>
        <p><span class="font-medium text-slate-700">{{ t('progress.summary.nextFocus') }}</span> {{ summary.next_week_focus }}</p>
      </div>

      <p class="mt-2 text-xs text-slate-400">{{ t('progress.summary.asOf', { date: generatedAtLabel }) }}</p>
    </template>
  </div>
</template>
