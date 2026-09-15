<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { ref } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import * as weightService from '@/services/weight'

const emit = defineEmits<{ logged: [] }>()

const weight = ref<number | null>(null)
const submitting = ref(false)

async function submit() {
  if (weight.value === null) return

  submitting.value = true

  try {
    await weightService.logWeight({ weight_kg: weight.value })
    weight.value = null
    emit('logged')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form
    class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-4"
    @submit.prevent="submit"
  >
    <label class="flex items-center gap-1.5 text-sm font-medium whitespace-nowrap text-slate-700" for="weight_kg">
      <FontAwesomeIcon :icon="icons.activityType.weight" :class="[ICON_SIZE.sm, 'text-slate-400']" />
      Update weight (kg)
    </label>
    <input
      id="weight_kg"
      v-model.number="weight"
      type="number"
      step="0.1"
      min="20"
      class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
    />
    <button
      type="submit"
      :disabled="submitting || weight === null"
      class="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
    >
      <FontAwesomeIcon :icon="icons.action.save" :class="ICON_SIZE.xs" />
      Save
    </button>
  </form>
</template>
