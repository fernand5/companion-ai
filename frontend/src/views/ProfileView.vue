<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { onMounted, reactive, watch } from 'vue'

import ErrorNotice from '@/components/ErrorNotice.vue'
import ScheduleEditor from '@/components/ScheduleEditor.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useProfileStore } from '@/stores/profile'

const store = useProfileStore()

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
      Profile
    </h1>

    <div v-if="store.loading" class="flex items-center justify-center gap-2 py-12 text-center text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.md" spin />
      Loading profile…
    </div>

    <div v-else-if="store.error" class="rounded-xl border border-slate-200 bg-white p-4">
      <ErrorNotice :message="store.error" :on-retry="store.load" />
    </div>

    <form v-else class="space-y-4 rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="submit">
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm text-slate-600">
          Height (cm)
          <input
            v-model.number="form.height_cm"
            type="number"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          Weight (kg)
          <input
            v-model.number="form.weight_kg"
            type="number"
            step="0.1"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          Age
          <input
            v-model.number="form.age"
            type="number"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          Fitness level
          <select
            v-model="form.fitness_level"
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          >
            <option value="">—</option>
            <option value="beginner">Beginner</option>
            <option value="intermediate">Intermediate</option>
            <option value="advanced">Advanced</option>
          </select>
        </label>
      </div>

      <label class="block text-sm text-slate-600">
        Primary goal
        <input
          v-model="form.primary_goal"
          type="text"
          placeholder="e.g. Lose body fat while maintaining muscle"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        Secondary goal
        <input
          v-model="form.secondary_goal"
          type="text"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        Equipment (comma separated)
        <input
          v-model="form.equipment"
          type="text"
          placeholder="dumbbells, exercise mat, treadmill"
          class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>

      <label class="block text-sm text-slate-600">
        Preferred session length (minutes)
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
        Save profile
      </button>
      <p v-if="saved.show" class="flex items-center justify-center gap-1.5 text-center text-sm text-brand-600">
        <FontAwesomeIcon :icon="icons.planStatus.completed" :class="ICON_SIZE.sm" />
        Saved.
      </p>
    </form>

    <ScheduleEditor v-if="!store.loading && !store.error" />
  </div>
</template>
