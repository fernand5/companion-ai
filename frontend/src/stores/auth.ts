import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { applyLocale, currentLocale, isSupportedLocale } from '@/i18n'
import * as authService from '@/services/auth'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(localStorage.getItem('auth_token'))
  const initializing = ref(true)

  const isAuthenticated = computed(() => !!token.value)

  /**
   * The signed-in user's saved language decides what is shown. It replaces whatever a guest picked on
   * the sign-in screen, and nothing the guest picked is ever written back over it.
   */
  function adoptSavedLocale(account: User) {
    if (isSupportedLocale(account.locale)) applyLocale(account.locale)
  }

  function setSession(newUser: User, newToken: string) {
    user.value = newUser
    adoptSavedLocale(newUser)
    token.value = newToken
    localStorage.setItem('auth_token', newToken)
  }

  function clearSession() {
    user.value = null
    token.value = null
    localStorage.removeItem('auth_token')
  }

  async function register(payload: {
    name: string
    email: string
    password: string
    password_confirmation: string
  }) {
    // Registration is the one place a guest's language choice becomes the account's language.
    const response = await authService.register({ ...payload, locale: currentLocale() })
    setSession(response.user, response.token)
  }

  async function login(payload: { email: string; password: string }) {
    const response = await authService.login(payload)
    setSession(response.user, response.token)
  }

  async function logout() {
    try {
      await authService.logout()
    } finally {
      clearSession()
    }
  }

  async function fetchCurrentUser() {
    if (!token.value) {
      initializing.value = false

      return
    }

    try {
      const account = await authService.me()
      user.value = account
      adoptSavedLocale(account)
    } catch {
      clearSession()
    } finally {
      initializing.value = false
    }
  }

  return {
    user,
    token,
    initializing,
    isAuthenticated,
    register,
    login,
    logout,
    clearSession,
    fetchCurrentUser,
  }
})
