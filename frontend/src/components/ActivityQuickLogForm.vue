<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { DEFAULT_SPORT } from '@/constants/activity'
import { ICON_SIZE, icons } from '@/constants/icons'
import { formatDecimal } from '@/i18n/format'
import { useActivityStore } from '@/stores/activity'
import { activityLabel, formatPace } from '@/utils/format'

const TYPE_ICON = {
  steps: icons.activityType.steps,
  treadmill: icons.activityType.treadmill,
  sport: icons.activityType.sport,
} as const

const { t } = useI18n()
const activityStore = useActivityStore()

const form = reactive({
  type: 'steps' as 'steps' | 'treadmill' | 'sport',
  steps: 6000,
  duration_minutes: 20,
  distance_km: null as number | null,
  intensity: 'moderate' as 'low' | 'moderate' | 'high',
  notes: '',
})

// The suggested sport is SHOWN in the language on screen, but accepting it unchanged stores the canonical value
// (DEFAULT_SPORT). Once the person types, the text is theirs and is stored exactly as typed.
const typedSport = ref<string | null>(null)
const sport = computed({
  get: () => typedSport.value ?? t('activity.quickLog.defaultSport'),
  set: (value: string) => (typedSport.value = value),
})

const submitting = computed(() => activityStore.loading)

/** Average speed and pace, previewed from what is typed (the server derives the stored values). */
const cardioPreview = computed(() => {
  const km = form.distance_km
  const minutes = form.duration_minutes

  if (form.type !== 'treadmill' || !km || km <= 0 || !minutes || minutes <= 0) return null

  return `${formatDecimal(km / (minutes / 60), { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km/h · ${formatPace((minutes * 60) / km)}`
})

async function submit() {
  const metadata: Record<string, unknown> = {}

  if (form.type === 'steps') metadata.steps = form.steps
  if (form.type === 'sport') metadata.sport = typedSport.value ?? DEFAULT_SPORT

  await activityStore.log({
    type: form.type,
    duration_minutes: form.type === 'steps' ? undefined : form.duration_minutes,
    distance_km: form.type === 'treadmill' && form.distance_km ? form.distance_km : undefined,
    intensity: form.type === 'steps' ? undefined : form.intensity,
    notes: form.notes || undefined,
    metadata,
  })

  form.notes = ''
  form.distance_km = null
}
</script>

<template>
  <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="submit">
    <div class="flex flex-wrap gap-2">
      <button
        v-for="option in ['steps', 'treadmill', 'sport'] as const"
        :key="option"
        type="button"
        class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium capitalize"
        :class="
          form.type === option
            ? 'bg-brand-600 text-white'
            : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
        "
        @click="form.type = option"
      >
        <FontAwesomeIcon :icon="TYPE_ICON[option]" :class="ICON_SIZE.xs" />
        {{ activityLabel(option) }}
      </button>
    </div>

    <div v-if="form.type === 'steps'" class="flex items-center gap-2">
      <label class="text-sm text-slate-600" for="steps">{{ t('activity.quickLog.steps') }}</label>
      <input
        id="steps"
        v-model.number="form.steps"
        type="number"
        min="0"
        class="w-32 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
      />
    </div>

    <div v-if="form.type === 'treadmill'" class="flex flex-wrap items-center gap-3">
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.minutes') }}
        <input
          v-model.number="form.duration_minutes"
          type="number"
          min="0"
          class="ml-1 w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.distance') }}
        <input
          v-model.number="form.distance_km"
          type="number"
          inputmode="decimal"
          min="0"
          step="0.1"
          :placeholder="t('activity.quickLog.optional')"
          class="ml-1 w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.intensity') }}
        <select v-model="form.intensity" class="ml-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="low">{{ t('enums.intensity.low') }}</option>
          <option value="moderate">{{ t('enums.intensity.moderate') }}</option>
          <option value="high">{{ t('enums.intensity.high') }}</option>
        </select>
      </label>
      <p v-if="cardioPreview" class="w-full text-xs text-slate-500" data-testid="cardio-preview">{{ cardioPreview }}</p>
    </div>

    <div v-if="form.type === 'sport'" class="flex flex-wrap items-center gap-3">
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.sport') }}
        <input
          v-model="sport"
          type="text"
          class="ml-1 w-32 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.minutes') }}
        <input
          v-model.number="form.duration_minutes"
          type="number"
          min="0"
          class="ml-1 w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        {{ t('activity.quickLog.intensity') }}
        <select v-model="form.intensity" class="ml-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="low">{{ t('enums.intensity.low') }}</option>
          <option value="moderate">{{ t('enums.intensity.moderate') }}</option>
          <option value="high">{{ t('enums.intensity.high') }}</option>
        </select>
      </label>
    </div>

    <input
      v-model="form.notes"
      type="text"
      :placeholder="t('activity.quickLog.notes')"
      class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
    />

    <button
      type="submit"
      :disabled="submitting"
      class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
    >
      <FontAwesomeIcon :icon="submitting ? icons.loading : icons.action.add" :class="ICON_SIZE.sm" :spin="submitting" />
      {{ t('activity.quickLog.submit') }}
    </button>
  </form>
</template>
