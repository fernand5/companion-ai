import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import CoachingStyleSettings from '@/components/CoachingStyleSettings.vue'
import { COACH_STYLE_VALUES, DEFAULT_COACHING, RESPONSE_DETAIL_VALUES } from '@/constants/coaching'
import { applyLocale } from '@/i18n'
import { en } from '@/i18n/messages/en'
import { es } from '@/i18n/messages/es'
import * as profileService from '@/services/profile'
import * as scheduleService from '@/services/schedule'
import * as authService from '@/services/auth'
import { useProfileStore } from '@/stores/profile'
import type { CoachStyle, FitnessProfile, ResponseDetail } from '@/types'

vi.mock('@/services/profile', () => ({ fetchProfile: vi.fn(), updateProfile: vi.fn() }))
vi.mock('@/services/schedule', () => ({ fetchSchedule: vi.fn(), createSchedule: vi.fn(), deleteSchedule: vi.fn() }))
vi.mock('@/services/auth', () => ({ register: vi.fn(), login: vi.fn(), logout: vi.fn(), me: vi.fn(), updateLocale: vi.fn() }))

const profile = (overrides: Partial<FitnessProfile> = {}): FitnessProfile => ({
  id: 1, height_cm: 180, weight_kg: 80, age: 30, sex: null, fitness_level: 'intermediate', primary_goal: 'Lose fat', secondary_goal: null,
  equipment: [], preferred_training_days: [], preferred_training_duration_minutes: null, coach_style: 'balanced', response_detail: 'standard', updated_at: null, ...overrides,
})

async function mountLoaded(saved: FitnessProfile = profile()) {
  vi.mocked(profileService.fetchProfile).mockResolvedValue(saved)
  vi.mocked(scheduleService.fetchSchedule).mockResolvedValue([])
  const store = useProfileStore()
  await store.load()

  return { store, wrapper: mount(CoachingStyleSettings) }
}

type Wrapper = ReturnType<typeof mount>
const radio = (wrapper: Wrapper, name: string, value: string) => wrapper.get(`input[name="${name}"][value="${value}"]`)
const styleLabels = (wrapper: Wrapper) => wrapper.findAll('label:has(input[name="coach-style"])').map((l) => l.get('span span').text())
const detailLabels = (wrapper: Wrapper) => wrapper.findAll('label:has(input[name="response-detail"])').map((l) => l.get('span span').text())

describe('CoachingStyleSettings in both languages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads exactly as it always has in English', async () => {
    const { wrapper } = await mountLoaded()

    expect(wrapper.get('h2').text()).toBe('Your coach')
    expect(wrapper.text()).toContain('Choose how your coach talks to you. This changes the wording only: your plan, your recovery and the safety guidance stay the same.')
    expect(wrapper.get('[data-testid="saved-values"]').text()).toBe('Saved: Balanced style, Standard detail')
    expect(styleLabels(wrapper)).toEqual(['Supportive', 'Balanced', 'Intense'])
    expect(detailLabels(wrapper)).toEqual(['Concise', 'Standard', 'Detailed'])
    expect(wrapper.text()).toContain('Coaching style')
    expect(wrapper.text()).toContain('Response detail')
    expect(wrapper.text()).toContain('Detail changes length only. Pain and safety cautions, and what hasn’t been recorded, are never left out.')
    expect(wrapper.get('[data-testid="sample"]').text()).toContain('Example reply')
    expect(wrapper.get('[data-testid="sample"]').text()).toContain('You: “I missed Tuesday’s workout and my knee feels a bit sore.”')
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(`Coach: “${en.coaching.samples.balanced.standard}”`)
    expect(wrapper.get('[data-testid="sample"]').text()).toContain('A fixed example, not written by the AI.')
    expect(wrapper.get('button').text()).toBe('Save coaching style')
  })

  it('reads in Spanish: labels, descriptions, legends, saved values, the example and the button', async () => {
    applyLocale('es')
    const { wrapper } = await mountLoaded()

    expect(wrapper.get('h2').text()).toBe('Tu coach')
    expect(wrapper.get('[data-testid="saved-values"]').text()).toBe('Guardado: estilo Equilibrado, detalle Estándar')
    expect(styleLabels(wrapper)).toEqual(['De apoyo', 'Equilibrado', 'Intenso'])
    expect(detailLabels(wrapper)).toEqual(['Conciso', 'Estándar', 'Detallado'])
    expect(wrapper.text()).toContain('Estilo de coaching')
    expect(wrapper.text()).toContain('Nivel de detalle de las respuestas')
    expect(wrapper.text()).toContain(es.coaching.styles.intense.description)
    expect(wrapper.text()).toContain(es.coaching.details.detailed.description)
    expect(wrapper.get('[data-testid="sample"]').text()).toContain('Tú: “Me perdí el entrenamiento del martes y me duele un poco la rodilla.”')
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(`Coach: “${es.coaching.samples.balanced.standard}”`)
    expect(wrapper.get('[data-testid="sample"]').text()).toContain('Un ejemplo fijo, no escrito por la IA.')
    expect(wrapper.get('button').text()).toBe('Guardar estilo de coaching')
  })

  it('updates every label, description, badge and example the moment the language changes, without a reload', async () => {
    const { wrapper } = await mountLoaded()
    await radio(wrapper, 'coach-style', 'intense').setValue(true)
    await radio(wrapper, 'response-detail', 'detailed').setValue(true)
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(en.coaching.samples.intense.detailed)

    applyLocale('es')
    await nextTick()

    expect(styleLabels(wrapper)).toEqual(['De apoyo', 'Equilibrado', 'Intenso'])
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(es.coaching.samples.intense.detailed)
    expect(wrapper.get('[data-testid="sample"]').text()).not.toContain(en.coaching.samples.intense.detailed)
    expect(wrapper.get('[data-testid="saved-values"]').text()).toBe('Guardado: estilo Equilibrado, detalle Estándar')
    expect(wrapper.findAll('[data-testid="saved-badge"]').map((b) => b.text())).toEqual(['Guardado', 'Guardado'])
    expect(wrapper.get('button').text()).toBe('Guardar estilo de coaching')

    applyLocale('en')
    await nextTick()
    expect(wrapper.get('[data-testid="sample"]').text()).toContain(en.coaching.samples.intense.detailed)
    expect(wrapper.get('button').text()).toBe('Save coaching style')
  })

  it('keeps the selection (unsaved) across a language change', async () => {
    const { wrapper } = await mountLoaded()
    await radio(wrapper, 'coach-style', 'supportive').setValue(true)

    applyLocale('es')
    await nextTick()

    expect((radio(wrapper, 'coach-style', 'supportive').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
  })

  it('has exactly the stable identifiers, in the same order, whatever the language', async () => {
    expect([...COACH_STYLE_VALUES]).toEqual(['supportive', 'balanced', 'intense'])
    expect([...RESPONSE_DETAIL_VALUES]).toEqual(['concise', 'standard', 'detailed'])
    expect(DEFAULT_COACHING).toEqual({ coach_style: 'balanced', response_detail: 'standard' })

    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      const { wrapper } = await mountLoaded()
      const values = (name: string) => wrapper.findAll(`input[name="${name}"]`).map((i) => i.attributes('value'))

      expect(values('coach-style')).toEqual(['supportive', 'balanced', 'intense'])
      expect(values('response-detail')).toEqual(['concise', 'standard', 'detailed'])
    }
  })

  it('saves the same identifiers in either language and touches nothing else, the language included', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      vi.mocked(profileService.updateProfile).mockResolvedValue(profile({ coach_style: 'intense', response_detail: 'concise' }))
      const { wrapper } = await mountLoaded()
      await radio(wrapper, 'coach-style', 'intense').setValue(true)
      await radio(wrapper, 'response-detail', 'concise').setValue(true)

      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(profileService.updateProfile).toHaveBeenLastCalledWith({ coach_style: 'intense', response_detail: 'concise' })
      expect(authService.updateLocale).not.toHaveBeenCalled()
    }
  })

  it('words "Saved." and the saving state in the language on screen', async () => {
    applyLocale('es')
    vi.mocked(profileService.updateProfile).mockResolvedValue(profile({ coach_style: 'intense' }))
    const { wrapper } = await mountLoaded()
    await radio(wrapper, 'coach-style', 'intense').setValue(true)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toBe('Guardado.')
  })

  describe('when saving fails', () => {
    async function failedSave(rejection: unknown) {
      const { wrapper, store } = await mountLoaded()
      vi.mocked(profileService.updateProfile).mockRejectedValue(rejection)
      await radio(wrapper, 'coach-style', 'intense').setValue(true)
      await wrapper.get('button').trigger('click')
      await flushPromises()

      return { wrapper, store }
    }

    it('shows the fallback in the language on screen and re-words it when that changes, never as success', async () => {
      const { wrapper, store } = await failedSave(new Error('Network Error'))
      expect(wrapper.text()).toContain("Couldn't save your coaching style. Please try again.")

      applyLocale('es')
      await nextTick()

      expect(wrapper.text()).toContain('No pudimos guardar tu estilo de coaching. Inténtalo de nuevo.')
      expect(wrapper.find('[role="status"]').exists()).toBe(false)
      expect(store.coaching.coach_style).toBe('balanced') // nothing was saved
    })

    it('shows a server message as it came', async () => {
      const { wrapper } = await failedSave({ response: { status: 422, data: { message: 'El valor seleccionado en estilo del coach no es válido.' } } })

      applyLocale('en')
      await nextTick()
      expect(wrapper.text()).toContain('El valor seleccionado en estilo del coach no es válido.')
    })
  })
})

describe('the nine coaching samples', () => {
  const styles: CoachStyle[] = ['supportive', 'balanced', 'intense']
  const details: ResponseDetail[] = ['concise', 'standard', 'detailed']

  it('exist in both languages for every style and detail', () => {
    for (const style of styles) {
      for (const detail of details) {
        expect(en.coaching.samples[style][detail].length, `en ${style}.${detail}`).toBeGreaterThan(20)
        expect(es.coaching.samples[style][detail].length, `es ${style}.${detail}`).toBeGreaterThan(20)
        expect(es.coaching.samples[style][detail]).not.toBe(en.coaching.samples[style][detail])
      }
    }
    expect(styles.length * details.length).toBe(9)
  })

  /**
   * The safety meaning each sample must carry in BOTH languages. The English pattern and the Spanish one stand for the
   * same instruction; a sample that loses one is a different message, whatever its tone.
   */
  const meaning: Array<{ what: string; en: RegExp; es: RegExp; in: Array<[CoachStyle, ResponseDetail]> }> = [
    { what: 'rest the sore knee', en: /rest/i, es: /descansa/i, in: styles.flatMap((s) => details.map((d) => [s, d] as [CoachStyle, ResponseDetail])) },
    { what: 'do not make up the missed session / no doubling up', en: /skip the catch-up|no need to make (it )?up|no catching up|no doubling up|not doing extra|as planned/i, es: /olvídate de recuperarla|no hace falta repon|sin recuperar nada|sin duplicar|no hacer de más|como (está|estaba) planeada?/i, in: styles.flatMap((s) => details.map((d) => [s, d] as [CoachStyle, ResponseDetail])).filter(([s, d]) => !(s === 'supportive' && d === 'concise')) },
    { what: 'check the knee tomorrow / see how it feels', en: /tomorrow/i, es: /mañana/i, in: [['supportive', 'standard'], ['supportive', 'detailed'], ['balanced', 'concise'], ['balanced', 'standard'], ['balanced', 'detailed'], ['intense', 'standard'], ['intense', 'detailed']] },
    { what: 'keep the planned loads where they are, no extra', en: /loads/i, es: /cargas/i, in: [['balanced', 'detailed'], ['intense', 'standard'], ['intense', 'detailed']] },
    { what: 'get it looked at if it persists or gets worse', en: /professional|looked at/i, es: /profesional|que la revisen/i, in: [['supportive', 'detailed'], ['balanced', 'detailed'], ['intense', 'detailed']] },
    { what: 'no training through knee pain', en: /no training through knee pain/i, es: /sin entrenar con dolor de rodilla/i, in: [['intense', 'concise'], ['intense', 'standard'], ['intense', 'detailed']] },
    { what: 'adjust the plan rather than push through', en: /adjust the plan rather than push|lower-impact|keep (it|things) light/i, es: /ajustamos el plan en lugar de forzar|menor impacto|ligera|ligero/i, in: [['balanced', 'detailed'], ['intense', 'detailed'], ['balanced', 'standard'], ['supportive', 'detailed']] },
  ]

  it.each(meaning.map((m) => [m.what, m] as const))('every sample that says it in English says it in Spanish: %s', (_what, m) => {
    for (const [style, detail] of m.in) {
      expect(en.coaching.samples[style][detail], `en ${style}.${detail}`).toMatch(m.en)
      expect(es.coaching.samples[style][detail], `es ${style}.${detail}`).toMatch(m.es)
    }
  })

  /** Every place `phrase` occurs, is it directly after one of the negations? (Advice NOT to do something is what these samples give.) */
  const alwaysNegated = (text: string, phrase: RegExp, negations: RegExp) =>
    [...text.matchAll(new RegExp(phrase, 'gi'))].every((m) => negations.test(text.slice(Math.max(0, (m.index ?? 0) - 12), m.index)))

  it('never tells the person to make up the missed session, train through pain or add load, in either language', () => {
    for (const style of styles) {
      for (const detail of details) {
        const english = en.coaching.samples[style][detail]
        const spanish = es.coaching.samples[style][detail]

        // Training through pain and doubling up appear only as things NOT to do.
        expect(alwaysNegated(english, /training through/, /\bno $/i), `en ${style}.${detail}`).toBe(true)
        expect(alwaysNegated(english, /doubling up/, /\bno $/i), `en ${style}.${detail}`).toBe(true)
        expect(alwaysNegated(spanish, /entrenar con dolor/, /\bsin $/i), `es ${style}.${detail}`).toBe(true)
        expect(alwaysNegated(spanish, /duplicar/, /\bsin $/i), `es ${style}.${detail}`).toBe(true)

        for (const text of [english, spanish]) {
          expect(text, `${style}.${detail}`).not.toMatch(/\b(add|increase|raise) (more )?(weight|load)|\b(aumenta|sube|agrega)\w* (el peso|la carga|más peso)|extra volume now would help/i)
        }
      }
    }
  })

  it('says the same thing about the prompt: a missed Tuesday workout and a sore knee', () => {
    expect(en.coaching.samplePrompt).toMatch(/Tuesday/)
    expect(en.coaching.samplePrompt).toMatch(/knee/)
    expect(es.coaching.samplePrompt).toMatch(/martes/)
    expect(es.coaching.samplePrompt).toMatch(/rodilla/)
  })

  it('names the same weekday in every sample that names one (Thursday / jueves, Tuesday / martes)', () => {
    for (const style of styles) {
      for (const detail of details) {
        const english = en.coaching.samples[style][detail]
        const spanish = es.coaching.samples[style][detail]

        expect(/Thursday/.test(english), `${style}.${detail} Thursday`).toBe(/jueves/.test(spanish))
        expect(/Tuesday/.test(english), `${style}.${detail} Tuesday`).toBe(/martes/.test(spanish))
      }
    }
  })
})
