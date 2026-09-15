import api from '@/services/api'
import type { User } from '@/types'

export interface AuthResponse {
  user: User
  token: string
}

export function register(payload: {
  name: string
  email: string
  password: string
  password_confirmation: string
}) {
  return api.post<AuthResponse>('/auth/register', payload).then((r) => r.data)
}

export function login(payload: { email: string; password: string }) {
  return api.post<AuthResponse>('/auth/login', payload).then((r) => r.data)
}

export function logout() {
  return api.post('/auth/logout')
}

export function me() {
  return api.get<User>('/me').then((r) => r.data)
}
