import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { applyLocale, currentLocale } from '@/i18n'
import * as authService from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import { useLocaleStore } from '@/stores/locale'
import type { User } from '@/types'

vi.mock('@/services/auth', () => ({
  register: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
  updateLocale: vi.fn(),
}))

const user = (locale: User['locale'] = 'en'): User => ({ id: 1, name: 'Ana', email: 'ana@example.com', timezone: 'America/Bogota', locale })

describe('locale store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  describe('as a guest', () => {
    it('changes the screen and calls nothing on the server', async () => {
      const store = useLocaleStore()

      await store.choose('es')

      expect(currentLocale()).toBe('es')
      expect(store.locale).toBe('es')
      expect(authService.updateLocale).not.toHaveBeenCalled()
    })

    it('ignores a language that is not supported', async () => {
      const store = useLocaleStore()

      await store.choose('fr' as never)

      expect(currentLocale()).toBe('en')
    })
  })

  describe('signed in', () => {
    function authenticated(locale: User['locale'] = 'en') {
      const auth = useAuthStore()
      auth.user = user(locale)
      auth.token = 'token'
      applyLocale(locale)
      return auth
    }

    it('updates the screen at once, then saves it on the account', async () => {
      const auth = authenticated('en')
      let finish!: (u: User) => void
      vi.mocked(authService.updateLocale).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const store = useLocaleStore()

      const pending = store.choose('es')

      expect(currentLocale()).toBe('es') // optimistic: no waiting for the server
      expect(store.saving).toBe(true)

      finish(user('es'))
      await pending

      expect(authService.updateLocale).toHaveBeenCalledWith('es')
      expect(auth.user?.locale).toBe('es')
      expect(store.saving).toBe(false)
      expect(store.justSaved).toBe(true)
      expect(store.failed).toBe(false)
    })

    it('goes back to the saved language and says so when saving fails', async () => {
      const auth = authenticated('en')
      vi.mocked(authService.updateLocale).mockRejectedValue(new Error('network'))
      const store = useLocaleStore()

      await store.choose('es')

      expect(currentLocale()).toBe('en')
      expect(auth.user?.locale).toBe('en')
      expect(store.failed).toBe(true)
      expect(store.saving).toBe(false)
    })

    it('does not send a request when the language is already the saved one', async () => {
      authenticated('es')
      const store = useLocaleStore()

      await store.choose('es')

      expect(authService.updateLocale).not.toHaveBeenCalled()
    })

    it('ignores a second choice while one is being saved', async () => {
      authenticated('en')
      let finish!: (u: User) => void
      vi.mocked(authService.updateLocale).mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const store = useLocaleStore()

      const first = store.choose('es')
      await store.choose('en')

      expect(authService.updateLocale).toHaveBeenCalledTimes(1)
      finish(user('es'))
      await first
      expect(currentLocale()).toBe('es')
    })

    it('clears the saved/failed status', async () => {
      authenticated('en')
      vi.mocked(authService.updateLocale).mockRejectedValue(new Error('x'))
      const store = useLocaleStore()
      await store.choose('es')

      store.clearStatus()

      expect(store.failed).toBe(false)
      expect(store.justSaved).toBe(false)
    })
  })
})
