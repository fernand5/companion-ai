import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { LOCALE_STORAGE_KEY, applyLocale, currentLocale } from '@/i18n'
import * as authService from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'

vi.mock('@/services/auth', () => ({ register: vi.fn(), login: vi.fn(), logout: vi.fn(), me: vi.fn(), updateLocale: vi.fn() }))

const router = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: LoginView },
      { path: '/register', name: 'register', component: RegisterView },
    ],
  })

const account = (locale: 'en' | 'es') => ({ id: 1, name: 'Ana', email: 'ana@example.com', timezone: null, locale })

type Mounted = ReturnType<typeof mount>
const label = (wrapper: Mounted, id: string) => wrapper.get(`label[for="${id}"]`).text()
const submit = (wrapper: Mounted) => wrapper.get('button[type="submit"]')

describe('LoginView in both languages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('reads exactly as it always has in English', () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })

    expect(wrapper.get('h1').text()).toBe('Welcome back')
    expect(wrapper.text()).toContain('Log in to keep training with your coach.')
    expect(label(wrapper, 'email')).toBe('Email')
    expect(label(wrapper, 'password')).toBe('Password')
    expect(submit(wrapper).text()).toBe('Log in')
    expect(wrapper.text()).toContain('No account?')
    expect(wrapper.get('a').text()).toBe('Register')
  })

  it('reads in Spanish', () => {
    applyLocale('es')
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })

    expect(wrapper.get('h1').text()).toBe('Te damos la bienvenida')
    expect(wrapper.text()).toContain('Inicia sesión para seguir entrenando con tu coach.')
    expect(label(wrapper, 'email')).toBe('Correo electrónico')
    expect(label(wrapper, 'password')).toBe('Contraseña')
    expect(submit(wrapper).text()).toBe('Iniciar sesión')
    expect(wrapper.text()).toContain('¿No tienes una cuenta?')
    expect(wrapper.get('a').text()).toBe('Regístrate')
  })

  it('switches language on the screen without reloading or losing what was typed', async () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })
    await wrapper.get('#email').setValue('ana@example.com')
    await wrapper.get('#password').setValue('secret-password')

    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Te damos la bienvenida')
    expect((wrapper.get('#email').element as HTMLInputElement).value).toBe('ana@example.com')
    expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('secret-password')

    await wrapper.get('[data-testid="language-option-en"] input').setValue(true)
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Welcome back')
  })

  it('labels every field and names every control, in both languages', () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      const wrapper = mount(LoginView, { global: { plugins: [router()] } })

      for (const input of wrapper.findAll('input[type="email"], input[type="password"]')) {
        expect(label(wrapper, input.attributes('id')!).length, `${lang}: ${input.attributes('id')}`).toBeGreaterThan(0)
      }
      expect(submit(wrapper).text().length).toBeGreaterThan(0)
    }
  })

  it('words its own pending state in the language on screen', async () => {
    applyLocale('es')
    vi.mocked(authService.login).mockReturnValue(new Promise(() => {}))
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })

    await wrapper.get('#password').setValue('x')
    await wrapper.get('form').trigger('submit')
    await nextTick()

    expect(submit(wrapper).text()).toBe('Iniciando sesión…')
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
  })

  describe('a failure the server gave no message for', () => {
    async function fail(wrapper: Mounted) {
      vi.mocked(authService.login).mockRejectedValue(new Error('Network Error'))
      await wrapper.get('#password').setValue('x')
      await wrapper.get('form').trigger('submit')
      await flushPromises()
    }

    it('is the English fallback, announced to assistive technology', async () => {
      const wrapper = mount(LoginView, { global: { plugins: [router()] } })
      await fail(wrapper)

      const alert = wrapper.get('[role="alert"]')
      expect(alert.text()).toBe("Couldn't log in — please try again.")
    })

    it('is re-worded in the language on screen when it changes, and stays a failure', async () => {
      const wrapper = mount(LoginView, { global: { plugins: [router()] } })
      await fail(wrapper)

      applyLocale('es')
      await nextTick()

      expect(wrapper.get('[role="alert"]').text()).toBe('No pudimos iniciar tu sesión. Inténtalo de nuevo.')
      expect(useAuthStore().isAuthenticated).toBe(false)

      applyLocale('en')
      await nextTick()
      expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't log in — please try again.")
    })
  })

  it('shows a message the server wrote exactly as it came, whatever the language, and never matches its text', async () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })
    vi.mocked(authService.login).mockRejectedValue({ response: { status: 422, data: { message: 'Estas credenciales no coinciden con nuestros registros.' } } })
    await wrapper.get('#password').setValue('x')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Estas credenciales no coinciden con nuestros registros.')

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[role="alert"]').text()).toBe('Estas credenciales no coinciden con nuestros registros.')
  })

  it('clears the failure when a new attempt starts', async () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })
    vi.mocked(authService.login).mockRejectedValueOnce(new Error('x')).mockReturnValueOnce(new Promise(() => {}))
    await wrapper.get('#password').setValue('x')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    await wrapper.get('form').trigger('submit')
    await nextTick()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})

describe('RegisterView in both languages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('reads exactly as it always has in English', () => {
    const wrapper = mount(RegisterView, { global: { plugins: [router()] } })

    expect(wrapper.get('h1').text()).toBe('Create your account')
    expect(wrapper.text()).toContain('Start training with your adaptive AI coach.')
    expect([label(wrapper, 'name'), label(wrapper, 'email'), label(wrapper, 'password'), label(wrapper, 'password_confirmation')]).toEqual(['Name', 'Email', 'Password', 'Confirm password'])
    expect(submit(wrapper).text()).toBe('Register')
    expect(wrapper.text()).toContain('Already have an account?')
    expect(wrapper.get('a').text()).toBe('Log in')
  })

  it('reads in Spanish', () => {
    applyLocale('es')
    const wrapper = mount(RegisterView, { global: { plugins: [router()] } })

    expect(wrapper.get('h1').text()).toBe('Crea tu cuenta')
    expect(wrapper.text()).toContain('Empieza a entrenar con tu coach de IA adaptativo.')
    expect([label(wrapper, 'name'), label(wrapper, 'email'), label(wrapper, 'password'), label(wrapper, 'password_confirmation')]).toEqual(['Nombre', 'Correo electrónico', 'Contraseña', 'Confirmar contraseña'])
    expect(submit(wrapper).text()).toBe('Registrarse')
    expect(wrapper.text()).toContain('¿Ya tienes una cuenta?')
    expect(wrapper.get('a').text()).toBe('Inicia sesión')
  })

  it('words its fallback failure in the language on screen, and keeps the server\'s own message as it came', async () => {
    const wrapper = mount(RegisterView, { global: { plugins: [router()] } })
    vi.mocked(authService.register).mockRejectedValue(new Error('Network Error'))
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Could not create your account — check the fields and try again.')

    applyLocale('es')
    await nextTick()
    expect(wrapper.get('[role="alert"]').text()).toBe('No pudimos crear tu cuenta. Revisa los campos e inténtalo de nuevo.')

    vi.mocked(authService.register).mockRejectedValue({ response: { status: 422, data: { message: 'El campo correo electrónico debe ser un correo electrónico válido.' } } })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('El campo correo electrónico debe ser un correo electrónico válido.')
  })

  it('labels every field in both languages', () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      const wrapper = mount(RegisterView, { global: { plugins: [router()] } })

      for (const input of wrapper.findAll('input[type="text"], input[type="email"], input[type="password"]')) {
        expect(label(wrapper, input.attributes('id')!).length).toBeGreaterThan(0)
      }
    }
  })
})

describe('the guest language, the account language and login', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('stays on this screen for a guest: nothing is sent to the server and the device remembers it', async () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })

    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    await flushPromises()

    expect(currentLocale()).toBe('es')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('es')
    expect(authService.updateLocale).not.toHaveBeenCalled()
  })

  it('is replaced by the saved language of the account that logs in, and never written over it', async () => {
    const r = router()
    const wrapper = mount(LoginView, { global: { plugins: [r] } })
    await wrapper.get('[data-testid="language-option-es"] input').setValue(true) // the guest chose Spanish
    vi.mocked(authService.login).mockResolvedValue({ user: account('en'), token: 't' }) // the account is English

    await wrapper.get('#password').setValue('secret')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(currentLocale()).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    expect(wrapper.get('h1').text()).toBe('Welcome back')
    expect(authService.updateLocale).not.toHaveBeenCalled()
    expect(authService.login).toHaveBeenCalledWith({ email: 'demo@example.com', password: 'secret' })
  })

  it('also works the other way: an account saved in Spanish is shown in Spanish after an English guest logs in', async () => {
    const wrapper = mount(LoginView, { global: { plugins: [router()] } })
    vi.mocked(authService.login).mockResolvedValue({ user: account('es'), token: 't' })

    await wrapper.get('#password').setValue('secret')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(currentLocale()).toBe('es')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('es')
    expect(authService.updateLocale).not.toHaveBeenCalled()
  })

  it('becomes the new account\'s language only at registration', async () => {
    const wrapper = mount(RegisterView, { global: { plugins: [router()] } })
    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    expect(authService.register).not.toHaveBeenCalled()
    vi.mocked(authService.register).mockResolvedValue({ user: account('es'), token: 't' })

    await wrapper.get('#name').setValue('Ana')
    await wrapper.get('#email').setValue('ana@example.com')
    await wrapper.get('#password').setValue('password123')
    await wrapper.get('#password_confirmation').setValue('password123')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(authService.register).toHaveBeenCalledWith(expect.objectContaining({ locale: 'es' }))
    expect(authService.updateLocale).not.toHaveBeenCalled()
  })

  it('survives a refresh: the saved language is shown again when the session is restored', async () => {
    localStorage.setItem('auth_token', 't')
    vi.mocked(authService.me).mockResolvedValue(account('es'))
    setActivePinia(createPinia())

    await useAuthStore().fetchCurrentUser()

    expect(currentLocale()).toBe('es')
    expect(authService.updateLocale).not.toHaveBeenCalled()
  })

  it('leaves English as the default and the fallback for a visitor who never chose', () => {
    expect(currentLocale()).toBe('en')
    expect(mount(LoginView, { global: { plugins: [router()] } }).get('h1').text()).toBe('Welcome back')
  })
})
