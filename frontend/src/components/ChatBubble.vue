<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import MarkdownText from '@/components/MarkdownText.vue'
import SaveResultCard from '@/components/SaveResultCard.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import type { Message } from '@/types'

const { t } = useI18n()
const props = defineProps<{ message: Message; busy?: boolean }>()
const emit = defineEmits<{ answer: [answer: 'yes' | 'no'] }>()

const isUser = computed(() => props.message.role === 'user')
const toolCalls = computed(() => props.message.meta?.tool_calls ?? [])
const saveResult = computed(() => props.message.meta?.save_result ?? null)

// A question is answerable only while the server says so (it checked the signed proposal when it sent this message) and its
// time has not run out here either. The server decides every answer again when it arrives; this only avoids offering a
// button that can no longer work.
const expired = ref(false)
let expiryTimer: ReturnType<typeof setTimeout> | undefined
const answerable = computed(() => props.message.confirmation?.actionable === true && !expired.value)
const lapsed = computed(() => props.message.confirmation?.actionable === true && expired.value)

onMounted(() => {
  const at = props.message.confirmation?.expires_at

  if (!at || props.message.confirmation?.actionable !== true) {
    return
  }

  const wait = Date.parse(at) - Date.now()

  if (wait <= 0) {
    expired.value = true
  } else if (wait < 2 ** 31 - 1) {
    expiryTimer = setTimeout(() => (expired.value = true), wait)
  }
})

onBeforeUnmount(() => clearTimeout(expiryTimer))
</script>

<template>
  <div class="flex min-w-0" :class="isUser ? 'justify-end' : 'justify-start'">
    <div class="min-w-0 max-w-[85%] sm:max-w-md">
      <!-- What the user typed is shown as typed. The coach's reply may use Markdown, drawn safely (never as HTML). -->
      <div
        class="min-w-0 rounded-2xl px-4 py-2 text-sm"
        :class="
          isUser
            ? 'bg-brand-600 text-white rounded-br-sm whitespace-pre-wrap break-words [overflow-wrap:anywhere]'
            : 'bg-white text-slate-800 border border-slate-200 rounded-bl-sm'
        "
        data-testid="bubble"
      >
        <template v-if="isUser">{{ message.content }}</template>
        <MarkdownText v-else :source="message.content" />
      </div>
      <SaveResultCard v-if="saveResult" :result="saveResult" />
      <div
        v-if="answerable"
        class="mt-2 flex flex-wrap gap-2"
        role="group"
        :aria-label="t('coach.confirm.group')"
        data-testid="confirmation"
      >
        <button
          type="button"
          class="min-h-[44px] flex-1 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
          :disabled="busy"
          data-testid="confirm-yes"
          @click="emit('answer', 'yes')"
        >
          {{ t('coach.confirm.yes') }}
        </button>
        <button
          type="button"
          class="min-h-[44px] flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          :disabled="busy"
          data-testid="confirm-no"
          @click="emit('answer', 'no')"
        >
          {{ t('coach.confirm.no') }}
        </button>
      </div>
      <p v-else-if="lapsed" class="mt-2 text-xs text-slate-500" data-testid="confirmation-expired">{{ t('coach.confirm.expired') }}</p>
      <details v-if="toolCalls.length > 0" class="group mt-1 text-xs text-slate-400">
        <summary class="flex cursor-pointer list-none items-center gap-1 select-none [&::-webkit-details-marker]:hidden">
          <FontAwesomeIcon
            :icon="icons.chevronDown"
            :class="[ICON_SIZE.xs, 'transition-transform group-open:rotate-180']"
          />
          {{ t('coach.checked', { count: toolCalls.length }, toolCalls.length) }}
        </summary>
        <ul class="mt-1 space-y-0.5 pl-3">
          <li v-for="(call, idx) in toolCalls" :key="idx">{{ call.name }}</li>
        </ul>
      </details>
    </div>
  </div>
</template>
