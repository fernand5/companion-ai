<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { computed } from 'vue'

import MarkdownText from '@/components/MarkdownText.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import type { Message } from '@/types'

const props = defineProps<{ message: Message }>()

const isUser = computed(() => props.message.role === 'user')
const toolCalls = computed(() => props.message.meta?.tool_calls ?? [])
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
      <details v-if="toolCalls.length > 0" class="group mt-1 text-xs text-slate-400">
        <summary class="flex cursor-pointer list-none items-center gap-1 select-none [&::-webkit-details-marker]:hidden">
          <FontAwesomeIcon
            :icon="icons.chevronDown"
            :class="[ICON_SIZE.xs, 'transition-transform group-open:rotate-180']"
          />
          Checked {{ toolCalls.length }} thing{{ toolCalls.length > 1 ? 's' : '' }}
        </summary>
        <ul class="mt-1 space-y-0.5 pl-3">
          <li v-for="(call, idx) in toolCalls" :key="idx">{{ call.name }}</li>
        </ul>
      </details>
    </div>
  </div>
</template>
