<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted, ref } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useWeeklyPlanStore } from '@/stores/weeklyPlan'
import type { ActivityType } from '@/types'
import { activityIcon, formatDate } from '@/utils/format'
import { todayUtc } from '@/utils/week'

const store = useWeeklyPlanStore()
const input = ref('')
const today = todayUtc()

onMounted(() => {
  store.loadWeek()
})

const showEmptyState = computed(() => store.error !== null && store.days.length === 0)

function submit() {
  const value = input.value.trim()
  if (!value || store.sending) return

  store.submitAdaptation(value)
  input.value = ''
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <h1 class="text-lg font-semibold text-slate-900">Weekly Plan</h1>

    <p
      v-if="store.loading"
      class="flex items-center justify-center gap-1.5 pt-8 text-center text-sm text-slate-400"
    >
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
      Loading your week…
    </p>

    <div v-else-if="showEmptyState" class="rounded-xl border border-slate-200 bg-white p-4">
      <ErrorNotice :message="store.error!" :on-retry="store.loadWeek" />
    </div>

    <template v-else>
      <div class="space-y-2">
        <div
          v-for="day in store.days"
          :key="day.date"
          class="space-y-2 rounded-xl border p-4"
          :class="day.date === today ? 'border-brand-300 bg-brand-50' : 'border-slate-200 bg-white'"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-xs font-medium tracking-wide text-slate-400 uppercase">
                {{ formatDate(day.date) }}
              </p>
              <p v-if="day.plan" class="flex items-center gap-1.5 font-semibold text-slate-900">
                <FontAwesomeIcon :icon="activityIcon(day.plan.activity_type as ActivityType)" :class="ICON_SIZE.sm" />
                {{ day.plan.title }}
              </p>
              <p v-else class="text-sm text-slate-400">Nothing planned</p>
            </div>

            <span
              v-if="day.plan"
              class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
              :class="{
                'bg-brand-100 text-brand-700': day.plan.status === 'completed',
                'bg-amber-100 text-amber-700': day.plan.status === 'partial' || day.plan.status === 'in_progress',
                'bg-slate-100 text-slate-500': day.plan.status === 'planned' || day.plan.status === 'skipped',
              }"
            >
              {{ day.plan.status.replace('_', ' ') }}
            </span>
          </div>

          <WhyThisChangedCard
            v-if="day.plan"
            :reasoning="day.plan.reasoning"
            :reasoning-factors="day.plan.reasoning_factors"
          />
        </div>
      </div>

      <div v-if="store.lastResult">
        <WhyThisChangedCard
          v-if="store.lastResult.applied"
          :reasoning="store.lastResult.decision_summary ?? null"
          :reasoning-factors="store.lastResult.reasoning_factors"
        />
        <p
          v-else-if="store.lastResult.explanation"
          class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600"
        >
          {{ store.lastResult.explanation }}
        </p>
      </div>

      <form class="flex items-end gap-2" @submit.prevent="submit">
        <textarea
          v-model="input"
          rows="2"
          placeholder='Tell your coach something that might change your week — e.g. "I have soccer on Wednesday"'
          class="max-h-32 flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
        />
        <button
          type="submit"
          :disabled="store.sending || !input.trim()"
          class="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
        >
          <FontAwesomeIcon
            :icon="store.sending ? icons.loading : icons.action.send"
            :class="ICON_SIZE.sm"
            :spin="store.sending"
          />
          {{ store.sending ? 'Thinking…' : 'Send' }}
        </button>
      </form>

      <p v-if="store.error" class="flex items-center gap-1.5 text-sm text-red-600">
        <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
        {{ store.error }}
      </p>
    </template>
  </div>
</template>
