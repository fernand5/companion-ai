<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted } from 'vue'

import ActivityQuickLogForm from '@/components/ActivityQuickLogForm.vue'
import ErrorNotice from '@/components/ErrorNotice.vue'
import WeightLogForm from '@/components/WeightLogForm.vue'
import WorkoutLogForm from '@/components/WorkoutLogForm.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useActivityStore } from '@/stores/activity'
import { activityIcon, activityLabel, activitySummary, formatDate } from '@/utils/format'

const activityStore = useActivityStore()

onMounted(() => {
  activityStore.load()
})

const timeline = computed(() =>
  [...activityStore.logs].sort((a, b) => (a.logged_date < b.logged_date ? 1 : -1)),
)
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <h1 class="text-lg font-semibold text-slate-900">Activity</h1>

    <div class="grid gap-4 sm:grid-cols-2">
      <ActivityQuickLogForm />
      <div class="space-y-4">
        <WorkoutLogForm />
        <WeightLogForm @logged="activityStore.load()" />
      </div>
    </div>

    <section>
      <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
        <FontAwesomeIcon :icon="icons.history" :class="[ICON_SIZE.sm, 'text-slate-400']" />
        History
      </h2>
      <p v-if="activityStore.loading" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        Loading…
      </p>
      <ErrorNotice
        v-else-if="activityStore.error"
        :message="activityStore.error"
        :on-retry="activityStore.load"
      />
      <p v-else-if="timeline.length === 0" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.activityFallback" :class="ICON_SIZE.sm" />
        Nothing logged yet.
      </p>
      <ul v-else class="space-y-2">
        <li
          v-for="log in timeline"
          :key="log.id"
          class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm"
        >
          <div class="flex items-center gap-3">
            <FontAwesomeIcon :icon="activityIcon(log.type)" :class="[ICON_SIZE.sm, 'text-slate-500']" fixed-width />
            <div>
              <p class="font-medium text-slate-800">{{ activityLabel(log.type) }}</p>
              <p class="text-xs text-slate-500">{{ formatDate(log.logged_date) }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-slate-500">{{ activitySummary(log) }}</span>
            <button
              type="button"
              class="text-slate-300 hover:text-red-500"
              aria-label="Delete activity log"
              @click="activityStore.remove(log.id)"
            >
              <FontAwesomeIcon :icon="icons.action.delete" :class="ICON_SIZE.sm" />
            </button>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
