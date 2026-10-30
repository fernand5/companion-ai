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

/**
 * The day on show. It is the user's today (as the server decided) until they pick another, and it stays what they
 * picked when the week is read again, as long as that day is still in the week.
 */
const selected = ref<string | null>(null)

/** Off: only the selected day's details are shown. On: all seven days, each opened or closed on its own. */
const showFullWeek = ref(false)

/** In the full-week view, the days whose details are open. */
const open = ref<Set<string>>(new Set())

const selectedDay = computed(() => store.days.find((day) => day.date === selected.value) ?? null)

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

// Once a week is on screen, show today. A week that is read again keeps the day the user picked if it is still there;
// a different week (or a day that is gone) starts again from today.
watch(
  () => store.days.map((day) => day.date).join(','),
  () => {
    const dates = store.days.map((day) => day.date)

    if (dates.length === 0) return

    if (selected.value === null || !dates.includes(selected.value)) {
      selected.value = today.value !== null && dates.includes(today.value) ? today.value : dates[0]
      open.value = new Set([selected.value])
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

  // One day on show: choosing another simply shows it. The full week opens that day and brings it into view.
  if (!showFullWeek.value) return

  open.value = new Set(open.value).add(date)

  await nextTick()
  document.getElementById(`day-${date}`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

function toggleFullWeek() {
  showFullWeek.value = !showFullWeek.value

  // Going back to the full week, the day that was on show is the one that is open.
  if (showFullWeek.value && selected.value) open.value = new Set(open.value).add(selected.value)
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
      <WeekStrip :days="store.days" :selected="selected" :controls="showFullWeek ? undefined : 'selected-day'" @select="select" />

      <div class="flex justify-end">
        <button
          type="button"
          class="min-h-11 rounded-lg px-3 text-sm font-medium text-brand-700 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
          :aria-pressed="showFullWeek"
          data-testid="toggle-full-week"
          @click="toggleFullWeek"
        >
          {{ showFullWeek ? 'Show selected day only' : 'Show full week' }}
        </button>
      </div>

      <p v-if="store.generating" class="flex items-center gap-1.5 text-sm text-slate-500">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        Filling in the rest of your week — this can take a minute…
      </p>

      <div v-if="!showFullWeek && selectedDay" id="selected-day" role="region" aria-live="polite" :aria-label="`Details for ${selectedDay.weekday}`" data-testid="selected-day">
        <WeekDayCard
          :key="selectedDay.date"
          :day="selectedDay"
          :expanded="true"
          :collapsible="false"
          :suggestions="suggestionsByDay[selectedDay.date] ?? 0"
          @show-suggestions="showSuggestions"
        />
      </div>

      <div v-else class="space-y-2" data-testid="week-days">
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
