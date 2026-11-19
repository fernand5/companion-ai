<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import ChatBubble from '@/components/ChatBubble.vue'
import ChatInput from '@/components/ChatInput.vue'
import QuickActions from '@/components/QuickActions.vue'
import { ICON_SIZE, icons } from '@/constants/icons'
import { useAutoScroll } from '@/composables/useAutoScroll'
import { useChatStore } from '@/stores/chat'

const { t } = useI18n()
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

// A button sends the same short answer a person would type; the server checks the signed proposal and decides what happens.
async function handleAnswer(answer: 'yes' | 'no') {
  await chat.send(answer === 'yes' ? t('coach.confirm.yesMessage') : t('coach.confirm.noMessage'))
}
</script>

<template>
  <div class="mx-auto flex h-full max-w-2xl flex-col p-4 sm:p-6">
    <h1 class="mb-3 text-lg font-semibold text-slate-900">{{ t('coach.title') }}</h1>

    <div ref="scrollContainer" class="flex-1 space-y-3 overflow-y-auto pb-2">
      <p v-if="chat.loadingMessages" class="flex items-center justify-center gap-1.5 text-center text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        {{ t('common.loading') }}
      </p>
      <p
        v-else-if="chat.messages.length === 0"
        class="flex flex-col items-center gap-2 pt-8 text-center text-sm text-slate-400"
      >
        <FontAwesomeIcon :icon="icons.ai" :class="ICON_SIZE.lg" />
        {{ t('coach.empty') }}
      </p>
      <ChatBubble
        v-for="message in chat.messages"
        :key="message.id"
        :message="message"
        :busy="chat.sending"
        @answer="handleAnswer"
      />
      <p v-if="chat.sending" class="flex items-center gap-1.5 text-sm text-slate-400">
        <FontAwesomeIcon :icon="icons.loading" :class="ICON_SIZE.sm" spin />
        {{ t('coach.thinking') }}
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
