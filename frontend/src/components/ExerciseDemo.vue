<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onUnmounted, ref } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'
import { useExerciseImageStore } from '@/stores/exerciseImages'

const props = defineProps<{
  planId: number
  exerciseId: number
  exerciseName: string
  /** Already-generated image from the plan payload, if any. */
  imageUrl: string | null
}>()

const store = useExerciseImageStore()
const entry = computed(() => store.entryFor(props.exerciseId))
const brokenImage = ref(false)

const url = computed(() => props.imageUrl ?? (entry.value?.state === 'ready' ? entry.value.url : null))
const showImage = computed(() => url.value !== null && !brokenImage.value)
const unavailable = computed(() => brokenImage.value || entry.value?.state === 'failed')

onUnmounted(() => store.cancel(props.exerciseId))
</script>

<template>
  <div>
    <img
      v-if="showImage"
      :src="url!"
      :alt="`${exerciseName} demonstration`"
      loading="lazy"
      class="w-full rounded-lg border border-slate-200 bg-slate-50 object-cover"
      @error="brokenImage = true"
    />
    <p v-else-if="entry?.state === 'loading'" class="flex items-center gap-1.5 text-xs text-slate-400">
      <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.xs" spin />
      Generating exercise demonstration…
    </p>
    <p v-else-if="unavailable" class="text-xs text-slate-400">Demo unavailable right now.</p>
    <button
      v-else
      type="button"
      class="flex items-center gap-1 text-xs text-slate-400 hover:text-brand-600"
      @click="store.request(planId, exerciseId)"
    >
      <FontAwesomeIcon :icon="icons.demo" :class="ICON_SIZE.xs" />
      Show demo
    </button>
  </div>
</template>
