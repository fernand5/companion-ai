import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import ChatBubble from '@/components/ChatBubble.vue'
import { applyLocale } from '@/i18n'
import * as conversationService from '@/services/conversations'
import { useChatStore } from '@/stores/chat'
import type { Message } from '@/types'
import CoachView from '@/views/CoachView.vue'

vi.mock('@/services/conversations', () => ({
  fetchConversations: vi.fn(async () => [{ id: 1, title: null, updated_at: null, created_at: null }]),
  createConversation: vi.fn(async () => ({ id: 1, title: null, updated_at: null, created_at: null })),
  fetchMessages: vi.fn(async () => []),
  sendMessage: vi.fn(async () => ({ id: 99, role: 'assistant', content: 'ok', meta: null, created_at: null })),
}))

const stubs = { RouterLink: { template: '<a data-testid="dashboard-link"><slot /></a>' } }

const question = (over: Partial<Message> = {}): Message => ({
  id: 10,
  role: 'assistant',
  content: 'Replace it? Reply "yes".',
  meta: null,
  confirmation: { kind: 'replacement', actionable: true, expires_at: new Date(Date.now() + 10 * 60_000).toISOString() },
  created_at: null,
  ...over,
})

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('confirmation buttons', () => {
  it('shows Yes/No only when the server says the question is actionable', () => {
    const live = mount(ChatBubble, { props: { message: question() } })

    expect(live.get('[data-testid="confirm-yes"]').text()).toBe('Yes, replace')
    expect(live.get('[data-testid="confirm-no"]').text()).toBe('No, keep it')

    const spent = mount(ChatBubble, { props: { message: question({ confirmation: { kind: 'replacement', actionable: false, expires_at: null } }) } })
    expect(spent.find('[data-testid="confirmation"]').exists()).toBe(false)
  })

  it('never decides by the wording of a message', () => {
    const wording = mount(ChatBubble, { props: { message: question({ confirmation: null, content: 'Do you want me to replace it? Reply "yes" within 15 minutes.' }) } })
    const absent = mount(ChatBubble, { props: { message: { id: 3, role: 'assistant', content: 'Replace it? Reply "yes".', meta: null, created_at: null } } })

    expect(wording.find('button').exists()).toBe(false)
    expect(absent.find('button').exists()).toBe(false)
  })

  it('emits the answer, and disables both buttons while a turn is in flight', async () => {
    const wrapper = mount(ChatBubble, { props: { message: question() } })

    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await wrapper.get('[data-testid="confirm-no"]').trigger('click')
    expect(wrapper.emitted('answer')).toEqual([['yes'], ['no']])

    await wrapper.setProps({ busy: true })
    expect(wrapper.get('[data-testid="confirm-yes"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="confirm-no"]').attributes('disabled')).toBeDefined()
  })

  it('stops offering the buttons when the time runs out, and says why', async () => {
    vi.useFakeTimers()
    const wrapper = mount(ChatBubble, {
      props: { message: question({ confirmation: { kind: 'replacement', actionable: true, expires_at: new Date(Date.now() + 5_000).toISOString() } }) },
    })

    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(true)
    vi.advanceTimersByTime(6_000)
    await nextTick()

    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="confirmation-expired"]').text()).toContain('expired')
  })

  it('already-expired questions loaded from history offer no buttons', async () => {
    const wrapper = mount(ChatBubble, {
      props: { message: question({ confirmation: { kind: 'replacement', actionable: true, expires_at: new Date(Date.now() - 60_000).toISOString() } }) },
    })
    await nextTick()

    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(false)
  })

  it('reads in Spanish and switches language without a reload', async () => {
    const wrapper = mount(ChatBubble, { props: { message: question() } })

    applyLocale('es')
    await nextTick()

    expect(wrapper.get('[data-testid="confirm-yes"]').text()).toBe('Sí, reemplazar')
    expect(wrapper.get('[data-testid="confirm-no"]').text()).toBe('No, conservar')
  })
})

describe('save feedback', () => {
  const saved = (days: NonNullable<Message['meta']>['save_result'] extends infer R ? R : never): Message => ({
    id: 20,
    role: 'assistant',
    content: 'Listo.',
    meta: { save_result: days },
    created_at: null,
  })

  it('shows the workout name, the exact date, what happened, and a Dashboard link', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: saved({ version: 1, days: [{ date: '2026-11-18', outcome: 'replaced', title: 'Torso y Core' }] }) },
      global: { stubs },
    })

    expect(wrapper.get('[data-testid="save-result-title"]').text()).toBe('Torso y Core')
    expect(wrapper.get('[data-testid="save-result-date"]').text()).toBe('Wednesday, November 18, 2026')
    expect(wrapper.get('[data-testid="save-result-outcome"]').text()).toBe('Replaced')
    expect(wrapper.get('[data-testid="save-result-dashboard"]').text()).toContain('Open Dashboard')
  })

  it('lists every day of a multi-day save, rest days included, in the chosen language', async () => {
    const wrapper = mount(ChatBubble, {
      props: {
        message: saved({
          version: 1,
          days: [
            { date: '2026-11-18', outcome: 'added', title: 'Piernas' },
            { date: '2026-11-19', outcome: 'rest', title: null },
            { date: '2026-11-20', outcome: 'replaced', title: 'Torso' },
          ],
        }),
      },
      global: { stubs },
    })

    applyLocale('es')
    await nextTick()

    const days = wrapper.findAll('[data-testid="save-result-day"]')
    expect(days).toHaveLength(3)
    expect(days[0].text()).toContain('Piernas')
    expect(days[0].text()).toContain('miércoles, 18 de noviembre de 2026')
    expect(days[0].text()).toContain('Agregado')
    expect(days[1].text()).toContain('Día de descanso')
    expect(days[2].text()).toContain('Reemplazado')
    expect(wrapper.get('[data-testid="save-result-dashboard"]').text()).toContain('Abrir el Dashboard')
  })

  it('shows no success card without the server\'s result, however the reply is worded', () => {
    const wrapper = mount(ChatBubble, { props: { message: { id: 5, role: 'assistant', content: 'Ya lo agregué a tu plan.', meta: null, created_at: null } } })

    expect(wrapper.find('[data-testid="save-result"]').exists()).toBe(false)
  })
})

describe('answering from the Coach screen', () => {
  it('a button sends the short answer, then asks the server which questions are still open', async () => {
    vi.mocked(conversationService.fetchMessages).mockResolvedValueOnce([question()]).mockResolvedValue([question({ confirmation: { kind: 'replacement', actionable: false, expires_at: null } })])
    vi.mocked(conversationService.sendMessage).mockResolvedValueOnce({ id: 99, role: 'assistant', content: 'Listo.', meta: null, created_at: null })

    const wrapper = mount(CoachView, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(true)

    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(conversationService.sendMessage).toHaveBeenCalledWith(1, 'Yes')
    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(false)
  })

  it('sends "Sí" in Spanish and "No" to decline', async () => {
    vi.mocked(conversationService.fetchMessages).mockResolvedValue([question()])
    applyLocale('es')

    const wrapper = mount(CoachView, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await flushPromises()
    expect(conversationService.sendMessage).toHaveBeenLastCalledWith(1, 'Sí')

    vi.mocked(conversationService.fetchMessages).mockResolvedValue([question()])
    useChatStore().messages = [question()]
    await nextTick()
    await wrapper.get('[data-testid="confirm-no"]').trigger('click')
    await flushPromises()
    expect(conversationService.sendMessage).toHaveBeenLastCalledWith(1, 'No')
  })

  it('a click while a turn is in flight is ignored (no duplicate answer)', async () => {
    vi.mocked(conversationService.fetchMessages).mockResolvedValue([question()])
    let release: (m: Message) => void = () => undefined
    vi.mocked(conversationService.sendMessage).mockImplementationOnce(() => new Promise<Message>((resolve) => (release = resolve)))

    const wrapper = mount(CoachView, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await wrapper.get('[data-testid="confirm-no"]').trigger('click')

    expect(conversationService.sendMessage).toHaveBeenCalledTimes(1)
    release({ id: 99, role: 'assistant', content: 'Listo.', meta: null, created_at: null })
    await flushPromises()
  })

  it('a network failure keeps the question open and reports the error; the server is asked what is still open', async () => {
    vi.mocked(conversationService.fetchMessages).mockResolvedValue([question()])
    vi.mocked(conversationService.sendMessage).mockRejectedValueOnce(new Error('offline'))

    const wrapper = mount(CoachView, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(true)
    expect(wrapper.text()).toMatch(/coach|reach|connect/i)
  })

  it('if the open questions cannot be re-read, none is offered on a guess', async () => {
    vi.mocked(conversationService.fetchMessages).mockResolvedValueOnce([question()]).mockRejectedValue(new Error('offline'))

    const wrapper = mount(CoachView, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-testid="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="confirm-yes"]').exists()).toBe(false)
  })
})
