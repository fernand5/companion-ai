import { createI18n } from 'vue-i18n'

import { en, type MessageSchema } from '@/i18n/messages/en'
import { es } from '@/i18n/messages/es'

/** The languages the app is offered in. Must match the backend's App\Support\AppLocale. */
export const SUPPORTED_LOCALES = ['en', 'es'] as const
export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

/** English is the default for everyone who has not chosen, and the fallback for any missing text. */
export const DEFAULT_LOCALE: AppLocale = 'en'

/** The language last shown on this device. Only a first-paint hint: a signed-in user's saved language wins. */
export const LOCALE_STORAGE_KEY = 'ui_locale'

/** Sent on every request. The server only reads it when nobody is signed in (e.g. a login error). */
export const LOCALE_HEADER = 'X-App-Locale'

declare module 'vue-i18n' {
  // Types the message keys for `t()` from the English source. Declaration merging needs an interface.
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  export interface DefineLocaleMessage extends MessageSchema {}
}

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

function storage(): Storage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

/** The language last used on this device, or English. Never guessed from the browser or the location. */
export function readStoredLocale(): AppLocale {
  try {
    const stored = storage()?.getItem(LOCALE_STORAGE_KEY)

    return isSupportedLocale(stored) ? stored : DEFAULT_LOCALE
  } catch {
    return DEFAULT_LOCALE
  }
}

export function createAppI18n(
  messages: Record<string, unknown> = { en, es },
  locale: AppLocale = readStoredLocale(),
) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: DEFAULT_LOCALE,
    messages: messages as Record<AppLocale, MessageSchema>,
    // A missing Spanish string quietly shows English; that is the designed fallback, not an error.
    silentFallbackWarn: true,
    missingWarn: import.meta.env.DEV,
  })
}

export const i18n = createAppI18n()

/** The language the interface is showing right now. Reading it inside a render or computed makes that re-run on change. */
export function currentLocale(): AppLocale {
  const value = i18n.global.locale.value

  return isSupportedLocale(value) ? value : DEFAULT_LOCALE
}

/**
 * Switches the interface language immediately, with no reload. It only changes wording and
 * formatting: nothing here talks to the server, so it is safe for a guest on the sign-in screen.
 */
export function applyLocale(locale: AppLocale): void {
  i18n.global.locale.value = locale
  document.documentElement.lang = locale

  try {
    storage()?.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Private mode or blocked storage: the language still switches, it just is not remembered here.
  }
}

// First paint: the <html lang> must match what is about to be shown.
document.documentElement.lang = currentLocale()
