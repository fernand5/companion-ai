<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import ProgressionRecommendations from '@/components/ProgressionRecommendations.vue'
import WeekDayCard from '@/components/WeekDayCard.vue'
import WeekStrip from '@/components/WeekStrip.vue'
import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProgressionStore } from '@/stores/progression'
import { useWeeklyPlanStore } from '@/stores/weeklyPlan'
import { formatWeekRange } from '@/utils/weekDay'

const store = useWeeklyPlanStore()
const input = ref('')

const progression = useProgressionStore()

/** The user's own "today", as the server decided it. */
const today = computed(() => store.week?.today ?? null)

/** The days whose details are open. Today starts open; the strip opens the day it points at. */
const open = ref<Set<string>>(new Set())
const selected = ref<string | null>(null)

/** How many calculated adjustments are waiting for approval on each day (from the Progression section below). */
const suggestionsByDay = computed(() => {
  const counts: Record<string, number> = {}

  for (const item of progression.items) {
    if (item.state === 'suggested' && item.applies_to) {
      counts[item.applies_to.date] = (counts[item.applies_to.date] ?? 0) + 1
    }
  }

  return counts
})

onMounted(() => {
  store.loadWeek()
})

// Once a week is on screen, open today (only the first time, so a reload never closes what the user opened).
watch(
  () => store.week?.start,
  (start, previous) => {
    if (!start || !today.value) return

    if (previous === undefined || previous !== start) {
      selected.value = today.value
      open.value = new Set([today.value])
    }
  },
  { immediate: true },
)

// Filling in the week can add the upcoming sessions a recommendation would apply to.
watch(
  () => store.filled,
  (filled) => {
    if (filled) progression.load()
  },
)

// An approved progression changes a load in the plan: re-read the week so the day on screen shows what is stored now.
const appliedCount = computed(() => Object.values(progression.actions).filter((a) => a.status === 'applied').length)

watch(appliedCount, (now, before) => {
  if (now > before) store.reloadDays()
})

const showEmptyState = computed(() => store.error !== null && store.days.length === 0)

const range = computed(() => (store.week ? formatWeekRange(store.week.start, store.week.end) : ''))

function toggle(date: string) {
  const next = new Set(open.value)

  if (next.has(date)) next.delete(date)
  else next.add(date)

  open.value = next
  selected.value = date
}

async function select(date: string) {
  selected.value = date
  open.value = new Set(open.value).add(date)

  await nextTick()
  document.getElementById(`day-${date}`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

function showSuggestions() {
  document.getElementById('progression-heading')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function submit() {
  const value = input.value.trim()
  if (!value || store.sending) return

  store.submitAdaptation(value)
  input.value = ''
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-5 p-4 sm:p-6">
    <header class="space-y-0.5">
      <h1 class="text-lg font-semibold text-slate-900">Weekly Plan</h1>
      <p v-if="range" class="flex items-center gap-2 text-sm text-slate-500" data-testid="week-range">
        {{ range }}
        <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700">This week</span>
      </p>
    </header>

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
      <WeekStrip :days="store.days" :selected="selected" @select="select" />

      <p v-if="store.generating" class="flex items-center gap-1.5 text-sm text-slate-500">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        Filling in the rest of your week — this can take a minute…
      </p>

      <div class="space-y-2" data-testid="week-days">
        <WeekDayCard
          v-for="day in store.days"
          :key="day.date"
          :day="day"
          :expanded="open.has(day.date)"
          :suggestions="suggestionsByDay[day.date] ?? 0"
          @toggle="toggle(day.date)"
          @show-suggestions="showSuggestions"
        />
      </div>

      <ul
        v-if="store.notices.length > 0"
        class="space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
        role="status"
        data-testid="plan-notices"
      >
        <li v-for="notice in store.notices" :key="notice">{{ notice }}</li>
      </ul>

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
          class="max-h-32 min-w-0 flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
        />
        <button
          type="submit"
          :disabled="store.sending || !input.trim()"
          class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
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

    <!-- Below the week, and outside its loading states so it mounts (and fetches) once, not each time the week reloads. -->
    <ProgressionRecommendations />
  </div>
</template>
