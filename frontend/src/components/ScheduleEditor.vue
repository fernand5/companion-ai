<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ICON_SIZE, icons } from '@/constants/icons'
import { DEFAULT_SCHEDULE_ACTIVITY } from '@/constants/schedule'
import { useProfileStore } from '@/stores/profile'
import { dayName, formatTime } from '@/utils/format'

const store = useProfileStore()
const { t } = useI18n()

// The suggested activity is SHOWN in the language on screen, but what is stored is the canonical value: accepting the
// suggestion unchanged saves DEFAULT_SCHEDULE_ACTIVITY whatever the language, because the server identifies an entry by
// its activity name. Once the person types, the text is theirs and is saved exactly as typed.
const typedActivity = ref<string | null>(null)
const activity = computed({
  get: () => typedActivity.value ?? t('profile.schedule.defaultActivity'),
  set: (value: string) => (typedActivity.value = value),
})

/** How a stored activity name is shown: the canonical suggestion in the language on screen, anything else as saved. */
function activityLabel(stored: string): string {
  return stored === DEFAULT_SCHEDULE_ACTIVITY ? t('profile.schedule.defaultActivity') : stored
}

const form = reactive({
  day_of_week: 2,
  start_time: '20:00',
  expected_duration_minutes: 90,
  intensity: 'high' as 'low' | 'moderate' | 'high',
})

async function addEntry() {
  await store.addScheduleEntry({ ...form, activity_type: typedActivity.value ?? DEFAULT_SCHEDULE_ACTIVITY, notes: null })
  typedActivity.value = ''
}
</script>

<template>
  <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
      <FontAwesomeIcon :icon="icons.calendar" :class="[ICON_SIZE.sm, 'text-slate-400']" />
      {{ t('profile.schedule.title') }}
    </h2>

    <ul class="space-y-1">
      <li
        v-for="entry in store.schedule"
        :key="entry.id"
        class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm"
      >
        <span>
          <strong class="font-medium text-slate-800">{{ activityLabel(entry.activity_type) }}</strong>
          — {{ dayName(entry.day_of_week) }} {{ formatTime(entry.start_time) }}
        </span>
        <button
          type="button"
          class="text-slate-400 hover:text-red-500"
          :aria-label="t('a11y.removeScheduleEntry', { activity: activityLabel(entry.activity_type), day: dayName(entry.day_of_week) })"
          @click="store.removeScheduleEntry(entry.id)"
        >
          <FontAwesomeIcon :icon="icons.action.delete" :class="ICON_SIZE.xs" />
        </button>
      </li>
      <li v-if="store.schedule.length === 0" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.calendar" :class="ICON_SIZE.sm" />
        {{ t('profile.schedule.empty') }}
      </li>
    </ul>

    <form class="flex flex-wrap items-center gap-2 pt-2" @submit.prevent="addEntry">
      <input
        v-model="activity"
        type="text"
        :placeholder="t('profile.schedule.activityPlaceholder')"
        required
        class="min-w-[10rem] flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <select v-model.number="form.day_of_week" :aria-label="t('a11y.scheduleDay')" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option v-for="d in 7" :key="d" :value="d - 1">{{ dayName(d - 1) }}</option>
      </select>
      <input
        v-model="form.start_time"
        type="time"
        :aria-label="t('a11y.scheduleStartTime')"
        class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <input
        v-model.number="form.expected_duration_minutes"
        type="number"
        :aria-label="t('a11y.scheduleDuration')"
        class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
      />
      <select v-model="form.intensity" :aria-label="t('a11y.scheduleIntensity')" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option value="low">{{ t('profile.schedule.intensity.low') }}</option>
        <option value="moderate">{{ t('profile.schedule.intensity.moderate') }}</option>
        <option value="high">{{ t('profile.schedule.intensity.high') }}</option>
      </select>
      <button
        type="submit"
        class="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700"
      >
        <FontAwesomeIcon :icon="icons.action.add" :class="ICON_SIZE.xs" />
        {{ t('profile.schedule.add') }}
      </button>
    </form>
  </div>
</template>
