import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import CoachingStyleSettings from '@/components/CoachingStyleSettings.vue'
import { COACH_SAMPLES } from '@/constants/coaching'
import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import { useProfileStore } from '@/stores/profile'
import type { FitnessProfile } from '@/types'

vi.mock('@/services/profile', () => ({ fetchProfile: vi.fn(), updateProfile: vi.fn() }))
vi.mock('@/services/schedule', () => ({ fetchSchedule: vi.fn(), createSchedule: vi.fn(), deleteSchedule: vi.fn() }))

function makeProfile(overrides: Partial<FitnessProfile> = {}): FitnessProfile {
  return {
    id: 1,
    height_cm: 180,
    weight_kg: 80,
    age: 30,
    sex: null,
    fitness_level: 'intermediate',
    primary_goal: 'Lose fat',
    secondary_goal: null,
    equipment: [],
    preferred_training_days: [],
    preferred_training_duration_minutes: null,
    coach_style: 'balanced',
    response_detail: 'standard',
    updated_at: null,
    ...overrides,
  }
}

async function mountLoaded(profile: FitnessProfile | null = makeProfile()) {
  vi.mocked(profileService.fetchProfile).mockResolvedValue(profile)
  vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])

  const store = useProfileStore()
  await store.load()

  return { store, wrapper: mount(CoachingStyleSettings) }
}

const radio = (wrapper: ReturnType<typeof mount>, name: string, value: string) =>
  wrapper.find(`input[name="${name}"][value="${value}"]`)

const saveButton = (wrapper: ReturnType<typeof mount>) =>
  wrapper.findAll('button').find((b) => /save coaching style|saving/i.test(b.text()))!

describe('CoachingStyleSettings', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('offers all three styles and all three detail levels, each with a description', async () => {
    const { wrapper } = await mountLoaded()

    for (const v of ['supportive', 'balanced', 'intense']) expect(radio(wrapper, 'coach-style', v).exists()).toBe(true)
    for (const v of ['concise', 'standard', 'detailed']) expect(radio(wrapper, 'response-detail', v).exists()).toBe(true)
    expect(wrapper.text()).toContain('Empathetic, encouraging and patient')
    expect(wrapper.text()).toContain('Energetic, challenging and accountable')
    expect(wrapper.text()).toContain('Short answers')
  })

  it('shows which values are saved, and selects them', async () => {
    const { wrapper } = await mountLoaded(makeProfile({ coach_style: 'intense', response_detail: 'detailed' }))

    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Intense')
    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Detailed')
    expect((radio(wrapper, 'coach-style', 'intense').element as HTMLInputElement).checked).toBe(true)
    expect((radio(wrapper, 'response-detail', 'detailed').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.findAll('[data-testid="saved-badge"]')).toHaveLength(2)
  })

  it('starts on Balanced and Standard for someone with no profile yet', async () => {
    const { wrapper } = await mountLoaded(null)

    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Balanced')
    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Standard')
  })

  it('cannot be saved until something changes', async () => {
    const { wrapper } = await mountLoaded()

    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

    await radio(wrapper, 'coach-style', 'supportive').setValue(true)
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    await radio(wrapper, 'coach-style', 'balanced').setValue(true)
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('previews a fixed example for the chosen style and detail without calling anything', async () => {
    const { wrapper } = await mountLoaded()
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(COACH_SAMPLES.balanced.standard)

    await radio(wrapper, 'coach-style', 'intense').setValue(true)
    await radio(wrapper, 'response-detail', 'concise').setValue(true)

    expect(wrapper.get('[data-testid="sample"]').text()).toContain(COACH_SAMPLES.intense.concise)
    expect(profileService.updateProfile).not.toHaveBeenCalled()
  })

  it('never shows an example that pushes harder or trains through pain, in any style', () => {
    for (const style of Object.values(COACH_SAMPLES)) {
      for (const text of Object.values(style)) {
        expect(text).toMatch(/rest|light|lower-impact|adjust/i)
        expect(text).not.toMatch(/no excuses|extra (sets|reps|weight)|heavier|train through the pain|ignore the pain/i)
      }
    }
  })

  it('saves only the two coaching fields, then shows them as saved', async () => {
    vi.mocked(profileService.updateProfile).mockResolvedValue(
      makeProfile({ coach_style: 'supportive', response_detail: 'concise' }),
    )
    const { wrapper } = await mountLoaded()

    await radio(wrapper, 'coach-style', 'supportive').setValue(true)
    await radio(wrapper, 'response-detail', 'concise').setValue(true)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(profileService.updateProfile).toHaveBeenCalledTimes(1)
    expect(profileService.updateProfile).toHaveBeenCalledWith({ coach_style: 'supportive', response_detail: 'concise' })
    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Supportive')
    expect(wrapper.get('[role="status"]').text()).toContain('Saved')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('shows a saving state and blocks changes while the request is in flight', async () => {
    let finish!: (p: FitnessProfile) => void
    vi.mocked(profileService.updateProfile).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { wrapper } = await mountLoaded()

    await radio(wrapper, 'coach-style', 'intense').setValue(true)
    await saveButton(wrapper).trigger('click')

    expect(saveButton(wrapper).text()).toContain('Saving')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.findAll('fieldset').every((f) => f.attributes('disabled') !== undefined)).toBe(true)

    finish(makeProfile({ coach_style: 'intense' }))
    await flushPromises()

    expect(saveButton(wrapper).text()).toContain('Save coaching style')
  })

  it('shows the error, keeps the choice and lets the user retry', async () => {
    vi.mocked(profileService.updateProfile)
      .mockRejectedValueOnce({ response: { data: { message: 'The selected coach style is invalid.' } } })
      .mockResolvedValueOnce(makeProfile({ coach_style: 'intense' }))
    const { store, wrapper } = await mountLoaded()

    await radio(wrapper, 'coach-style', 'intense').setValue(true)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The selected coach style is invalid.')
    expect(store.coaching.coach_style).toBe('balanced')
    expect((radio(wrapper, 'coach-style', 'intense').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    await wrapper.findAll('button').find((b) => b.text() === 'Try again')!.trigger('click')
    await flushPromises()

    expect(store.coaching.coach_style).toBe('intense')
    expect(wrapper.text()).not.toContain('The selected coach style is invalid.')
  })

  it('shows the saved values again after a reload', async () => {
    vi.mocked(profileService.updateProfile).mockResolvedValue(makeProfile({ coach_style: 'supportive', response_detail: 'detailed' }))
    const first = await mountLoaded()
    await radio(first.wrapper, 'coach-style', 'supportive').setValue(true)
    await radio(first.wrapper, 'response-detail', 'detailed').setValue(true)
    await saveButton(first.wrapper).trigger('click')
    await flushPromises()
    first.wrapper.unmount()

    // A new page load: fresh store, and the server now returns what was saved.
    setActivePinia(createPinia())
    const { wrapper } = await mountLoaded(makeProfile({ coach_style: 'supportive', response_detail: 'detailed' }))

    expect(wrapper.get('[data-testid="saved-values"]').text()).toContain('Supportive')
    expect((radio(wrapper, 'response-detail', 'detailed').element as HTMLInputElement).checked).toBe(true)
  })
})
