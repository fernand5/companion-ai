<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import RecoveryCheckinForm from '@/components/RecoveryCheckinForm.vue'
import StatCard from '@/components/StatCard.vue'
import TodaysPlanCard from '@/components/TodaysPlanCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useDashboardStore } from '@/stores/dashboard'
import { useWorkoutPlanStore } from '@/stores/workoutPlan'
import { activityIcon, activityLabel, activitySummary, dayName, formatTime } from '@/utils/format'

const store = useDashboardStore()
const workoutPlanStore = useWorkoutPlanStore()

onMounted(() => {
  store.loadDashboard()
  workoutPlanStore.loadToday()
})

const data = computed(() => store.dashboard)

const weightChangeLabel = computed(() => {
  const change = data.value?.progress.weight_change_kg
  if (change === null || change === undefined) return '—'

  return `${change > 0 ? '+' : ''}${change} kg`
})

const recommendationUnavailableMessage = computed(() => {
  switch (data.value?.coach_recommendation_unavailable_reason) {
    case 'rate_limited':
      return "Your coach is at its request limit for now — check back in a bit, or ask directly on the Coach tab."
    case 'unavailable':
      return "Your coach is temporarily unavailable — try again shortly, or ask directly on the Coach tab."
    case 'not_configured':
    default:
      return 'Set your AI_API_KEY to get a live recommendation, or ask your coach directly on the Coach tab.'
  }
})
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
    <div v-if="store.loading" class="flex items-center justify-center gap-2 py-12 text-center text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.md" spin />
      Loading dashboard…
    </div>

    <div v-else-if="store.error" class="rounded-xl border border-slate-200 bg-white p-4">
      <ErrorNotice :message="store.error" :on-retry="store.loadDashboard" />
    </div>

    <template v-else-if="data">
      <section
        class="rounded-2xl bg-gradient-to-br from-brand-600 to-brand-700 p-5 text-white shadow-sm"
      >
        <h2 class="flex items-center gap-1.5 text-sm font-medium uppercase tracking-wide text-brand-100">
          <FontAwesomeIcon :icon="icons.ai" :class="ICON_SIZE.sm" />
          Today's recommendation
        </h2>
        <p v-if="data.coach_recommendation" class="mt-2 text-lg leading-snug">
          {{ data.coach_recommendation }}
        </p>
        <p v-else class="mt-2 flex items-start gap-1.5 text-sm text-brand-100">
          <FontAwesomeIcon :icon="icons.warning" :class="[ICON_SIZE.sm, 'mt-0.5 shrink-0']" />
          {{ recommendationUnavailableMessage }}
        </p>
        <RouterLink
          :to="{ name: 'coach' }"
          class="mt-3 inline-flex items-center gap-1 text-sm font-semibold underline decoration-brand-200 underline-offset-4"
        >
          Ask your coach
          <FontAwesomeIcon :icon="icons.action.next" :class="ICON_SIZE.xs" />
        </RouterLink>
      </section>

      <RecoveryCheckinForm />

      <TodaysPlanCard />

      <section>
        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
          <FontAwesomeIcon :icon="icons.calendarDay" :class="[ICON_SIZE.sm, 'text-slate-400']" />
          Today
        </h2>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <p v-if="data.today_activity.length === 0" class="flex items-center gap-1.5 text-sm text-slate-500">
            <FontAwesomeIcon :icon="icons.activityFallback" :class="[ICON_SIZE.sm, 'text-slate-300']" />
            Nothing logged yet today.
          </p>
          <ul v-else class="space-y-2">
            <li
              v-for="log in data.today_activity"
              :key="log.id"
              class="flex items-center gap-3 text-sm"
            >
              <FontAwesomeIcon :icon="activityIcon(log.type)" :class="[ICON_SIZE.sm, 'text-slate-500']" fixed-width />
              <span class="font-medium text-slate-800">{{ activityLabel(log.type) }}</span>
              <span class="text-slate-500">{{ activitySummary(log) }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section v-if="data.upcoming_schedule.length > 0">
        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
          <FontAwesomeIcon :icon="icons.calendar" :class="[ICON_SIZE.sm, 'text-slate-400']" />
          Upcoming
        </h2>
        <div class="space-y-2">
          <div
            v-for="(item, idx) in data.upcoming_schedule"
            :key="idx"
            class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-3 text-sm"
          >
            <div>
              <span class="font-medium text-slate-800">{{ item.activity_type }}</span>
              <span class="ml-2 text-slate-500">{{ formatTime(item.start_time) }}</span>
            </div>
            <span class="text-xs text-slate-400">{{
              dayName(new Date(`${item.date}T00:00:00`).getDay())
            }}</span>
          </div>
        </div>
      </section>

      <section>
        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
          <FontAwesomeIcon :icon="icons.nav.progress" :class="[ICON_SIZE.sm, 'text-slate-400']" />
          Progress
        </h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard
            label="Current weight"
            :icon="icons.activityType.weight"
            :value="data.progress.current_weight_kg ? `${data.progress.current_weight_kg} kg` : '—'"
          />
          <StatCard label="Change" :icon="icons.nav.progress" :value="weightChangeLabel" />
          <StatCard
            label="Workouts this week"
            :icon="icons.activityType.strength"
            :value="String(data.progress.workouts_this_week)"
          />
          <StatCard
            label="Avg steps"
            :icon="icons.activityType.steps"
            :value="String(data.progress.average_steps)"
          />
        </div>
      </section>
    </template>
  </div>
</template>
