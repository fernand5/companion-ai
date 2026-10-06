import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import ActivityQuickLogForm from '@/components/ActivityQuickLogForm.vue'
import * as activityService from '@/services/activity'

vi.mock('@/services/activity', () => ({
  fetchActivity: vi.fn(),
  deleteActivity: vi.fn(),
  logActivity: vi.fn(async () => ({ id: 1 })),
}))
vi.mock('@/services/workouts', () => ({ fetchWorkouts: vi.fn(), logWorkout: vi.fn() }))

function mountForm() {
  return mount(ActivityQuickLogForm)
}

async function pick(wrapper: ReturnType<typeof mountForm>, type: string) {
  await wrapper.findAll('button').find((b) => b.text().toLowerCase() === type)!.trigger('click')
}

describe('ActivityQuickLogForm', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('records distance and duration for a treadmill session', async () => {
    const wrapper = mountForm()
    await pick(wrapper, 'treadmill')

    await wrapper.find('input[placeholder="optional"]').setValue('5.2')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(activityService.logActivity).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'treadmill', duration_minutes: 20, distance_km: 5.2 }),
    )
  })

  it('previews speed and pace from what is typed', async () => {
    const wrapper = mountForm()
    await pick(wrapper, 'treadmill')

    expect(wrapper.find('[data-testid="cardio-preview"]').exists()).toBe(false)

    await wrapper.find('input[placeholder="optional"]').setValue('4')
    const minutes = wrapper.findAll('input[type="number"]')[0]
    await minutes.setValue('30')

    expect(wrapper.find('[data-testid="cardio-preview"]').text()).toBe('8.0 km/h · 7:30 /km')
  })

  it('does not send a distance when none was entered', async () => {
    const wrapper = mountForm()
    await pick(wrapper, 'treadmill')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(activityService.logActivity).mock.calls[0][0].distance_km).toBeUndefined()
  })

  it('offers no distance field for steps and never sends one', async () => {
    const wrapper = mountForm()

    expect(wrapper.find('input[placeholder="optional"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(activityService.logActivity).mock.calls[0][0].distance_km).toBeUndefined()
  })

  it('clears the distance after logging so it is not repeated by accident', async () => {
    const wrapper = mountForm()
    await pick(wrapper, 'treadmill')
    await wrapper.find('input[placeholder="optional"]').setValue('5')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect((wrapper.find('input[placeholder="optional"]').element as HTMLInputElement).value).toBe('')
  })
})
