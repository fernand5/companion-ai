import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as conversationService from '@/services/conversations'
import type { Conversation, Message } from '@/types'
import { errorRef } from '@/utils/errors'

export const useChatStore = defineStore('chat', () => {
  const conversations = ref<Conversation[]>([])
  const activeConversation = ref<Conversation | null>(null)
  const messages = ref<Message[]>([])
  const loadingMessages = ref(false)
  const sending = ref(false)
  const failure = errorRef()
  const error = failure.message

  async function loadConversations() {
    conversations.value = await conversationService.fetchConversations()
  }

  async function openConversation(conversation: Conversation) {
    activeConversation.value = conversation
    loadingMessages.value = true
    failure.clear()

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
    failure.clear()

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
      failure.fail(e, 'chatLoad')

      return null
    } finally {
      loadingMessages.value = false
    }
  }

  /**
   * Re-reads, from the server, which replacement questions can still be answered, and updates only those. A question that
   * cannot be re-read is treated as closed, so a button is never offered on a guess; the server decides every answer anyway.
   */
  async function refreshConfirmations() {
    const conversation = activeConversation.value

    if (!conversation || !messages.value.some((m) => m.confirmation?.actionable)) {
      return
    }

    let fresh: Message[] | null = null

    try {
      fresh = await conversationService.fetchMessages(conversation.id)
    } catch {
      fresh = null
    }

    if (activeConversation.value?.id !== conversation.id) {
      return
    }

    const byId = new Map((fresh ?? []).map((m) => [m.id, m]))

    messages.value = messages.value.map((m) =>
      m.confirmation?.actionable
        ? { ...m, confirmation: byId.get(m.id)?.confirmation ?? { ...m.confirmation, actionable: false } }
        : m,
    )
  }

  async function send(content: string) {
    // One in-flight turn at a time. Two overlapping requests could otherwise
    // resolve out of order and append the older reply after the newer one, or
    // have the second turn built on a history missing the first turn's reply.
    // The flag is taken before any await so two calls in the same tick can't
    // both pass the check.
    if (sending.value) {
      return
    }

    sending.value = true
    failure.clear()

    try {
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

      try {
        const assistantMessage = await conversationService.sendMessage(conversation.id, content)

        // Only render the reply in the conversation it belongs to.
        if (activeConversation.value?.id === conversation.id) {
          messages.value.push(assistantMessage)
        }

        // An older question may have just been answered, spent or overtaken: the server says which ones can still be answered.
        await refreshConfirmations()
      } catch (e: unknown) {
        failure.fail(e, 'coachUnreachable')
        // Compare by id, not object reference — Vue's reactive array wraps
        // pushed objects in a proxy, so the local object reference no longer
        // matches what filter() reads back out of messages.value.
        messages.value = messages.value.filter((m) => m.id !== tempId)
        // The request may have reached the server before the connection failed: ask it what is still open.
        await refreshConfirmations()
      }
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
