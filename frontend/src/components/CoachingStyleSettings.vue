<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, ref, watch } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import {
  COACH_SAMPLES,
  COACH_STYLES,
  RESPONSE_DETAILS,
  SAMPLE_PROMPT,
  coachStyleLabel,
  responseDetailLabel,
} from '@/constants/coaching'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProfileStore } from '@/stores/profile'
import type { CoachStyle, ResponseDetail } from '@/types'

const store = useProfileStore()

// What the user has picked but not saved yet. It starts from, and follows, what is saved.
const style = ref<CoachStyle>(store.coaching.coach_style)
const detail = ref<ResponseDetail>(store.coaching.response_detail)
const justSaved = ref(false)

watch(
  () => store.coaching,
  (saved) => {
    style.value = saved.coach_style
    detail.value = saved.response_detail
  },
)

const dirty = computed(
  () => style.value !== store.coaching.coach_style || detail.value !== store.coaching.response_detail,
)

const sample = computed(() => COACH_SAMPLES[style.value][detail.value])

async function save() {
  justSaved.value = false

  if (await store.saveCoaching({ coach_style: style.value, response_detail: detail.value })) {
    justSaved.value = true
    setTimeout(() => (justSaved.value = false), 2500)
  }
}

function optionClasses(selected: boolean) {
  return selected
    ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600'
    : 'border-slate-200 bg-white hover:border-slate-300'
}
</script>

<template>
  <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-4" aria-labelledby="coaching-heading">
    <div>
      <h2 id="coaching-heading" class="flex items-center gap-2 text-base font-semibold text-slate-900">
        <FontAwesomeIcon :icon="icons.ai" :class="ICON_SIZE.md" />
        Your coach
      </h2>
      <p class="mt-1 text-sm text-slate-500">
        Choose how your coach talks to you. This changes the wording only: your plan, your recovery and the safety
        guidance stay the same.
      </p>
      <p class="mt-2 text-sm text-slate-600" data-testid="saved-values">
        Saved:
        <span class="font-medium text-slate-900">{{ coachStyleLabel(store.coaching.coach_style) }}</span>
        style,
        <span class="font-medium text-slate-900">{{ responseDetailLabel(store.coaching.response_detail) }}</span>
        detail
      </p>
    </div>

    <fieldset :disabled="store.savingCoaching" class="space-y-2">
      <legend class="text-sm font-medium text-slate-700">Coaching style</legend>
      <div class="grid gap-2 sm:grid-cols-3">
        <label
          v-for="option in COACH_STYLES"
          :key="option.value"
          class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm focus-within:ring-2 focus-within:ring-brand-500"
          :class="optionClasses(style === option.value)"
        >
          <span class="flex items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ option.label }}</span>
            <span
              v-if="store.coaching.coach_style === option.value"
              class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
              data-testid="saved-badge"
              >Saved</span
            >
          </span>
          <span class="text-slate-500">{{ option.description }}</span>
          <input v-model="style" type="radio" name="coach-style" class="sr-only" :value="option.value" />
        </label>
      </div>
    </fieldset>

    <fieldset :disabled="store.savingCoaching" class="space-y-2">
      <legend class="text-sm font-medium text-slate-700">Response detail</legend>
      <div class="grid gap-2 sm:grid-cols-3">
        <label
          v-for="option in RESPONSE_DETAILS"
          :key="option.value"
          class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm focus-within:ring-2 focus-within:ring-brand-500"
          :class="optionClasses(detail === option.value)"
        >
          <span class="flex items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ option.label }}</span>
            <span
              v-if="store.coaching.response_detail === option.value"
              class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
              data-testid="saved-badge"
              >Saved</span
            >
          </span>
          <span class="text-slate-500">{{ option.description }}</span>
          <input v-model="detail" type="radio" name="response-detail" class="sr-only" :value="option.value" />
        </label>
      </div>
      <p class="text-xs text-slate-500">
        Detail changes length only. Pain and safety cautions, and what hasn’t been recorded, are never left out.
      </p>
    </fieldset>

    <div class="space-y-2 rounded-lg bg-slate-50 p-3 text-sm" data-testid="sample">
      <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Example reply</p>
      <p class="text-slate-500">You: “{{ SAMPLE_PROMPT }}”</p>
      <p class="text-slate-800">Coach: “{{ sample }}”</p>
      <p class="text-xs text-slate-400">A fixed example, not written by the AI.</p>
    </div>

    <ErrorNotice v-if="store.coachingError" :message="store.coachingError" :on-retry="save" />

    <button
      type="button"
      class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300"
      :disabled="!dirty || store.savingCoaching"
      @click="save"
    >
      <FontAwesomeIcon
        :icon="store.savingCoaching ? icons.loading : icons.action.save"
        :class="ICON_SIZE.sm"
        :spin="store.savingCoaching"
      />
      {{ store.savingCoaching ? 'Saving…' : 'Save coaching style' }}
    </button>
    <p v-if="justSaved" class="flex items-center justify-center gap-1.5 text-center text-sm text-brand-600" role="status">
      <FontAwesomeIcon :icon="icons.planStatus.completed" :class="ICON_SIZE.sm" />
      Saved.
    </p>
  </section>
</template>
