<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted } from 'vue'

import ProgressChart from '@/components/ProgressChart.vue'
import StatCard from '@/components/StatCard.vue'
import WeeklySummaryCard from '@/components/WeeklySummaryCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useDashboardStore } from '@/stores/dashboard'
import { formatDate } from '@/utils/format'

const store = useDashboardStore()

onMounted(() => {
  store.loadProgress()
  store.loadAdherence()
  store.loadWeeklySummary()
})

const data = computed(() => store.progress)
const adherence = computed(() => store.adherence)

const adherenceLabels = computed(() => adherence.value?.series.map((d) => formatDate(d.week_start)) ?? [])
const adherenceValues = computed(() => adherence.value?.series.map((d) => d.adherence_pct ?? 0) ?? [])

const weightLabels = computed(() => data.value?.weight_series.map((d) => formatDate(d.date)) ?? [])
const weightValues = computed(() => data.value?.weight_series.map((d) => d.weight_kg) ?? [])

const stepsLabels = computed(() => data.value?.steps_series.map((d) => formatDate(d.date)) ?? [])
const stepsValues = computed(() => data.value?.steps_series.map((d) => d.steps) ?? [])

const workoutLabels = computed(
  () => data.value?.weekly_workouts.map((d) => formatDate(d.week_start)) ?? [],
)
const workoutValues = computed(() => data.value?.weekly_workouts.map((d) => d.workouts) ?? [])
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <h1 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
      <FontAwesomeIcon :icon="icons.nav.progress" :class="ICON_SIZE.md" />
      Progress
    </h1>

    <div v-if="data" class="space-y-6">
      <WeeklySummaryCard
        :summary="store.weeklySummary"
        :loading="store.weeklySummaryLoading"
        :error="store.weeklySummaryError"
      />

      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatCard
          label="Current"
          :icon="icons.activityType.weight"
          :value="data.summary.current_weight_kg ? `${data.summary.current_weight_kg} kg` : '—'"
        />
        <StatCard
          label="Starting"
          :icon="icons.activityType.weight"
          :value="data.summary.starting_weight_kg ? `${data.summary.starting_weight_kg} kg` : '—'"
        />
        <StatCard label="Workouts/wk" :icon="icons.activityType.strength" :value="String(data.summary.workouts_this_week)" />
        <StatCard label="Avg steps" :icon="icons.activityType.steps" :value="String(data.summary.average_steps)" />
      </div>

      <section v-if="adherence" class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
            <FontAwesomeIcon :icon="icons.adherence" :class="[ICON_SIZE.sm, 'text-slate-400']" />
            Adherence
          </h2>
          <span class="text-xs text-slate-400">Last 7 days: {{ adherence.summary.active_days }} active day(s)</span>
        </div>
        <p class="mb-2 text-2xl font-semibold text-slate-900">
          {{ adherence.summary.adherence_pct !== null ? `${adherence.summary.adherence_pct}%` : '—' }}
        </p>
        <ProgressChart type="line" label="Adherence %" :labels="adherenceLabels" :values="adherenceValues" color="#0ea5e9" />
      </section>

      <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">Weight</h2>
        <ProgressChart
          v-if="weightValues.length > 0"
          type="line"
          label="Weight (kg)"
          :labels="weightLabels"
          :values="weightValues"
        />
        <p v-else class="flex items-center gap-1.5 text-sm text-slate-400">
          <FontAwesomeIcon :icon="icons.activityType.weight" :class="ICON_SIZE.sm" />
          No weight entries yet.
        </p>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">Steps</h2>
        <ProgressChart
          v-if="stepsValues.length > 0"
          type="bar"
          label="Steps"
          :labels="stepsLabels"
          :values="stepsValues"
          color="#0ea5e9"
        />
        <p v-else class="flex items-center gap-1.5 text-sm text-slate-400">
          <FontAwesomeIcon :icon="icons.activityType.steps" :class="ICON_SIZE.sm" />
          No steps logged yet.
        </p>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">Workouts per week</h2>
        <ProgressChart
          type="bar"
          label="Workouts"
          :labels="workoutLabels"
          :values="workoutValues"
          color="#a855f7"
        />
      </section>
    </div>
  </div>
</template>
