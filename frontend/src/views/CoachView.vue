<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { onMounted, ref, watch } from 'vue'

import ChatBubble from '@/components/ChatBubble.vue'
import ChatInput from '@/components/ChatInput.vue'
import QuickActions from '@/components/QuickActions.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useAutoScroll } from '@/composables/useAutoScroll'
import { useChatStore } from '@/stores/chat'

const chat = useChatStore()
const scrollContainer = ref<HTMLElement | null>(null)
const { scrollToBottom } = useAutoScroll(scrollContainer)

onMounted(async () => {
  await chat.ensureActiveConversation()
  scrollToBottom()
})

watch(
  () => chat.messages.length,
  () => scrollToBottom(),
)

async function handleSend(content: string) {
  await chat.send(content)
}
</script>

<template>
  <div class="mx-auto flex h-full max-w-2xl flex-col p-4 sm:p-6">
    <h1 class="mb-3 text-lg font-semibold text-slate-900">Coach</h1>

    <div ref="scrollContainer" class="flex-1 space-y-3 overflow-y-auto pb-2">
      <p v-if="chat.loadingMessages" class="flex items-center justify-center gap-1.5 text-center text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        Loading…
      </p>
      <p
        v-else-if="chat.messages.length === 0"
        class="flex flex-col items-center gap-2 pt-8 text-center text-sm text-slate-400"
      >
        <FontAwesomeIcon :icon="icons.ai" :class="ICON_SIZE.lg" />
        Say hello, or ask what to do today.
      </p>
      <ChatBubble v-for="message in chat.messages" :key="message.id" :message="message" />
      <p v-if="chat.sending" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        Coach is thinking…
      </p>
      <p v-if="chat.error" class="flex items-center gap-1.5 text-sm text-red-600">
        <FontAwesomeIcon :icon="icons.warning" :class="ICON_SIZE.sm" />
        {{ chat.error }}
      </p>
    </div>

    <div class="mt-3 space-y-2 border-t border-slate-200 pt-3">
      <QuickActions @pick="handleSend" />
      <ChatInput :disabled="chat.sending" @send="handleSend" />
    </div>
  </div>
</template>
