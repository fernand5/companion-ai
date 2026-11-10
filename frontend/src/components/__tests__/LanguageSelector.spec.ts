import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import LanguageSelector from '@/components/LanguageSelector.vue'
import { applyLocale, currentLocale } from '@/i18n'
import * as authService from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

vi.mock('@/services/auth', () => ({
  register: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
  updateLocale: vi.fn(),
}))

const user = (locale: User['locale']): User => ({ id: 1, name: 'Ana', email: 'ana@example.com', timezone: null, locale })

function signIn(locale: User['locale']) {
  const auth = useAuthStore()
  auth.user = user(locale)
  auth.token = 'token'
  applyLocale(locale)
}

const radio = (wrapper: ReturnType<typeof mount>, locale: string) => wrapper.get(`[data-testid="language-option-${locale}"] input`)

describe('LanguageSelector', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('offers English and Spanish, each written in its own language', () => {
    const wrapper = mount(LanguageSelector)

    expect(wrapper.get('[data-testid="language-option-en"]').text()).toBe('English')
    expect(wrapper.get('[data-testid="language-option-es"]').text()).toBe('Español')
    expect(wrapper.findAll('input[type="radio"]')).toHaveLength(2)
  })

  it('has English selected for someone who never chose', () => {
    const wrapper = mount(LanguageSelector)

    expect((radio(wrapper, 'en').element as HTMLInputElement).checked).toBe(true)
    expect((radio(wrapper, 'es').element as HTMLInputElement).checked).toBe(false)
  })

  it('switches the interface text and <html lang> immediately, without a reload', async () => {
    const wrapper = mount(LanguageSelector, { props: { scope: 'guest' } })
    expect(wrapper.get('h2').text()).toContain('Language')

    await radio(wrapper, 'es').setValue(true)
    await flushPromises()

    expect(wrapper.get('h2').text()).toContain('Idioma')
    expect(document.documentElement.lang).toBe('es')
    expect((radio(wrapper, 'es').element as HTMLInputElement).checked).toBe(true)

    await radio(wrapper, 'en').setValue(true)
    await flushPromises()

    expect(wrapper.get('h2').text()).toContain('Language')
    expect(document.documentElement.lang).toBe('en')
  })

  it('does not save anything for a guest', async () => {
    const wrapper = mount(LanguageSelector, { props: { scope: 'guest' } })

    await radio(wrapper, 'es').setValue(true)
    await flushPromises()

    expect(authService.updateLocale).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Tu cuenta conserva su propio idioma guardado')
  })

  it('explains on the sign-up screen that the choice becomes the account language', () => {
    expect(mount(LanguageSelector, { props: { scope: 'register' } }).text()).toContain('account language')
  })

  it('saves a signed-in user’s choice on the account and shows it was saved', async () => {
    signIn('en')
    vi.mocked(authService.updateLocale).mockResolvedValue(user('es'))
    const wrapper = mount(LanguageSelector, { props: { scope: 'account' } })

    await radio(wrapper, 'es').setValue(true)
    await flushPromises()

    expect(authService.updateLocale).toHaveBeenCalledWith('es')
    expect(currentLocale()).toBe('es')
    expect(wrapper.get('[data-testid="language-saved"]').text()).toBe('Guardado')
  })

  it('shows an error and returns to the saved language when saving fails', async () => {
    signIn('en')
    vi.mocked(authService.updateLocale).mockRejectedValue(new Error('offline'))
    const wrapper = mount(LanguageSelector, { props: { scope: 'account' } })

    await radio(wrapper, 'es').setValue(true)
    await flushPromises()

    expect(wrapper.get('[data-testid="language-error"]').attributes('role')).toBe('alert')
    expect(currentLocale()).toBe('en')
    expect((radio(wrapper, 'en').element as HTMLInputElement).checked).toBe(true)
  })

  it('disables the choices while saving so two saves cannot cross', async () => {
    signIn('en')
    let finish!: (u: User) => void
    vi.mocked(authService.updateLocale).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const wrapper = mount(LanguageSelector, { props: { scope: 'account' } })

    await radio(wrapper, 'es').setValue(true)

    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[role="status"]').text()).toBe('Guardando…')

    finish(user('es'))
    await flushPromises()

    expect(wrapper.get('fieldset').attributes('disabled')).toBeUndefined()
  })

  it('is accessible: a labelled region and a radio group with a legend', () => {
    const wrapper = mount(LanguageSelector)
    const section = wrapper.get('section')

    expect(section.attributes('aria-labelledby')).toBe(wrapper.get('h2').attributes('id'))
    expect(wrapper.find('legend').exists()).toBe(true)
    expect(wrapper.findAll('input[type="radio"]').every((input) => input.attributes('name') === 'app-language')).toBe(true)
  })

  it('lays out for phones: two equal columns, 44px-tall targets, no fixed widths', () => {
    const wrapper = mount(LanguageSelector)
    const group = wrapper.get('fieldset')

    expect(group.classes()).toContain('grid-cols-2')
    for (const label of wrapper.findAll('label')) {
      expect(label.classes()).toContain('min-h-11')
      expect(label.classes().some((c) => /^w-\d|^w-\[/.test(c))).toBe(false)
    }
  })
})
