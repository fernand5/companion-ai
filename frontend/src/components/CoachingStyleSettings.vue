<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import ErrorNotice from '@/components/ErrorNotice.vue'
import { COACH_STYLE_VALUES, RESPONSE_DETAIL_VALUES } from '@/constants/coaching'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProfileStore } from '@/stores/profile'
import type { CoachStyle, ResponseDetail } from '@/types'

const store = useProfileStore()
const { t } = useI18n()

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

// Every word on this card is looked up when it is drawn, so the whole card (labels, descriptions, the example) follows the language.
const sample = computed(() => t(`coaching.samples.${style.value}.${detail.value}`))

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
        {{ t('coaching.title') }}
      </h2>
      <p class="mt-1 text-sm text-slate-500">{{ t('coaching.intro') }}</p>
      <i18n-t keypath="coaching.savedValues" tag="p" class="mt-2 text-sm text-slate-600" data-testid="saved-values">
        <template #style>
          <span class="font-medium text-slate-900">{{ t(`coaching.styles.${store.coaching.coach_style}.label`) }}</span>
        </template>
        <template #detail>
          <span class="font-medium text-slate-900">{{ t(`coaching.details.${store.coaching.response_detail}.label`) }}</span>
        </template>
      </i18n-t>
    </div>

    <fieldset :disabled="store.savingCoaching" class="space-y-2">
      <legend class="text-sm font-medium text-slate-700">{{ t('coaching.styleLegend') }}</legend>
      <div class="grid gap-2 sm:grid-cols-3">
        <label
          v-for="option in COACH_STYLE_VALUES"
          :key="option"
          class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm focus-within:ring-2 focus-within:ring-brand-500"
          :class="optionClasses(style === option)"
        >
          <span class="flex items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ t(`coaching.styles.${option}.label`) }}</span>
            <span
              v-if="store.coaching.coach_style === option"
              class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
              data-testid="saved-badge"
              >{{ t('coaching.savedBadge') }}</span
            >
          </span>
          <span class="text-slate-500">{{ t(`coaching.styles.${option}.description`) }}</span>
          <input v-model="style" type="radio" name="coach-style" class="sr-only" :value="option" />
        </label>
      </div>
    </fieldset>

    <fieldset :disabled="store.savingCoaching" class="space-y-2">
      <legend class="text-sm font-medium text-slate-700">{{ t('coaching.detailLegend') }}</legend>
      <div class="grid gap-2 sm:grid-cols-3">
        <label
          v-for="option in RESPONSE_DETAIL_VALUES"
          :key="option"
          class="flex cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm focus-within:ring-2 focus-within:ring-brand-500"
          :class="optionClasses(detail === option)"
        >
          <span class="flex items-center justify-between gap-2">
            <span class="font-medium text-slate-900">{{ t(`coaching.details.${option}.label`) }}</span>
            <span
              v-if="store.coaching.response_detail === option"
              class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
              data-testid="saved-badge"
              >{{ t('coaching.savedBadge') }}</span
            >
          </span>
          <span class="text-slate-500">{{ t(`coaching.details.${option}.description`) }}</span>
          <input v-model="detail" type="radio" name="response-detail" class="sr-only" :value="option" />
        </label>
      </div>
      <p class="text-xs text-slate-500">{{ t('coaching.detailNote') }}</p>
    </fieldset>

    <div class="space-y-2 rounded-lg bg-slate-50 p-3 text-sm" data-testid="sample">
      <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ t('coaching.example.title') }}</p>
      <p class="text-slate-500">{{ t('coaching.example.you', { prompt: t('coaching.samplePrompt') }) }}</p>
      <p class="text-slate-800">{{ t('coaching.example.coach', { reply: sample }) }}</p>
      <p class="text-xs text-slate-400">{{ t('coaching.example.fixed') }}</p>
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
      {{ store.savingCoaching ? t('coaching.saving') : t('coaching.save') }}
    </button>
    <p v-if="justSaved" class="flex items-center justify-center gap-1.5 text-center text-sm text-brand-600" role="status">
      <FontAwesomeIcon :icon="icons.planStatus.completed" :class="ICON_SIZE.sm" />
      {{ t('coaching.saved') }}
    </p>
  </section>
</template>
