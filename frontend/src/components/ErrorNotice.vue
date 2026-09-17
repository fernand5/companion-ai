<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

import { ICON_SIZE, icons } from '@/constants/icons'

defineProps<{
  message: string
  onRetry?: () => void
}>()
</script>

<template>
  <div class="flex items-center gap-2 text-sm text-red-600">
    <FontAwesomeIcon :icon="icons.warning" :class="[ICON_SIZE.sm, 'shrink-0']" />
    <span class="flex-1">{{ message }}</span>
    <button
      v-if="onRetry"
      type="button"
      class="shrink-0 font-medium underline decoration-red-300 underline-offset-2 hover:text-red-700"
      @click="onRetry"
    >
      Try again
    </button>
  </div>
</template>
