import { config } from '@vue/test-utils'
import { afterEach } from 'vitest'

import { LOCALE_STORAGE_KEY, applyLocale, i18n } from '@/i18n'

// Every mounted component gets the same i18n instance the app uses.
config.global.plugins = [i18n]

// Each test starts as a first-time visitor: English, nothing remembered on the device.
afterEach(() => {
  window.localStorage.removeItem(LOCALE_STORAGE_KEY)
  applyLocale('en')
  window.localStorage.removeItem(LOCALE_STORAGE_KEY)
})
