import { computed, ref } from 'vue'

import { i18n } from '@/i18n'
import type { MessageSchema } from '@/i18n/messages/en'

/**
 * Axios error responses carry the backend's validation/error message at
 * response.data.message — this is the one place that reads it, so every
 * store falls back to the same shape instead of re-deriving it inline.
 */
export function extractErrorMessage(error: unknown, fallback: string): string {
  const apiMessage = (error as { response?: { data?: { message?: string } } })?.response?.data
    ?.message

  return apiMessage ?? fallback
}

/** The fallback errors the front end writes itself (see i18n/messages/en/errors.ts). */
export type FallbackErrorKey = keyof MessageSchema['errors']

/**
 * A failure kept as what it IS, not as the words it was shown in:
 *  - `server`: the message the server wrote, in the language it was asked in. Shown exactly as it came.
 *  - `key`: the fallback to show when the server gave none. It is said in the language the screen has NOW, so
 *    switching language re-words it instead of leaving it behind in the old one.
 * Nothing ever reads either one to decide what to do: behaviour follows the failure, not its text.
 */
export interface ErrorState {
  server: string | null
  key: FallbackErrorKey
}

export function errorState(error: unknown, key: FallbackErrorKey): ErrorState {
  return { server: extractErrorMessage(error, '') || null, key }
}

/** The words for a failure in the current language. Reading it in a computed or render re-runs it on a language change. */
export function describeError(state: ErrorState): string {
  return state.server ?? i18n.global.t(`errors.${state.key}`)
}

/**
 * A store's failure, kept as the failure it is (see ErrorState). `message` is what the screen shows, worded when it is
 * read; `fail` records a failure (the server's own message wins over the fallback); `clear` removes it.
 */
export function errorRef() {
  const state = ref<ErrorState | null>(null)

  return {
    message: computed(() => (state.value ? describeError(state.value) : null)),
    fail(error: unknown, key: FallbackErrorKey) {
      state.value = errorState(error, key)
    },
    clear() {
      state.value = null
    },
  }
}
