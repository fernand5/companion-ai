import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import ChatBubble from '@/components/ChatBubble.vue'
import ChatInput from '@/components/ChatInput.vue'
import QuickActions from '@/components/QuickActions.vue'
import { applyLocale } from '@/i18n'
import * as conversationService from '@/services/conversations'
import { useChatStore } from '@/stores/chat'
import type { Message } from '@/types'
import CoachView from '@/views/CoachView.vue'

vi.mock('@/services/conversations', () => ({
  fetchConversations: vi.fn(async () => []),
  createConversation: vi.fn(async () => ({ id: 1, title: null, updated_at: null, created_at: null })),
  fetchMessages: vi.fn(async () => []),
  sendMessage: vi.fn(async (_id: number, content: string) => ({ id: 2, role: 'assistant', content: `Eco: ${content}`, meta: null, created_at: null })),
}))

const message = (over: Partial<Message> = {}): Message => ({ id: 1, role: 'assistant', content: 'Hola', meta: null, created_at: null, ...over })

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('CoachView in both languages', () => {
  async function view() {
    const wrapper = mount(CoachView)
    await flushPromises()

    return wrapper
  }

  it('reads in English as it always has', async () => {
    const wrapper = await view()
    expect(wrapper.get('h1').text()).toBe('Coach')
    expect(wrapper.text()).toContain('Say hello, or ask what to do today.')
    expect(wrapper.get('textarea').attributes('placeholder')).toBe("Tell your coach what you did, or ask what's next…")
    expect(wrapper.get('button[type="submit"]').text()).toBe('Send')
    expect(wrapper.findAll('button').slice(0, 5).map((b) => b.text())).toEqual(['What should I do today?', 'Log today’s workout', 'Log my steps', 'Update my weight', 'How am I progressing?'])
  })

  it('reads in Spanish and switches without a reload', async () => {
    const wrapper = await view()
    applyLocale('es')
    await nextTick()

    expect(wrapper.text()).toContain('Saluda o pregunta qué hacer hoy.')
    expect(wrapper.get('textarea').attributes('placeholder')).toBe('Cuéntale a tu coach lo que hiciste, o pregunta qué sigue…')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Enviar')
    expect(wrapper.findAll('button').slice(0, 5).map((b) => b.text())).toEqual(['¿Qué debo hacer hoy?', 'Registrar el entrenamiento de hoy', 'Registrar mis pasos', 'Actualizar mi peso', '¿Cómo voy con mi progreso?'])

    applyLocale('en')
    await nextTick()
    expect(wrapper.get('button[type="submit"]').text()).toBe('Send')
  })

  it('sends a picked suggestion as the person\'s own message, in the language on screen, and shows the coach\'s reply as written', async () => {
    applyLocale('es')
    const wrapper = await view()

    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(conversationService.sendMessage).toHaveBeenCalledWith(1, '¿Qué debo hacer hoy?')
    expect(wrapper.text()).toContain('¿Qué debo hacer hoy?') // the person's message, as sent
    expect(wrapper.text()).toContain('Eco: ¿Qué debo hacer hoy?') // the coach's reply, untouched
  })

  it('sends what the person types exactly as typed, in either language', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      vi.mocked(conversationService.sendMessage).mockClear()
      const wrapper = await view()
      await wrapper.get('textarea').setValue('  add this workout to my dashboard  ')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(conversationService.sendMessage).toHaveBeenCalledWith(1, 'add this workout to my dashboard')
    }
  })

  it('says the coach is thinking in the language on screen', async () => {
    applyLocale('es')
    vi.mocked(conversationService.sendMessage).mockReturnValue(new Promise(() => {}))
    const wrapper = await view()
    useChatStore().send('hola')
    await nextTick()

    expect(wrapper.text()).toContain('El coach está pensando…')
  })

  it('shows the server\'s own provider error as it came, and words only its own fallbacks', async () => {
    const wrapper = await view()
    vi.mocked(conversationService.sendMessage).mockRejectedValue({ response: { status: 503, data: { code: 'ai_rate_limited', message: 'Tu coach alcanzó su límite de solicitudes por ahora; espera un momento e inténtalo de nuevo.' } } })
    await useChatStore().send('hola')
    await nextTick()
    expect(wrapper.text()).toContain('Tu coach alcanzó su límite de solicitudes por ahora')

    vi.mocked(conversationService.sendMessage).mockRejectedValue(new Error('Network Error'))
    await useChatStore().send('hola')
    expect(wrapper.text()).toContain("Couldn't reach your coach — please try again.")
    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos comunicarnos con tu coach. Inténtalo de nuevo.')
  })

  it('re-words a failed conversation load when the language changes', async () => {
    vi.mocked(conversationService.fetchConversations).mockRejectedValueOnce(new Error('x'))
    const wrapper = await view()
    expect(wrapper.text()).toContain("Couldn't load your coach conversation.")
    applyLocale('es')
    await nextTick()
    expect(wrapper.text()).toContain('No pudimos cargar tu conversación con el coach.')
  })
})

describe('chat pieces', () => {
  it('lets the Enter key send and Shift+Enter keep writing, in both languages', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      const wrapper = mount(ChatInput)
      await wrapper.get('textarea').setValue('hola')
      await wrapper.get('textarea').trigger('keydown', { key: 'Enter', shiftKey: true })
      expect(wrapper.emitted('send')).toBeUndefined()
      await wrapper.get('textarea').trigger('keydown', { key: 'Enter' })
      expect(wrapper.emitted('send')).toEqual([['hola']])
    }
  })

  it('names the send button with visible text in both languages', () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      expect(mount(ChatInput).get('button').text().length).toBeGreaterThan(0)
    }
  })

  it('emits the words of the suggestion on screen', async () => {
    applyLocale('es')
    const wrapper = mount(QuickActions)
    await wrapper.findAll('button')[3].trigger('click')

    expect(wrapper.emitted('pick')).toEqual([['Actualizar mi peso']])
  })

  it('words the "checked N things" summary with natural plurals and never translates a tool name', async () => {
    const one = mount(ChatBubble, { props: { message: message({ meta: { tool_calls: [{ name: 'get_todays_plan', arguments: {}, result: null }] } as never }) } })
    const two = mount(ChatBubble, { props: { message: message({ meta: { tool_calls: [{ name: 'get_todays_plan', arguments: {}, result: null }, { name: 'log_workout', arguments: {}, result: null }] } as never }) } })
    expect(one.get('summary').text()).toBe('Checked 1 thing')
    expect(two.get('summary').text()).toBe('Checked 2 things')

    applyLocale('es')
    await nextTick()
    expect(one.get('summary').text()).toBe('Revisó 1 cosa')
    expect(two.get('summary').text()).toBe('Revisó 2 cosas')
    expect(two.findAll('li').map((li) => li.text())).toEqual(['get_todays_plan', 'log_workout'])
  })

  it('shows what each side wrote as written, whatever the language on screen', () => {
    applyLocale('es')
    expect(mount(ChatBubble, { props: { message: message({ role: 'user', content: 'I did squats today' }) } }).text()).toContain('I did squats today')
    expect(mount(ChatBubble, { props: { message: message({ content: '**Bien** hecho' }) } }).text()).toContain('Bien hecho')
  })
})
