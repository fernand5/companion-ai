import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import { applyLocale } from '@/i18n'
import * as authService from '@/services/auth'
import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import { useProfileStore } from '@/stores/profile'
import type { FitnessProfile, TrainingSchedule } from '@/types'
import ProfileView from '@/views/ProfileView.vue'

vi.mock('@/services/profile', () => ({ fetchProfile: vi.fn(), updateProfile: vi.fn() }))
vi.mock('@/services/schedule', () => ({ fetchSchedule: vi.fn(), createSchedule: vi.fn(), deleteSchedule: vi.fn() }))
vi.mock('@/services/auth', () => ({ register: vi.fn(), login: vi.fn(), logout: vi.fn(), me: vi.fn(), updateLocale: vi.fn() }))

const profile: FitnessProfile = {
  id: 1, height_cm: 180, weight_kg: 80, age: 30, sex: null, fitness_level: 'intermediate', primary_goal: 'Lose fat', secondary_goal: null,
  equipment: ['dumbbells'], preferred_training_days: [], preferred_training_duration_minutes: 45, coach_style: 'balanced', response_detail: 'standard', updated_at: null,
}

const entry: TrainingSchedule = { id: 7, activity_type: 'Football', day_of_week: 2, start_time: '20:00', expected_duration_minutes: 90, intensity: 'high', notes: null }

async function mountProfile(overrides: { profile?: FitnessProfile; schedule?: TrainingSchedule[] } = {}) {
  vi.mocked(profileService.fetchProfile).mockResolvedValue(overrides.profile ?? profile)
  vi.mocked(scheduleService.fetchSchedule).mockResolvedValue(overrides.schedule ?? [])
  const wrapper = mount(ProfileView)
  await flushPromises()

  return wrapper
}

const labelTexts = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('form label').map((l) => l.text())

describe('ProfileView in both languages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads exactly as it always has in English', async () => {
    const wrapper = await mountProfile()

    expect(wrapper.get('h1').text()).toBe('Profile')
    expect(labelTexts(wrapper).slice(0, 4)).toEqual(['Height (cm)', 'Weight (kg)', 'Age', expect.stringContaining('Fitness level')])
    expect(wrapper.text()).toContain('Primary goal')
    expect(wrapper.text()).toContain('Secondary goal')
    expect(wrapper.text()).toContain('Equipment (comma separated)')
    expect(wrapper.text()).toContain('Preferred session length (minutes)')
    expect(wrapper.get('form button[type="submit"]').text()).toBe('Save profile')
    expect(wrapper.text()).toContain('Recurring schedule')
    expect(wrapper.text()).toContain('No recurring activities yet.')
    expect(wrapper.find('input[placeholder^="e.g. Lose"]').exists()).toBe(true)
    expect(wrapper.find('input[placeholder="dumbbells, exercise mat, treadmill"]').exists()).toBe(true)
  })

  it('reads in Spanish: headings, fields, options, placeholders and states', async () => {
    applyLocale('es')
    const wrapper = await mountProfile()

    expect(wrapper.get('h1').text()).toBe('Perfil')
    expect(labelTexts(wrapper).slice(0, 3)).toEqual(['Estatura (cm)', 'Peso (kg)', 'Edad'])
    expect(wrapper.text()).toContain('Nivel de condición física')
    expect(wrapper.text()).toContain('Objetivo principal')
    expect(wrapper.text()).toContain('Objetivo secundario')
    expect(wrapper.text()).toContain('Equipamiento (separado por comas)')
    expect(wrapper.text()).toContain('Duración preferida de la sesión (minutos)')
    expect(wrapper.get('form button[type="submit"]').text()).toBe('Guardar perfil')
    expect(wrapper.text()).toContain('Horario recurrente')
    expect(wrapper.text()).toContain('Aún no hay actividades recurrentes.')
    expect(wrapper.find('input[placeholder^="p. ej., Perder grasa"]').exists()).toBe(true)
    expect(wrapper.find('input[placeholder="mancuernas, colchoneta, cinta de correr"]').exists()).toBe(true)
    expect(wrapper.findAll('select')[0].findAll('option').map((o) => o.text())).toEqual(['—', 'Principiante', 'Intermedio', 'Avanzado'])
  })

  it('keeps the stored values of the options while their words change', async () => {
    const wrapper = await mountProfile()
    const values = () => wrapper.findAll('select')[0].findAll('option').map((o) => o.attributes('value'))
    const english = values()

    applyLocale('es')
    await nextTick()

    expect(values()).toEqual(english)
    expect(english).toEqual(['', 'beginner', 'intermediate', 'advanced'])
  })

  it('switches every word on the page at once, with no reload and without losing unsaved edits', async () => {
    const wrapper = await mountProfile()
    await wrapper.find('input[type="text"]').setValue('Run a 10k')

    applyLocale('es')
    await nextTick()

    expect(wrapper.get('h1').text()).toBe('Perfil')
    expect(wrapper.get('form button[type="submit"]').text()).toBe('Guardar perfil')
    expect((wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe('Run a 10k')

    applyLocale('en')
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('Profile')
  })

  it('words its loading state in the language on screen', async () => {
    applyLocale('es')
    vi.mocked(profileService.fetchProfile).mockReturnValue(new Promise(() => {}))
    vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
    const wrapper = mount(ProfileView)
    await nextTick()

    expect(wrapper.text()).toContain('Cargando perfil…')
  })

  it('shows the language selector even before a profile has loaded', async () => {
    vi.mocked(profileService.fetchProfile).mockReturnValue(new Promise(() => {}))
    vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
    const wrapper = mount(ProfileView)
    await nextTick()

    expect(wrapper.find('[data-testid="language-selector"]').exists()).toBe(true)
  })

  it('shows "Saved." in the language on screen after saving the profile', async () => {
    vi.mocked(profileService.updateProfile).mockResolvedValue(profile)
    applyLocale('es')
    const wrapper = await mountProfile()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Guardado.')
  })

  describe('when the profile cannot be loaded', () => {
    async function failedLoad(rejection: unknown) {
      vi.mocked(profileService.fetchProfile).mockRejectedValue(rejection)
      vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
      const wrapper = mount(ProfileView)
      await flushPromises()

      return wrapper
    }

    it('shows the English fallback with a retry, and no form (never a success)', async () => {
      const wrapper = await failedLoad(new Error('Network Error'))

      expect(wrapper.text()).toContain("Couldn't load your profile.")
      expect(wrapper.text()).toContain('Try again')
      expect(wrapper.find('form').exists()).toBe(false)
    })

    it('is re-worded when the language changes and is still a failure', async () => {
      const wrapper = await failedLoad(new Error('Network Error'))

      applyLocale('es')
      await nextTick()

      expect(wrapper.text()).toContain('No pudimos cargar tu perfil.')
      expect(wrapper.text()).toContain('Intentar de nuevo')
      expect(wrapper.find('form').exists()).toBe(false)
      expect(useProfileStore().error).toBe('No pudimos cargar tu perfil.')
    })

    it('keeps a message the server wrote, as it came', async () => {
      const wrapper = await failedLoad({ response: { status: 500, data: { message: 'Error del servidor. Inténtalo de nuevo en un momento.' } } })

      expect(wrapper.text()).toContain('Error del servidor. Inténtalo de nuevo en un momento.')
      applyLocale('en')
      await nextTick()
      expect(wrapper.text()).toContain('Error del servidor. Inténtalo de nuevo en un momento.')
    })

    it('retries when asked, whatever the language', async () => {
      applyLocale('es')
      const wrapper = await failedLoad(new Error('x'))
      vi.mocked(profileService.fetchProfile).mockResolvedValue(profile)

      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(wrapper.find('form').exists()).toBe(true)
    })
  })
})

describe('ScheduleEditor in both languages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('names the remove button after the entry and the day, in the language on screen', async () => {
    const wrapper = await mountProfile({ schedule: [entry] })
    const remove = () => wrapper.get('button[aria-label^="Remove"], button[aria-label^="Quitar"]')

    expect(remove().attributes('aria-label')).toBe('Remove Football on Tue')

    applyLocale('es')
    await nextTick()
    expect(remove().attributes('aria-label')).toBe('Quitar Fútbol (mar)')
  })

  it('names every field of the add form for a screen reader, and updates them with the language', async () => {
    const wrapper = await mountProfile()
    const names = () => wrapper.findAll('form.flex [aria-label]').map((c) => c.attributes('aria-label'))

    expect(names()).toEqual(['Day of the week', 'Start time', 'Duration in minutes', 'Intensity'])

    applyLocale('es')
    await nextTick()
    expect(names()).toEqual(['Día de la semana', 'Hora de inicio', 'Duración en minutos', 'Intensidad'])
  })

  it('offers the activity placeholder, the intensity words and the add button in the language on screen', async () => {
    applyLocale('es')
    const wrapper = await mountProfile()
    const add = wrapper.get('form.flex')

    expect(add.get('input[type="text"]').attributes('placeholder')).toBe('Actividad (p. ej., Fútbol)')
    expect(add.findAll('select')[1].findAll('option').map((o) => o.text())).toEqual(['Baja', 'Moderada', 'Alta'])
    expect(add.findAll('select')[1].findAll('option').map((o) => o.attributes('value'))).toEqual(['low', 'moderate', 'high'])
    expect(add.get('button').text()).toBe('Agregar')
  })

  it('shows the suggested activity in the language on screen but saves the canonical value when it is accepted unchanged', async () => {
    vi.mocked(scheduleService.createSchedule).mockImplementation(async (payload) => ({ id: 9, ...payload }) as TrainingSchedule)

    for (const lang of ['en', 'es'] as const) {
      vi.clearAllMocks()
      vi.mocked(scheduleService.createSchedule).mockImplementation(async (payload) => ({ id: 9, ...payload }) as TrainingSchedule)
      applyLocale(lang)
      const wrapper = await mountProfile()
      const field = () => wrapper.get('form.flex input[type="text"]').element as HTMLInputElement

      expect(field().value).toBe(lang === 'es' ? 'Fútbol' : 'Football')

      await wrapper.get('form.flex').trigger('submit')
      await flushPromises()

      // The stored identity does not depend on the language the form was shown in.
      expect(scheduleService.createSchedule).toHaveBeenCalledWith(expect.objectContaining({ activity_type: 'Football', day_of_week: 2, intensity: 'high' }))
      expect(field().value).toBe('')
    }
  })

  it('follows a language change while the suggestion is untouched, and still saves the canonical value', async () => {
    vi.mocked(scheduleService.createSchedule).mockImplementation(async (payload) => ({ id: 9, ...payload }) as TrainingSchedule)
    const wrapper = await mountProfile()
    const field = () => wrapper.get('form.flex input[type="text"]').element as HTMLInputElement

    applyLocale('es')
    await nextTick()
    expect(field().value).toBe('Fútbol')
    applyLocale('en')
    await nextTick()
    expect(field().value).toBe('Football')

    applyLocale('es')
    await nextTick()
    await wrapper.get('form.flex').trigger('submit')
    await flushPromises()

    expect(scheduleService.createSchedule).toHaveBeenCalledWith(expect.objectContaining({ activity_type: 'Football' }))
  })

  it.each(['Fútbol', 'Natación', 'football', 'Football '])('saves what the person typed exactly as typed: %j', async (typed) => {
    vi.mocked(scheduleService.createSchedule).mockImplementation(async (payload) => ({ id: 9, ...payload }) as TrainingSchedule)
    applyLocale('es')
    const wrapper = await mountProfile()
    await wrapper.get('form.flex input[type="text"]').setValue(typed)

    await wrapper.get('form.flex').trigger('submit')
    await flushPromises()

    expect(scheduleService.createSchedule).toHaveBeenCalledWith(expect.objectContaining({ activity_type: typed }))
  })

  it('does not replace what the person typed when the language changes', async () => {
    const wrapper = await mountProfile()
    const input = wrapper.get('form.flex input[type="text"]')
    await input.setValue('Natación')

    applyLocale('es')
    await nextTick()

    expect((input.element as HTMLInputElement).value).toBe('Natación')
  })

  it('shows the canonical activity in the language on screen, and anything else exactly as it was saved', async () => {
    const own: TrainingSchedule = { ...entry, id: 8, activity_type: 'Entrenamiento de fuerza', day_of_week: 3 }
    applyLocale('es')
    const wrapper = await mountProfile({ schedule: [entry, own] })

    expect(wrapper.findAll('li strong').map((s) => s.text())).toEqual(['Fútbol', 'Entrenamiento de fuerza'])

    applyLocale('en')
    await nextTick()
    expect(wrapper.findAll('li strong').map((s) => s.text())).toEqual(['Football', 'Entrenamiento de fuerza'])
  })

  it('never changes the stored value it displays: delete still targets the entry by id', async () => {
    vi.mocked(scheduleService.deleteSchedule).mockResolvedValue({} as never)
    applyLocale('es')
    const wrapper = await mountProfile({ schedule: [entry] })

    await wrapper.get('button[aria-label^="Quitar"]').trigger('click')
    await flushPromises()

    expect(scheduleService.deleteSchedule).toHaveBeenCalledWith(7)
    expect(useProfileStore().schedule.find((s) => s.id === 7)).toBeUndefined()
  })
})

describe('what a language switch must not touch', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('writes no profile, schedule or coaching preference: only an explicit language choice reaches the server', async () => {
    const wrapper = await mountProfile()

    applyLocale('es')
    await nextTick()
    applyLocale('en')
    await nextTick()

    expect(profileService.updateProfile).not.toHaveBeenCalled()
    expect(scheduleService.createSchedule).not.toHaveBeenCalled()
    expect(scheduleService.deleteSchedule).not.toHaveBeenCalled()
    expect(authService.updateLocale).not.toHaveBeenCalled()
    expect(wrapper.exists()).toBe(true)
  })
})
