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
