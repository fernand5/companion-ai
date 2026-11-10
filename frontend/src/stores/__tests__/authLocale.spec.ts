import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { applyLocale, currentLocale } from '@/i18n'
import * as authService from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

vi.mock('@/services/auth', () => ({
  register: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(async () => undefined),
  me: vi.fn(),
  updateLocale: vi.fn(),
}))

const account = (locale: User['locale']): User => ({ id: 1, name: 'Ana', email: 'ana@example.com', timezone: null, locale })

describe('language on sign-in and sign-up', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('shows the account’s saved language after login, replacing what the guest picked', async () => {
    applyLocale('en') // the guest picked English on the sign-in screen
    vi.mocked(authService.login).mockResolvedValue({ user: account('es'), token: 't' })

    await useAuthStore().login({ email: 'ana@example.com', password: 'secret' })

    expect(currentLocale()).toBe('es')
  })

  it('never saves the guest’s pick over the account’s saved language', async () => {
    applyLocale('en')
    vi.mocked(authService.login).mockResolvedValue({ user: account('es'), token: 't' })

    await useAuthStore().login({ email: 'ana@example.com', password: 'secret' })

    expect(authService.updateLocale).not.toHaveBeenCalled()
    // The login request carries credentials only: no language field to persist.
    expect(authService.login).toHaveBeenCalledWith({ email: 'ana@example.com', password: 'secret' })
  })

  it('makes the language chosen on the sign-up screen the new account’s language', async () => {
    applyLocale('es')
    vi.mocked(authService.register).mockResolvedValue({ user: account('es'), token: 't' })

    await useAuthStore().register({ name: 'Ana', email: 'ana@example.com', password: 'secret123', password_confirmation: 'secret123' })

    expect(authService.register).toHaveBeenCalledWith(expect.objectContaining({ locale: 'es' }))
    expect(currentLocale()).toBe('es')
  })

  it('registers in English when the visitor never chose', async () => {
    vi.mocked(authService.register).mockResolvedValue({ user: account('en'), token: 't' })

    await useAuthStore().register({ name: 'Ana', email: 'ana@example.com', password: 'secret123', password_confirmation: 'secret123' })

    expect(authService.register).toHaveBeenCalledWith(expect.objectContaining({ locale: 'en' }))
  })

  it('adopts the saved language when the page is reloaded with a session', async () => {
    localStorage.setItem('auth_token', 't')
    applyLocale('en')
    vi.mocked(authService.me).mockResolvedValue(account('es'))
    setActivePinia(createPinia())

    await useAuthStore().fetchCurrentUser()

    expect(currentLocale()).toBe('es')
  })

  it('keeps the current language when the server sends none (an older API)', async () => {
    applyLocale('es')
    vi.mocked(authService.login).mockResolvedValue({ user: { ...account('en'), locale: undefined } as unknown as User, token: 't' })

    await useAuthStore().login({ email: 'ana@example.com', password: 'secret' })

    expect(currentLocale()).toBe('es')
  })

  it('ignores a language the server sends that this app does not support', async () => {
    applyLocale('en')
    vi.mocked(authService.login).mockResolvedValue({ user: account('fr' as never), token: 't' })

    await useAuthStore().login({ email: 'ana@example.com', password: 'secret' })

    expect(currentLocale()).toBe('en')
  })

  it('a different user signing in on the same device gets their own language', async () => {
    vi.mocked(authService.login).mockResolvedValueOnce({ user: account('es'), token: 't1' })
    const store = useAuthStore()
    await store.login({ email: 'ana@example.com', password: 'secret' })
    await store.logout()

    vi.mocked(authService.login).mockResolvedValueOnce({ user: { ...account('en'), id: 2 }, token: 't2' })
    await store.login({ email: 'ben@example.com', password: 'secret' })

    expect(currentLocale()).toBe('en')
  })
})
