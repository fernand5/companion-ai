<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ICON_SIZE, icons } from '@/constants/icons'
import { formatDateOnly } from '@/i18n/format'
import type { SaveResult } from '@/types'

/**
 * What a save actually did, shown from the server's own record of it (read back from the database after the change was
 * stored), never from the coach's wording: each affected day with its exact date, the workout's stored name, and whether it
 * was added, replaced, or made a rest day.
 */
const props = defineProps<{ result: SaveResult }>()

const { t } = useI18n()

const days = computed(() => props.result.days)

function dateLabel(date: string): string {
  return formatDateOnly(date, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<template>
  <section class="mt-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm" data-testid="save-result" :aria-label="t('coach.saved.title')">
    <p class="flex items-center gap-1.5 font-medium text-emerald-800">
      <FontAwesomeIcon :icon="icons.planStatus.completed" :class="ICON_SIZE.sm" />
      {{ t('coach.saved.title') }}
    </p>
    <ul class="mt-2 space-y-1.5">
      <li v-for="day in days" :key="day.date" class="min-w-0" data-testid="save-result-day">
        <p class="text-slate-900 break-words [overflow-wrap:anywhere]">
          <span class="font-medium" data-testid="save-result-title">{{ day.title ?? t('coach.saved.rest') }}</span>
        </p>
        <p class="text-xs text-slate-600">
          <span data-testid="save-result-date">{{ dateLabel(day.date) }}</span>
          ·
          <span data-testid="save-result-outcome">{{ t(`coach.saved.${day.outcome}`) }}</span>
        </p>
      </li>
    </ul>
    <RouterLink
      :to="{ name: 'dashboard' }"
      class="mt-3 inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
      data-testid="save-result-dashboard"
    >
      <FontAwesomeIcon :icon="icons.nav.dashboard" :class="ICON_SIZE.sm" />
      {{ t('coach.saved.dashboard') }}
    </RouterLink>
  </section>
</template>
