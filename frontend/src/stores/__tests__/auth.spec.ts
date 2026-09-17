import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/auth'

vi.mock('@/services/auth', () => ({
  register: vi.fn(),
  login: vi.fn(async () => ({
    user: { id: 1, name: 'Alonso', email: 'alonso@example.com' },
    token: 'test-token',
  })),
  logout: vi.fn(async () => undefined),
  me: vi.fn(),
}))

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
  })

  it('starts unauthenticated', () => {
    const store = useAuthStore()
    expect(store.isAuthenticated).toBe(false)
  })

  it('sets the session and persists the token on login', async () => {
    const store = useAuthStore()

    await store.login({ email: 'alonso@example.com', password: 'secret' })

    expect(store.isAuthenticated).toBe(true)
    expect(store.user?.email).toBe('alonso@example.com')
    expect(localStorage.getItem('auth_token')).toBe('test-token')
  })

  it('clears the session on logout', async () => {
    const store = useAuthStore()
    await store.login({ email: 'alonso@example.com', password: 'secret' })

    await store.logout()

    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
    expect(localStorage.getItem('auth_token')).toBeNull()
  })

  it('exposes clearSession directly, for reacting to a 401 without calling the logout endpoint', async () => {
    const store = useAuthStore()
    await store.login({ email: 'alonso@example.com', password: 'secret' })

    store.clearSession()

    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
    expect(localStorage.getItem('auth_token')).toBeNull()
  })
})
