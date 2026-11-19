import api from '@/services/api'
import type { AppLocale } from '@/i18n'
import type { User } from '@/types'

export interface AuthResponse {
  user: User
  token: string
}

/**
 * The browser's IANA timezone (e.g. "America/Bogota") — the backend uses this
 * for every "today"/"now" computation that decides which calendar date an
 * activity or plan belongs to, since the server's own clock (UTC) silently
 * diverges from the user's actual calendar day for hours around their local
 * midnight. Sent on both register and login (refreshed each time) so it
 * self-corrects if the user travels. Falls back to undefined if the browser
 * can't report one, which the backend treats as "unknown" (defaults to UTC).
 */
function detectTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone
  } catch {
    return undefined
  }
}

/** `locale` is the language chosen on the sign-up screen; it becomes the new account's saved language. */
export function register(payload: {
  name: string
  email: string
  password: string
  password_confirmation: string
  locale?: AppLocale
}) {
  return api
    .post<AuthResponse>('/auth/register', { ...payload, timezone: detectTimezone() })
    .then((r) => r.data)
}

export function login(payload: { email: string; password: string }) {
  return api
    .post<AuthResponse>('/auth/login', { ...payload, timezone: detectTimezone() })
    .then((r) => r.data)
}

export function logout() {
  return api.post('/auth/logout')
}

/** Saves the account's language. The login call never does this: a guest's pick must not overwrite it. */
export function updateLocale(locale: AppLocale) {
  return api.put<User>('/me/locale', { locale }).then((r) => r.data)
}

export function me() {
  return api.get<User>('/me').then((r) => r.data)
}
