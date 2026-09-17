import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as conversationService from '@/services/conversations'
import type { Conversation, Message } from '@/types'
import { extractErrorMessage } from '@/utils/errors'

export const useChatStore = defineStore('chat', () => {
  const conversations = ref<Conversation[]>([])
  const activeConversation = ref<Conversation | null>(null)
  const messages = ref<Message[]>([])
  const loadingMessages = ref(false)
  const sending = ref(false)
  const error = ref<string | null>(null)

  async function loadConversations() {
    conversations.value = await conversationService.fetchConversations()
  }

  async function openConversation(conversation: Conversation) {
    activeConversation.value = conversation
    loadingMessages.value = true
    error.value = null

    try {
      messages.value = await conversationService.fetchMessages(conversation.id)
    } finally {
      loadingMessages.value = false
    }
  }

  async function ensureActiveConversation(): Promise<Conversation | null> {
    if (activeConversation.value) {
      return activeConversation.value
    }

    loadingMessages.value = true
    error.value = null

    try {
      await loadConversations()

      if (conversations.value.length > 0) {
        const first = conversations.value[0]
        await openConversation(first)

        return first
      }

      const created = await conversationService.createConversation()
      conversations.value.unshift(created)
      activeConversation.value = created
      messages.value = []

      return created
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't load your coach conversation.")

      return null
    } finally {
      loadingMessages.value = false
    }
  }

  async function send(content: string) {
    error.value = null
    const conversation = await ensureActiveConversation()

    if (!conversation) {
      // ensureActiveConversation already set a specific error message.
      return
    }

    const tempId = -Date.now()
    messages.value.push({
      id: tempId,
      role: 'user',
      content,
      meta: null,
      created_at: new Date().toISOString(),
    })

    sending.value = true

    try {
      const assistantMessage = await conversationService.sendMessage(conversation.id, content)
      messages.value.push(assistantMessage)
    } catch (e: unknown) {
      error.value = extractErrorMessage(e, "Couldn't reach your coach — please try again.")
      // Compare by id, not object reference — Vue's reactive array wraps
      // pushed objects in a proxy, so the local object reference no longer
      // matches what filter() reads back out of messages.value.
      messages.value = messages.value.filter((m) => m.id !== tempId)
    } finally {
      sending.value = false
    }
  }

  return {
    conversations,
    activeConversation,
    messages,
    loadingMessages,
    sending,
    error,
    loadConversations,
    openConversation,
    ensureActiveConversation,
    send,
  }
})
