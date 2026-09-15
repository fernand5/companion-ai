<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onMounted, reactive, ref } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useRecoveryStore } from '@/stores/recovery'

const store = useRecoveryStore()

onMounted(() => {
  store.loadToday()
})

const editing = ref(false)

const form = reactive({
  energy: 3,
  soreness: 3,
  motivation: 3,
  perceived_difficulty: null as number | null,
  pain_notes: '',
})

const checkedInToday = computed(() => store.today !== null && !editing.value)

async function submit() {
  await store.submit({
    energy: form.energy,
    soreness: form.soreness,
    motivation: form.motivation,
    perceived_difficulty: form.perceived_difficulty ?? undefined,
    pain_notes: form.pain_notes || undefined,
  })
  editing.value = false
}

function startEditing() {
  if (store.today) {
    form.energy = store.today.energy
    form.soreness = store.today.soreness
    form.motivation = store.today.motivation
    form.perceived_difficulty = store.today.perceived_difficulty
    form.pain_notes = store.today.pain_notes ?? ''
  }
  editing.value = true
}
</script>

<template>
  <div class="rounded-xl border border-slate-200 bg-white p-4">
    <div v-if="checkedInToday" class="flex items-center justify-between text-sm">
      <div>
        <p class="flex items-center gap-1.5 font-medium text-slate-800">
          <FontAwesomeIcon :icon="icons.planStatus.completed" :class="[ICON_SIZE.sm, 'text-brand-600']" />
          Checked in today
        </p>
        <p class="mt-0.5 flex items-center gap-3 text-slate-500">
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.energy" :class="ICON_SIZE.xs" />
            {{ store.today?.energy }}/5
          </span>
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.soreness" :class="ICON_SIZE.xs" />
            {{ store.today?.soreness }}/5
          </span>
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.motivation" :class="ICON_SIZE.xs" />
            {{ store.today?.motivation }}/5
          </span>
        </p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1 text-xs text-brand-600 hover:underline"
        @click="startEditing"
      >
        <FontAwesomeIcon :icon="icons.action.edit" :class="ICON_SIZE.xs" />
        Edit
      </button>
    </div>

    <form v-else class="space-y-3" @submit.prevent="submit">
      <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
        <FontAwesomeIcon :icon="icons.activityType.recovery" :class="[ICON_SIZE.sm, 'text-slate-400']" />
        How are you feeling today?
      </p>

      <div class="grid grid-cols-3 gap-3">
        <label class="text-sm text-slate-600">
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.energy" :class="ICON_SIZE.xs" />
            Energy
          </span>
          <input
            v-model.number="form.energy"
            type="number"
            min="1"
            max="5"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.soreness" :class="ICON_SIZE.xs" />
            Soreness
          </span>
          <input
            v-model.number="form.soreness"
            type="number"
            min="1"
            max="5"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <label class="text-sm text-slate-600">
          <span class="flex items-center gap-1">
            <FontAwesomeIcon :icon="icons.recoveryMetric.motivation" :class="ICON_SIZE.xs" />
            Motivation
          </span>
          <input
            v-model.number="form.motivation"
            type="number"
            min="1"
            max="5"
            required
            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
      </div>

      <input
        v-model="form.pain_notes"
        type="text"
        placeholder="Any pain or discomfort? (optional)"
        class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
      />

      <button
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
      >
        <FontAwesomeIcon :icon="icons.action.save" :class="ICON_SIZE.sm" />
        Save check-in
      </button>
    </form>
  </div>
</template>
