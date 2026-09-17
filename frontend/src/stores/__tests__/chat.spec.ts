import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as conversationService from '@/services/conversations'
import { useChatStore } from '@/stores/chat'

vi.mock('@/services/conversations', () => ({
  fetchConversations: vi.fn(async () => []),
  createConversation: vi.fn(async () => ({
    id: 1,
    title: null,
    updated_at: null,
    created_at: null,
  })),
  fetchMessages: vi.fn(async () => []),
  sendMessage: vi.fn(async (_id: number, content: string) => ({
    id: 2,
    role: 'assistant',
    content: `Reply to: ${content}`,
    meta: null,
    created_at: null,
  })),
}))

describe('chat store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('creates a conversation on first send and appends both messages', async () => {
    const store = useChatStore()

    await store.send('What should I do today?')

    expect(store.activeConversation?.id).toBe(1)
    expect(store.messages).toHaveLength(2)
    expect(store.messages[0].role).toBe('user')
    expect(store.messages[0].content).toBe('What should I do today?')
    expect(store.messages[1].role).toBe('assistant')
    expect(store.messages[1].content).toContain('What should I do today?')
  })

  it('reuses the active conversation on subsequent sends', async () => {
    const store = useChatStore()

    await store.send('first')
    await store.send('second')

    expect(store.messages).toHaveLength(4)
  })

  it('removes the optimistic user message and surfaces an error when the send fails', async () => {
    const store = useChatStore()
    vi.mocked(conversationService.sendMessage).mockRejectedValueOnce({
      response: { data: { message: 'The AI coach is not configured yet.' } },
    })

    await store.send('What should I do today?')

    expect(store.messages).toHaveLength(0)
    expect(store.error).toBe('The AI coach is not configured yet.')
  })

  it('sets an error instead of leaving the conversation empty and silent when the initial load fails', async () => {
    const store = useChatStore()
    vi.mocked(conversationService.fetchConversations).mockRejectedValueOnce(new Error('network error'))

    const result = await store.ensureActiveConversation()

    expect(result).toBeNull()
    expect(store.activeConversation).toBeNull()
    expect(store.loadingMessages).toBe(false)
    expect(store.error).not.toBeNull()
  })

  it('does nothing and keeps the load error when send is called while the conversation failed to load', async () => {
    const store = useChatStore()
    vi.mocked(conversationService.fetchConversations).mockRejectedValueOnce(new Error('network error'))

    await store.send('What should I do today?')

    expect(store.messages).toHaveLength(0)
    expect(conversationService.sendMessage).not.toHaveBeenCalled()
    expect(store.error).not.toBeNull()
  })
})
