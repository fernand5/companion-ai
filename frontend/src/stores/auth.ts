import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import * as authService from '@/services/auth'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(localStorage.getItem('auth_token'))
  const initializing = ref(true)

  const isAuthenticated = computed(() => !!token.value)

  function setSession(newUser: User, newToken: string) {
    user.value = newUser
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
    const response = await authService.register(payload)
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
      user.value = await authService.me()
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
    fetchCurrentUser,
  }
})
