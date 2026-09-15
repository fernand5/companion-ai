import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import WhyThisChangedCard from '@/components/WhyThisChangedCard.vue'

describe('WhyThisChangedCard', () => {
  it('renders the reasoning factors as a bullet list', () => {
    const wrapper = mount(WhyThisChangedCard, {
      props: { reasoning: 'Keeping legs fresh.', reasoningFactors: ['Football tomorrow', 'Sore from yesterday'] },
    })

    expect(wrapper.text()).toContain('Why this changed')
    expect(wrapper.text()).toContain('Football tomorrow')
    expect(wrapper.text()).toContain('Sore from yesterday')
    expect(wrapper.text()).toContain('Keeping legs fresh.')
  })

  it('renders nothing when there is no reasoning', () => {
    const wrapper = mount(WhyThisChangedCard, { props: { reasoning: null, reasoningFactors: [] } })

    expect(wrapper.find('div').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })
})
