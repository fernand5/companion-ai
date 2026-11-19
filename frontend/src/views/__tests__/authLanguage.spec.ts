import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'

import * as authService from '@/services/auth'
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

describe('language on the sign-in and sign-up screens', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('lets a visitor pick a language on the login screen without calling the server', async () => {
    const r = router()
    const wrapper = mount(LoginView, { global: { plugins: [r] } })

    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    await flushPromises()

    expect(document.documentElement.lang).toBe('es')
    expect(authService.updateLocale).not.toHaveBeenCalled()
    expect(authService.login).not.toHaveBeenCalled()
  })

  it('logs in with credentials only, whatever language the screen is showing', async () => {
    const r = router()
    vi.mocked(authService.login).mockResolvedValue({ user: { id: 1, name: 'A', email: 'a@example.com', timezone: null, locale: 'en' }, token: 't' })
    const wrapper = mount(LoginView, { global: { plugins: [r] } })

    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    await wrapper.get('#email').setValue('a@example.com')
    await wrapper.get('#password').setValue('secret')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(authService.login).toHaveBeenCalledWith({ email: 'a@example.com', password: 'secret' })
    // The account was saved in English, so that is what the signed-in app shows.
    expect(document.documentElement.lang).toBe('en')
  })

  it('registers with the language picked on the sign-up screen', async () => {
    const r = router()
    vi.mocked(authService.register).mockResolvedValue({ user: { id: 1, name: 'A', email: 'a@example.com', timezone: null, locale: 'es' }, token: 't' })
    const wrapper = mount(RegisterView, { global: { plugins: [r] } })

    await wrapper.get('[data-testid="language-option-es"] input').setValue(true)
    await wrapper.get('#name').setValue('Ana')
    await wrapper.get('#email').setValue('a@example.com')
    await wrapper.get('#password').setValue('secret123')
    await wrapper.get('#password_confirmation').setValue('secret123')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(authService.register).toHaveBeenCalledWith(expect.objectContaining({ locale: 'es', email: 'a@example.com' }))
  })
})
