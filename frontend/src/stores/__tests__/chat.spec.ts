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

  it('ignores a second send fired while the first is still in flight, so replies can never land out of order', async () => {
    const store = useChatStore()
    let resolveFirst!: (m: Awaited<ReturnType<typeof conversationService.sendMessage>>) => void
    vi.mocked(conversationService.sendMessage).mockImplementationOnce(
      () => new Promise((resolve) => { resolveFirst = resolve }),
    )

    const first = store.send('first message')
    const second = store.send('second message')

    await second
    await vi.waitFor(() => expect(conversationService.sendMessage).toHaveBeenCalledTimes(1))
    resolveFirst({ id: 10, role: 'assistant', content: 'Reply to: first message', meta: null, created_at: null })
    await first

    expect(conversationService.sendMessage).toHaveBeenCalledTimes(1)
    expect(store.messages.map((m) => m.content)).toEqual(['first message', 'Reply to: first message'])
    expect(store.sending).toBe(false)
  })

  it('keeps strict order across two consecutive messages', async () => {
    const store = useChatStore()

    await store.send('one')
    await store.send('two')

    expect(store.messages.map((m) => [m.role, m.content])).toEqual([
      ['user', 'one'],
      ['assistant', 'Reply to: one'],
      ['user', 'two'],
      ['assistant', 'Reply to: two'],
    ])
  })

  it('does not render a reply that arrives after the user switched to a different conversation', async () => {
    const store = useChatStore()
    let resolveReply!: (m: Awaited<ReturnType<typeof conversationService.sendMessage>>) => void
    vi.mocked(conversationService.sendMessage).mockImplementationOnce(
      () => new Promise((resolve) => { resolveReply = resolve }),
    )

    const pending = store.send('hello')
    await vi.waitFor(() => expect(conversationService.sendMessage).toHaveBeenCalled())
    store.activeConversation = { id: 99, title: null, updated_at: null, created_at: null }
    store.messages = []
    resolveReply({ id: 5, role: 'assistant', content: 'Reply for the old conversation', meta: null, created_at: null })
    await pending

    expect(store.messages).toEqual([])
  })

  it('releases the sending lock after a failed send so the next message can go through', async () => {
    const store = useChatStore()
    vi.mocked(conversationService.sendMessage).mockRejectedValueOnce(new Error('503'))

    await store.send('will fail')
    expect(store.sending).toBe(false)

    await store.send('retry')
    expect(store.messages.map((m) => m.content)).toEqual(['retry', 'Reply to: retry'])
  })
})
