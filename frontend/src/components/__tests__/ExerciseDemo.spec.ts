import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ExerciseDemo from '@/components/ExerciseDemo.vue'
import * as exerciseImageService from '@/services/exerciseImages'
import { POLL_INTERVAL_MS } from '@/stores/exerciseImages'

vi.mock('@/services/exerciseImages', () => ({
  requestExerciseImage: vi.fn(),
}))

const request = vi.mocked(exerciseImageService.requestExerciseImage)

function mountDemo(imageUrl: string | null = null) {
  return mount(ExerciseDemo, {
    props: { planId: 1, exerciseId: 10, exerciseName: 'Goblet Squat', imageUrl },
  })
}

describe('ExerciseDemo', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows an existing image immediately, with no request and no button', () => {
    const wrapper = mountDemo('https://img.example/squat.jpg')

    const img = wrapper.find('img')
    expect(img.attributes('src')).toBe('https://img.example/squat.jpg')
    expect(img.attributes('alt')).toBe('Goblet Squat demonstration')
    expect(img.attributes('loading')).toBe('lazy')
    expect(wrapper.find('button').exists()).toBe(false)
    expect(request).not.toHaveBeenCalled()
  })

  it('does nothing until the user taps "Show demo"', () => {
    const wrapper = mountDemo()

    expect(wrapper.text()).toContain('Show demo')
    expect(request).not.toHaveBeenCalled()
  })

  it('shows the generating state, then the image once it is ready', async () => {
    vi.useFakeTimers()
    request
      .mockResolvedValueOnce({ image_url: null, status: 'generating' })
      .mockResolvedValueOnce({ image_url: 'https://img.example/squat.jpg', status: 'ready' })
    const wrapper = mountDemo()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(request).toHaveBeenCalledWith(1, 10)
    expect(wrapper.text()).toContain('Generating exercise demonstration…')
    expect(wrapper.find('img').exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await flushPromises()

    expect(wrapper.find('img').attributes('src')).toBe('https://img.example/squat.jpg')
    expect(wrapper.text()).not.toContain('Generating')
  })

  it('degrades to a quiet message when generation fails', async () => {
    request.mockRejectedValue(new Error('503'))
    const wrapper = mountDemo()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Demo unavailable right now.')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('degrades the same way if the image file itself cannot load', async () => {
    const wrapper = mountDemo('https://img.example/broken.jpg')

    await wrapper.find('img').trigger('error')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('Demo unavailable right now.')
  })

  it('stops polling when it is unmounted', async () => {
    vi.useFakeTimers()
    request.mockResolvedValue({ image_url: null, status: 'generating' })
    const wrapper = mountDemo()

    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(request).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 5)

    expect(request).toHaveBeenCalledTimes(1)
  })
})
