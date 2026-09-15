<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { ref } from 'vue'

import { ICON_SIZE, icons } from '@/constants/icons'

const props = defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ send: [content: string] }>()

const draft = ref('')

function submit() {
  const content = draft.value.trim()
  if (!content || props.disabled) return

  emit('send', content)
  draft.value = ''
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    submit()
  }
}
</script>

<template>
  <form class="flex items-end gap-2" @submit.prevent="submit">
    <textarea
      v-model="draft"
      rows="1"
      placeholder="Tell your coach what you did, or ask what's next…"
      class="max-h-32 flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
      @keydown="handleKeydown"
    />
    <button
      type="submit"
      :disabled="disabled || !draft.trim()"
      class="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
    >
      <FontAwesomeIcon :icon="icons.action.send" :class="ICON_SIZE.sm" />
      Send
    </button>
  </form>
</template>
