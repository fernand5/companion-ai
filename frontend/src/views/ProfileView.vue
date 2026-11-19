<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { onMounted, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import CoachingStyleSettings from '@/components/CoachingStyleSettings.vue'
import ErrorNotice from '@/components/ErrorNotice.vue'
import LanguageSelector from '@/components/LanguageSelector.vue'
import ScheduleEditor from '@/components/ScheduleEditor.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProfileStore } from '@/stores/profile'

const store = useProfileStore()
const { t } = useI18n()

const form = reactive({
  height_cm: null as number | null,
  weight_kg: null as number | null,
  age: null as number | null,
  fitness_level: '' as string,
  primary_goal: '',
  secondary_goal: '',
  equipment: '',
  preferred_training_duration_minutes: null as number | null,
})

onMounted(async () => {
  await store.load()
})

watch(
  () => store.profile,
  (profile) => {
    if (!profile) return
    form.height_cm = profile.height_cm
    form.weight_kg = profile.weight_kg
    form.age = profile.age
    form.fitness_level = profile.fitness_level ?? ''
    form.primary_goal = profile.primary_goal ?? ''
    form.secondary_goal = profile.secondary_goal ?? ''
    form.equipment = (profile.equipment ?? []).join(', ')
    form.preferred_training_duration_minutes = profile.preferred_training_duration_minutes
  },
  { immediate: true },
)

const saved = reactive({ show: false })

async function submit() {
  await store.save({
    height_cm: form.height_cm ?? undefined,
    weight_kg: form.weight_kg ?? undefined,
    age: form.age ?? undefined,
    fitness_level: form.fitness_level || undefined,
    primary_goal: form.primary_goal || undefined,
    secondary_goal: form.secondary_goal || undefined,
    equipment: form.equipment
      ? form.equipment.split(',').map((e) => e.trim()).filter(Boolean)
      : [],
    preferred_training_duration_minutes: form.preferred_training_duration_minutes ?? undefined,
  })

  saved.show = true
  setTimeout(() => (saved.show = false), 2000)
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <h1 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
      <FontAwesomeIcon :icon="icons.nav.profile" :class="ICON_SIZE.md" />
      {{ t('profile.title') }}
    </h1>

    <!-- Outside the profile loading/error states: the language works even before a profile exists. -->
    <div class="rounded-xl border border-slate-200 bg-white p-4">
      <LanguageSelector scope="account" />
    </div>

    <div v-if="store.loading" class="flex items-center justify-center gap-2 py-12 text-center text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.md" spin />
      {{ t('profile.loading') }}
    </div>

    <div v-else-if="store.error" class="rounded-xl border border-slate-200 bg-white p-4">
      <ErrorNotice :message="store.error" :on-retry="store.load" />
    </div>

    <form v-else class="space-y-4 rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="submit">
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm text-slate-600">
          {{ t('profile.height') }}
          <input
            v-model.number="form.height_cm"
            type="number"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          {{ t('profile.weight') }}
          <input
            v-model.number="form.weight_kg"
            type="number"
            step="0.1"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          {{ t('profile.age') }}
          <input
            v-model.number="form.age"
            type="number"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          {{ t('profile.fitnessLevel') }}
          <select
            v-model="form.fitness_level"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          >
            <option value="">—</option>
            <option value="beginner">{{ t('profile.levels.beginner') }}</option>
            <option value="intermediate">{{ t('profile.levels.intermediate') }}</option>
            <option value="advanced">{{ t('profile.levels.advanced') }}</option>
          </select>
        </label>
      </div>

      <label class="block text-sm text-slate-600">
        {{ t('profile.primaryGoal') }}
        <input
          v-model="form.primary_goal"
          type="text"
          :placeholder="t('profile.primaryGoalPlaceholder')"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        {{ t('profile.secondaryGoal') }}
        <input
          v-model="form.secondary_goal"
          type="text"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        {{ t('profile.equipment') }}
        <input
          v-model="form.equipment"
          type="text"
          :placeholder="t('profile.equipmentPlaceholder')"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        {{ t('profile.sessionLength') }}
        <input
          v-model.number="form.preferred_training_duration_minutes"
          type="number"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <button
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
      >
        <FontAwesomeIcon :icon="icons.action.save" :class="ICON_SIZE.sm" />
        {{ t('profile.save') }}
      </button>
      <p v-if="saved.show" class="flex items-center justify-center gap-1.5 text-center text-sm text-brand-600">
        <FontAwesomeIcon :icon="icons.planStatus.completed" :class="ICON_SIZE.sm" />
        {{ t('profile.saved') }}
      </p>
    </form>

    <CoachingStyleSettings v-if="!store.loading && !store.error" />

    <ScheduleEditor v-if="!store.loading && !store.error" />
  </div>
</template>
