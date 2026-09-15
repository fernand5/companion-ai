<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, reactive } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useActivityStore } from '@/stores/activity'

const TYPE_ICON = {
  steps: icons.activityType.steps,
  treadmill: icons.activityType.treadmill,
  sport: icons.activityType.sport,
} as const

const activityStore = useActivityStore()

const form = reactive({
  type: 'steps' as 'steps' | 'treadmill' | 'sport',
  steps: 6000,
  duration_minutes: 20,
  intensity: 'moderate' as 'low' | 'moderate' | 'high',
  sport: 'Football',
  notes: '',
})

const submitting = computed(() => activityStore.loading)

async function submit() {
  const metadata: Record<string, unknown> = {}

  if (form.type === 'steps') metadata.steps = form.steps
  if (form.type === 'sport') metadata.sport = form.sport

  await activityStore.log({
    type: form.type,
    duration_minutes: form.type === 'steps' ? undefined : form.duration_minutes,
    intensity: form.type === 'steps' ? undefined : form.intensity,
    notes: form.notes || undefined,
    metadata,
  })

  form.notes = ''
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
        {{ option }}
      </button>
    </div>

    <div v-if="form.type === 'steps'" class="flex items-center gap-2">
      <label class="text-sm text-slate-600" for="steps">Steps</label>
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
        Minutes
        <input
          v-model.number="form.duration_minutes"
          type="number"
          min="0"
          class="ml-1 w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        Intensity
        <select v-model="form.intensity" class="ml-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="low">Low</option>
          <option value="moderate">Moderate</option>
          <option value="high">High</option>
        </select>
      </label>
    </div>

    <div v-if="form.type === 'sport'" class="flex flex-wrap items-center gap-3">
      <label class="text-sm text-slate-600">
        Sport
        <input
          v-model="form.sport"
          type="text"
          class="ml-1 w-32 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        Minutes
        <input
          v-model.number="form.duration_minutes"
          type="number"
          min="0"
          class="ml-1 w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm text-slate-600">
        Intensity
        <select v-model="form.intensity" class="ml-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="low">Low</option>
          <option value="moderate">Moderate</option>
          <option value="high">High</option>
        </select>
      </label>
    </div>

    <input
      v-model="form.notes"
      type="text"
      placeholder="Notes (optional)"
      class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
    />

    <button
      type="submit"
      :disabled="submitting"
      class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
    >
      <FontAwesomeIcon :icon="submitting ? icons.loading : icons.action.add" :class="ICON_SIZE.sm" :spin="submitting" />
      Log it
    </button>
  </form>
</template>
