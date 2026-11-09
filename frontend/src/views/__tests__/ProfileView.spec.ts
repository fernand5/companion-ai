import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import type { FitnessProfile } from '@/types'
import ProfileView from '@/views/ProfileView.vue'

vi.mock('@/services/profile', () => ({ fetchProfile: vi.fn(), updateProfile: vi.fn() }))
vi.mock('@/services/schedule', () => ({ fetchSchedule: vi.fn(), createSchedule: vi.fn(), deleteSchedule: vi.fn() }))

const profile: FitnessProfile = {
  id: 1,
  height_cm: 180,
  weight_kg: 80,
  age: 30,
  sex: null,
  fitness_level: 'intermediate',
  primary_goal: 'Lose fat',
  secondary_goal: null,
  equipment: ['dumbbells'],
  preferred_training_days: [],
  preferred_training_duration_minutes: 45,
  coach_style: 'balanced',
  response_detail: 'standard',
  updated_at: null,
}

describe('ProfileView coaching settings', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
  })

  it('shows the coaching settings once the profile has loaded', async () => {
    let finish!: (p: FitnessProfile) => void
    vi.mocked(profileService.fetchProfile).mockReturnValue(new Promise((resolve) => (finish = resolve)))

    const wrapper = mount(ProfileView)
    await nextTick()
    expect(wrapper.text()).toContain('Loading profile')
    expect(wrapper.find('#coaching-heading').exists()).toBe(false)

    finish(profile)
    await flushPromises()

    expect(wrapper.get('#coaching-heading').text()).toContain('Your coach')
  })

  it('does not show them when the profile could not be loaded', async () => {
    vi.mocked(profileService.fetchProfile).mockRejectedValue(new Error('network error'))

    const wrapper = mount(ProfileView)
    await flushPromises()

    expect(wrapper.find('#coaching-heading').exists()).toBe(false)
  })

  it('saving the coaching style leaves unsaved edits in the profile form alone and sends nothing else', async () => {
    vi.mocked(profileService.fetchProfile).mockResolvedValue(profile)
    vi.mocked(profileService.updateProfile).mockResolvedValue({ ...profile, coach_style: 'intense' })

    const wrapper = mount(ProfileView)
    await flushPromises()

    const goal = wrapper.get('input[placeholder^="e.g. Lose body fat"]')
    await goal.setValue('Run a half marathon')

    await wrapper.get('input[name="coach-style"][value="intense"]').setValue(true)
    await wrapper.findAll('button').find((b) => b.text().includes('Save coaching style'))!.trigger('click')
    await flushPromises()

    expect(profileService.updateProfile).toHaveBeenCalledTimes(1)
    expect(profileService.updateProfile).toHaveBeenCalledWith({ coach_style: 'intense', response_detail: 'standard' })
    expect((goal.element as HTMLInputElement).value).toBe('Run a half marathon')
  })

  it('saving the rest of the profile does not send the coaching settings', async () => {
    vi.mocked(profileService.fetchProfile).mockResolvedValue({ ...profile, coach_style: 'intense' })
    vi.mocked(profileService.updateProfile).mockResolvedValue({ ...profile, coach_style: 'intense' })

    const wrapper = mount(ProfileView)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const sent = vi.mocked(profileService.updateProfile).mock.calls[0][0]
    expect(sent).not.toHaveProperty('coach_style')
    expect(sent).not.toHaveProperty('response_detail')
  })
})
