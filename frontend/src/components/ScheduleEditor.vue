<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { reactive } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useProfileStore } from '@/stores/profile'
import { dayName, formatTime } from '@/utils/format'

const store = useProfileStore()

const form = reactive({
  activity_type: 'Football',
  day_of_week: 2,
  start_time: '20:00',
  expected_duration_minutes: 90,
  intensity: 'high' as 'low' | 'moderate' | 'high',
})

async function addEntry() {
  await store.addScheduleEntry({ ...form, notes: null })
  form.activity_type = ''
}
</script>

<template>
  <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
      <FontAwesomeIcon :icon="icons.calendar" :class="[ICON_SIZE.sm, 'text-slate-400']" />
      Recurring schedule
    </h2>

    <ul class="space-y-1">
      <li
        v-for="entry in store.schedule"
        :key="entry.id"
        class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm"
      >
        <span>
          <strong class="font-medium text-slate-800">{{ entry.activity_type }}</strong>
          — {{ dayName(entry.day_of_week) }} {{ formatTime(entry.start_time) }}
        </span>
        <button
          type="button"
          class="text-slate-400 hover:text-red-500"
          :aria-label="`Remove ${entry.activity_type} on ${dayName(entry.day_of_week)}`"
          @click="store.removeScheduleEntry(entry.id)"
        >
          <FontAwesomeIcon :icon="icons.action.delete" :class="ICON_SIZE.xs" />
        </button>
      </li>
      <li v-if="store.schedule.length === 0" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.calendar" :class="ICON_SIZE.sm" />
        No recurring activities yet.
      </li>
    </ul>

    <form class="flex flex-wrap items-center gap-2 pt-2" @submit.prevent="addEntry">
      <input
        v-model="form.activity_type"
        type="text"
        placeholder="Activity (e.g. Football)"
        required
        class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <select v-model.number="form.day_of_week" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option v-for="d in 7" :key="d" :value="d - 1">{{ dayName(d - 1) }}</option>
      </select>
      <input
        v-model="form.start_time"
        type="time"
        class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <input
        v-model.number="form.expected_duration_minutes"
        type="number"
        class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <select v-model="form.intensity" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option value="low">Low</option>
        <option value="moderate">Moderate</option>
        <option value="high">High</option>
      </select>
      <button
        type="submit"
        class="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700"
      >
        <FontAwesomeIcon :icon="icons.action.add" :class="ICON_SIZE.xs" />
        Add
      </button>
    </form>
  </div>
</template>
