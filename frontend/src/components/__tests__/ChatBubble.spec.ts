import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ChatBubble from '@/components/ChatBubble.vue'
import type { Message } from '@/types'

function makeMessage(overrides: Partial<Message> = {}): Message {
  return {
    id: 1,
    role: 'assistant',
    content: 'Take it easy today.',
    meta: null,
    created_at: null,
    ...overrides,
  }
}

describe('ChatBubble', () => {
  it('renders the message content', () => {
    const wrapper = mount(ChatBubble, { props: { message: makeMessage() } })

    expect(wrapper.text()).toContain('Take it easy today.')
  })

  it('shows a tool-call trace when present', () => {
    const wrapper = mount(ChatBubble, {
      props: {
        message: makeMessage({
          meta: { tool_calls: [{ name: 'log_activity', arguments: {}, result: {} }] },
        }),
      },
    })

    expect(wrapper.text()).toContain('Checked 1 thing')
    expect(wrapper.text()).toContain('log_activity')
  })

  it('does not show a tool-call trace when absent', () => {
    const wrapper = mount(ChatBubble, { props: { message: makeMessage() } })

    expect(wrapper.find('details').exists()).toBe(false)
  })
})

describe('ChatBubble: the coach writes Markdown', () => {
  it('shows bullets, bold and a numbered list as formatting, not as raw symbols', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: makeMessage({ content: "Here's your plan:\n\n* **Push-ups** 3x12\n* Rows 3x10\n\n1. Warm up\n2. Lift" }) },
    })

    expect(wrapper.findAll('ul > li')).toHaveLength(2)
    expect(wrapper.findAll('ol > li')).toHaveLength(2)
    expect(wrapper.get('strong').text()).toBe('Push-ups')
    expect(wrapper.text()).not.toContain('**')
    expect(wrapper.text()).not.toMatch(/^\*\s/m)
  })

  it('never inserts reply text as HTML', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: makeMessage({ content: 'Hi <img src=x onerror="window.__pwned=1"> <script>window.__pwned=2</script> <b>bold?</b>' }) },
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>window.__pwned=2</script>')
    expect((window as unknown as { __pwned?: number }).__pwned).toBeUndefined()
  })

  it('makes safe links open safely, and leaves a javascript: link as plain text', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: makeMessage({ content: '[Docs](https://example.com/guide) and [bad](javascript:alert(1))' }) },
    })

    const links = wrapper.findAll('a')
    expect(links).toHaveLength(1)
    expect(links[0].attributes()).toMatchObject({ href: 'https://example.com/guide', target: '_blank' })
    expect(links[0].attributes('rel')).toContain('noopener')
    expect(links[0].attributes('rel')).toContain('noreferrer')
    expect(wrapper.text()).toContain('bad')
    expect(wrapper.html()).not.toContain('javascript:')
  })

  it('shows what the user typed exactly as typed: no formatting, no links', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: makeMessage({ role: 'user', content: '**hello** <b>x</b> [a](https://example.com)\nsecond line' }) },
    })

    expect(wrapper.text()).toContain('**hello** <b>x</b> [a](https://example.com)')
    expect(wrapper.find('strong').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.get('[data-testid="bubble"]').classes()).toContain('whitespace-pre-wrap')
  })

  it('lets long unbroken text and code wrap or scroll inside the bubble instead of widening the page', () => {
    const assistant = mount(ChatBubble, {
      props: { message: makeMessage({ content: `${'x'.repeat(300)}\n\n\`\`\`\n${'y'.repeat(300)}\n\`\`\`` }) },
    })
    const user = mount(ChatBubble, { props: { message: makeMessage({ role: 'user', content: 'z'.repeat(300) }) } })

    expect(assistant.get('[data-testid="markdown"]').classes().join(' ')).toContain('[overflow-wrap:anywhere]')
    expect(assistant.get('pre').classes()).toContain('overflow-x-auto')
    expect(user.get('[data-testid="bubble"]').classes().join(' ')).toContain('[overflow-wrap:anywhere]')
    expect(assistant.get('[data-testid="bubble"]').element.parentElement?.classList.contains('min-w-0')).toBe(true)
  })

  it('keeps the tool-call trace, outside the formatted text', () => {
    const wrapper = mount(ChatBubble, {
      props: { message: makeMessage({ content: '- done', meta: { tool_calls: [{ name: 'create_workout_plan', arguments: {}, result: {} }] } }) },
    })

    expect(wrapper.text()).toContain('Checked 1 thing')
    expect(wrapper.text()).toContain('create_workout_plan')
  })
})
