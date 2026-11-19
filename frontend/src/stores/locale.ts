import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { applyLocale, currentLocale, isSupportedLocale, type AppLocale } from '@/i18n'
import * as authService from '@/services/auth'
import { useAuthStore } from '@/stores/auth'

/**
 * Switching the interface language.
 *
 * Two different things share one picker:
 *  - A guest (sign-in / sign-up screens): the language only changes this screen, in this browser. Nothing is
 *    sent to the server, so it can never overwrite an existing account's saved language. (Registering is the one
 *    place the choice becomes an account's language; see the auth store.)
 *  - A signed-in user: the screen changes at once, then the choice is saved on the account so it follows them
 *    to other devices. If saving fails the screen goes back to the saved language and says so.
 */
export const useLocaleStore = defineStore('locale', () => {
  const saving = ref(false)
  const failed = ref(false)
  const justSaved = ref(false)

  /** The language on screen right now. Reading it inside a computed or render re-runs that on change. */
  const locale = computed<AppLocale>(() => currentLocale())

  async function choose(next: AppLocale): Promise<void> {
    if (!isSupportedLocale(next) || saving.value) return

    const auth = useAuthStore()
    failed.value = false
    justSaved.value = false

    if (!auth.isAuthenticated || !auth.user) {
      applyLocale(next)

      return
    }

    const saved = isSupportedLocale(auth.user.locale) ? auth.user.locale : currentLocale()

    if (next === currentLocale() && next === saved) return

    saving.value = true
    applyLocale(next)

    try {
      const account = await authService.updateLocale(next)

      if (auth.user) auth.user = { ...auth.user, locale: account.locale }
      justSaved.value = true
    } catch {
      applyLocale(saved)
      failed.value = true
    } finally {
      saving.value = false
    }
  }

  function clearStatus() {
    failed.value = false
    justSaved.value = false
  }

  return { locale, saving, failed, justSaved, choose, clearStatus }
})
