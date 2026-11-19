import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  DEFAULT_LOCALE,
  LOCALE_STORAGE_KEY,
  SUPPORTED_LOCALES,
  applyLocale,
  createAppI18n,
  currentLocale,
  i18n,
  isSupportedLocale,
  readStoredLocale,
} from '@/i18n'
import { en } from '@/i18n/messages/en'
import { es } from '@/i18n/messages/es'

describe('supported languages', () => {
  it('offers exactly English and Spanish, with English as the default', () => {
    expect([...SUPPORTED_LOCALES]).toEqual(['en', 'es'])
    expect(DEFAULT_LOCALE).toBe('en')
  })

  it('accepts only an exact supported value', () => {
    expect(isSupportedLocale('en')).toBe(true)
    expect(isSupportedLocale('es')).toBe(true)
    for (const bad of ['ES', 'es-CO', 'es_419', 'fr', '', ' es', null, undefined, 5, ['es']]) {
      expect(isSupportedLocale(bad)).toBe(false)
    }
  })
})

describe('the starting language', () => {
  it('is English for a first-time visitor', () => {
    expect(readStoredLocale()).toBe('en')
    expect(currentLocale()).toBe('en')
  })

  it('is never inferred from the browser language or location', () => {
    const languages = vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(['es-CO', 'es'])
    const language = vi.spyOn(window.navigator, 'language', 'get').mockReturnValue('es-CO')

    expect(readStoredLocale()).toBe('en')

    languages.mockRestore()
    language.mockRestore()
  })

  it('restores the language last used on this device', () => {
    localStorage.setItem(LOCALE_STORAGE_KEY, 'es')

    expect(readStoredLocale()).toBe('es')
  })

  it('ignores a remembered value that is not a supported language', () => {
    for (const junk of ['fr', 'ES', '', '{"x":1}', 'es-CO']) {
      localStorage.setItem(LOCALE_STORAGE_KEY, junk)
      expect(readStoredLocale()).toBe('en')
    }
  })

  it('still works when storage is unavailable', () => {
    const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    expect(readStoredLocale()).toBe('en')
    expect(() => applyLocale('es')).not.toThrow()
    expect(currentLocale()).toBe('es')

    get.mockRestore()
    set.mockRestore()
  })
})

describe('switching language', () => {
  it('changes the text immediately, without a reload', async () => {
    expect(i18n.global.t('language.label')).toBe('Language')

    applyLocale('es')
    await nextTick()

    expect(i18n.global.t('language.label')).toBe('Idioma')
    expect(currentLocale()).toBe('es')

    applyLocale('en')

    expect(i18n.global.t('language.label')).toBe('Language')
  })

  it('keeps <html lang> in step with the language', () => {
    applyLocale('es')
    expect(document.documentElement.lang).toBe('es')

    applyLocale('en')
    expect(document.documentElement.lang).toBe('en')
  })

  it('remembers the language on this device', () => {
    applyLocale('es')

    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('es')
  })
})

describe('translations', () => {
  const keys = (value: unknown, prefix = ''): string[] =>
    Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
      typeof child === 'object' && child !== null ? keys(child, `${prefix}${key}.`) : [`${prefix}${key}`],
    )

  it('has a Spanish string for every English key, and no extras', () => {
    expect(keys(es).sort()).toEqual(keys(en).sort())
  })

  it('has no empty strings', () => {
    for (const bundle of [en, es]) {
      for (const key of keys(bundle)) {
        const value = key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], bundle)
        expect(String(value).trim().length).toBeGreaterThan(0)
      }
    }
  })

  it('falls back to English for a key Spanish does not have yet', () => {
    // A Spanish bundle with one key missing, as during a partial rollout.
    const partial = createAppI18n(
      { en, es: { language: { label: 'Idioma', options: { en: 'English', es: 'Español' } } } },
      'es',
    )

    expect(partial.global.t('language.label')).toBe('Idioma')
    expect(partial.global.t('language.saved')).toBe(en.language.saved)
  })

  it('shows the key itself rather than crashing for a key that exists nowhere', () => {
    const t = i18n.global.t as unknown as (key: string) => string

    expect(t('does.not.exist')).toBe('does.not.exist')
  })

  it('writes the language names in their own language, whatever the interface language is', () => {
    for (const bundle of [en, es]) {
      expect(bundle.language.options).toEqual({ en: 'English', es: 'Español' })
    }
  })

  it('addresses the user as «tú» in Spanish', () => {
    expect(es.language.saveFailed).toContain('Inténtalo')
    expect(JSON.stringify(es)).not.toMatch(/\busted\b|\bvos\b|\bvosotros\b/i)
  })
})
