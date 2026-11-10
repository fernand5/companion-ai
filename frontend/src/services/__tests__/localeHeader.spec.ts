import type { InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it } from 'vitest'

import { LOCALE_HEADER, applyLocale } from '@/i18n'
import api from '@/services/api'
import * as authService from '@/services/auth'

/** Runs a request through the real interceptors and captures what would be sent. */
async function capture(run: () => Promise<unknown>): Promise<InternalAxiosRequestConfig> {
  let seen!: InternalAxiosRequestConfig
  const original = api.defaults.adapter
  api.defaults.adapter = async (config) => {
    seen = config

    return { data: {}, status: 200, statusText: 'OK', headers: {}, config }
  }

  try {
    await run()
  } finally {
    api.defaults.adapter = original
  }

  return seen
}

describe('the language header', () => {
  it('names the interface language on every request', async () => {
    expect((await capture(() => api.get('/me'))).headers[LOCALE_HEADER]).toBe('en')

    applyLocale('es')
    expect((await capture(() => api.get('/me'))).headers[LOCALE_HEADER]).toBe('es')
  })

  it('is sent on login, but the login body carries no language to save', async () => {
    applyLocale('es')

    const config = await capture(() => authService.login({ email: 'ana@example.com', password: 'secret' }))
    const body = JSON.parse(String(config.data))

    expect(config.headers[LOCALE_HEADER]).toBe('es')
    expect(body).not.toHaveProperty('locale')
  })

  it('sends the chosen language as a field only when registering', async () => {
    const config = await capture(() =>
      authService.register({ name: 'Ana', email: 'ana@example.com', password: 'secret123', password_confirmation: 'secret123', locale: 'es' }),
    )

    expect(JSON.parse(String(config.data))).toMatchObject({ locale: 'es' })
  })

  it('saves the account language with a PUT of the locale and nothing else', async () => {
    const config = await capture(() => authService.updateLocale('es'))

    expect(config.method).toBe('put')
    expect(config.url).toBe('/me/locale')
    expect(JSON.parse(String(config.data))).toEqual({ locale: 'es' })
  })

  it('still sends the timezone rules unchanged', async () => {
    const config = await capture(() => authService.login({ email: 'ana@example.com', password: 'secret' }))

    expect(JSON.parse(String(config.data))).toHaveProperty('timezone')
  })
})
