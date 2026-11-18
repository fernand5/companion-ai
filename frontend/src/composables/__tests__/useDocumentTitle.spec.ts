import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { useDocumentTitle } from '@/composables/useDocumentTitle'
import { applyLocale } from '@/i18n'

const stub = { template: '<div />' }
const mounted: VueWrapper[] = []

async function app(path = '/') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      ['dashboard', '/'], ['weekly-plan', '/weekly-plan'], ['coach', '/coach'], ['activity', '/activity'],
      ['progress', '/progress'], ['profile', '/profile'], ['login', '/login'], ['register', '/register'], ['elsewhere', '/elsewhere'],
    ].map(([name, route]) => ({ path: route, name, component: stub })),
  })
  await router.push(path)
  await router.isReady()
  mounted.push(mount(defineComponent({ setup() {
          useDocumentTitle()

          return {}
        },
        template: '<div />', }), { global: { plugins: [router] } }))

  return router
}

describe('the browser tab title', () => {
  beforeEach(() => {
    document.title = ''
  })

  // A title watcher left running by one test would answer the next test's language change.
  afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))

  it.each([
    ['/', 'Dashboard · Companion AI Coach', 'Inicio · Companion AI Coach'],
    ['/weekly-plan', 'Weekly Plan · Companion AI Coach', 'Plan semanal · Companion AI Coach'],
    ['/coach', 'Coach · Companion AI Coach', 'Coach · Companion AI Coach'],
    ['/activity', 'Activity · Companion AI Coach', 'Actividad · Companion AI Coach'],
    ['/progress', 'Progress · Companion AI Coach', 'Progreso · Companion AI Coach'],
    ['/profile', 'Profile · Companion AI Coach', 'Perfil · Companion AI Coach'],
    ['/login', 'Log in · Companion AI Coach', 'Iniciar sesión · Companion AI Coach'],
    ['/register', 'Register · Companion AI Coach', 'Registrarse · Companion AI Coach'],
  ])('%s is titled in the language on screen', async (path, english, spanish) => {
    await app(path)
    expect(document.title).toBe(english)

    applyLocale('es')
    await nextTick()
    expect(document.title).toBe(spanish)
  })

  it('updates at once when the language changes, and back again', async () => {
    await app('/profile')

    applyLocale('es')
    await nextTick()
    expect(document.title).toBe('Perfil · Companion AI Coach')
    expect(document.documentElement.lang).toBe('es')

    applyLocale('en')
    await nextTick()
    expect(document.title).toBe('Profile · Companion AI Coach')
    expect(document.documentElement.lang).toBe('en')
  })

  it('follows the page as well as the language', async () => {
    applyLocale('es')
    const router = await app('/coach')
    expect(document.title).toBe('Coach · Companion AI Coach')

    await router.push('/progress')
    expect(document.title).toBe('Progreso · Companion AI Coach')
  })

  it('titles a page it does not know with the brand alone', async () => {
    await app('/elsewhere')

    expect(document.title).toBe('Companion AI Coach')
  })

  it('starts in Spanish when the saved language is Spanish', async () => {
    applyLocale('es')
    await app('/profile')

    expect(document.title).toBe('Perfil · Companion AI Coach')
    expect(document.documentElement.lang).toBe('es')
  })
})
