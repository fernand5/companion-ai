import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import App from '@/App.vue'
import ErrorNotice from '@/components/ErrorNotice.vue'
import { applyLocale } from '@/i18n'
import * as authService from '@/services/auth'

vi.mock('@/services/auth', () => ({ register: vi.fn(), login: vi.fn(), logout: vi.fn(), me: vi.fn(), updateLocale: vi.fn() }))

describe('ErrorNotice', () => {
  it('shows the message it is given and a retry button in the language on screen', async () => {
    const retry = vi.fn()
    const wrapper = mount(ErrorNotice, { props: { message: 'Something happened', onRetry: retry } })

    expect(wrapper.text()).toContain('Something happened')
    expect(wrapper.get('button').text()).toBe('Try again')

    applyLocale('es')
    await nextTick()

    expect(wrapper.get('button').text()).toBe('Intentar de nuevo')
    expect(wrapper.text()).toContain('Something happened') // the message is not touched: it is whatever the caller says

    await wrapper.get('button').trigger('click')
    expect(retry).toHaveBeenCalledOnce()
  })

  it('offers no retry button when there is nothing to retry', () => {
    expect(mount(ErrorNotice, { props: { message: 'x' } }).find('button').exists()).toBe(false)
  })

  it('has a visible name on its only control, in both languages', () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      expect(mount(ErrorNotice, { props: { message: 'x', onRetry: () => {} } }).get('button').text().length).toBeGreaterThan(0)
    }
  })
})

describe('App while the session is being restored', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.setItem('auth_token', 'token')
    vi.mocked(authService.me).mockReturnValue(new Promise(() => {}))
  })

  const app = async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'dashboard', component: { template: '<div />' } }] })
    await router.push('/')

    return mount(App, { global: { plugins: [router] } })
  }

  it('says it is loading, announced as a status, in English', async () => {
    const wrapper = await app()

    expect(wrapper.get('[role="status"]').text()).toBe('Loading…')
  })

  it('says it in Spanish, and follows a language change', async () => {
    applyLocale('es')
    const wrapper = await app()
    expect(wrapper.get('[role="status"]').text()).toBe('Cargando…')

    applyLocale('en')
    await nextTick()
    expect(wrapper.get('[role="status"]').text()).toBe('Loading…')
  })

  it('titles the browser tab while loading', async () => {
    applyLocale('es')
    await app()

    expect(document.title).toBe('Inicio · Companion AI Coach')
  })
})
