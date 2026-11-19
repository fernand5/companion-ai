import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import AppShell from '@/components/AppShell.vue'
import { applyLocale } from '@/i18n'

const stub = { template: '<div />' }

async function shell() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['dashboard', 'weekly-plan', 'coach', 'activity', 'progress', 'profile', 'login'].map((name) => ({
      path: name === 'dashboard' ? '/' : `/${name}`,
      name,
      component: stub,
    })),
  })
  await router.push('/')
  await router.isReady()

  return mount(AppShell, { global: { plugins: [router] }, slots: { default: '<p data-testid="page">page</p>' } })
}

const labels = (wrapper: Awaited<ReturnType<typeof shell>>, which: 0 | 1) =>
  wrapper.findAll('nav')[which].findAll('a').map((a) => a.text())

describe('AppShell', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('reads exactly as it always has in English', async () => {
    const wrapper = await shell()

    expect(wrapper.text()).toContain('Companion AI Coach')
    expect(wrapper.find('header button').text()).toBe('Log out')
    expect(labels(wrapper, 0)).toEqual(['Dashboard', 'Weekly Plan', 'Coach', 'Activity', 'Progress', 'Profile'])
    expect(labels(wrapper, 1)).toEqual(['Dashboard', 'Weekly Plan', 'Coach', 'Activity', 'Progress', 'Profile'])
  })

  it('reads in Spanish, in the desktop and the mobile navigation alike', async () => {
    applyLocale('es')
    const wrapper = await shell()

    expect(wrapper.find('header button').text()).toBe('Cerrar sesión')
    expect(labels(wrapper, 0)).toEqual(['Inicio', 'Plan semanal', 'Coach', 'Actividad', 'Progreso', 'Perfil'])
    expect(labels(wrapper, 1)).toEqual(['Inicio', 'Plan semanal', 'Coach', 'Actividad', 'Progreso', 'Perfil'])
  })

  it('switches language on screen at once, without being mounted again', async () => {
    const wrapper = await shell()
    expect(wrapper.find('header button').text()).toBe('Log out')

    applyLocale('es')
    await nextTick()

    expect(wrapper.find('header button').text()).toBe('Cerrar sesión')
    expect(labels(wrapper, 1)[1]).toBe('Plan semanal')

    applyLocale('en')
    await nextTick()

    expect(wrapper.find('header button').text()).toBe('Log out')
    expect(labels(wrapper, 1)[1]).toBe('Weekly Plan')
  })

  it('names both navigation landmarks in the language on screen', async () => {
    const wrapper = await shell()
    expect(wrapper.findAll('nav').map((n) => n.attributes('aria-label'))).toEqual(['Primary', 'Primary'])

    applyLocale('es')
    await nextTick()

    expect(wrapper.findAll('nav').map((n) => n.attributes('aria-label'))).toEqual(['Principal', 'Principal'])
  })

  it('keeps every route name and target as it was: only the words change', async () => {
    const targets = async () => (await shell()).findAll('nav')[0].findAll('a').map((a) => a.attributes('href'))
    const english = await targets()

    applyLocale('es')

    expect(await targets()).toEqual(english)
    expect(english).toEqual(['/', '/weekly-plan', '/coach', '/activity', '/progress', '/profile'])
  })

  it('is keyboard reachable: real links and a real button, none taken out of the tab order', async () => {
    const wrapper = await shell()

    for (const a of wrapper.findAll('nav a')) {
      expect(a.element.tagName).toBe('A')
      expect(a.attributes('href')).toBeTruthy()
      expect(a.attributes('tabindex')).toBeUndefined()
    }
    const logout = wrapper.get('header button')
    expect(logout.attributes('type')).toBe('button')
    expect(logout.attributes('tabindex')).toBeUndefined()
  })

  it('has no icon-only control: every link and button has text for a screen reader', async () => {
    for (const lang of ['en', 'es'] as const) {
      applyLocale(lang)
      const wrapper = await shell()

      for (const control of wrapper.findAll('a, button')) {
        expect(control.text().length, `${lang}: ${control.html()}`).toBeGreaterThan(0)
      }
    }
  })

  it('shows the page it wraps', async () => {
    expect((await shell()).get('[data-testid="page"]').text()).toBe('page')
  })
})
