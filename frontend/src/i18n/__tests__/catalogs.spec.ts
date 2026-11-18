import { describe, expect, it } from 'vitest'

import { en } from '@/i18n/messages/en'
import { es } from '@/i18n/messages/es'

/** Every string in a catalog, by dotted key. */
function flatten(value: unknown, prefix = ''): Record<string, string> {
  if (typeof value === 'string') return { [prefix]: value }

  return Object.entries(value as Record<string, unknown>).reduce<Record<string, string>>(
    (all, [key, child]) => ({ ...all, ...flatten(child, prefix ? `${prefix}.${key}` : key) }),
    {},
  )
}

const placeholders = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort()

const english = flatten(en)
const spanish = flatten(es)

describe('translation catalogs', () => {
  it('has the same keys in English and Spanish, in the same order', () => {
    expect(Object.keys(spanish)).toEqual(Object.keys(english))
  })

  it('is organised by domain', () => {
    expect(Object.keys(en)).toEqual(['language', 'common', 'nav', 'auth', 'profile', 'coaching', 'errors', 'a11y'])
    expect(Object.keys(es)).toEqual(Object.keys(en))
  })

  it('keeps every {placeholder} of the English text in the Spanish text', () => {
    for (const [key, text] of Object.entries(english)) {
      expect(placeholders(spanish[key]), key).toEqual(placeholders(text))
    }
  })

  it('has no empty or whitespace-only string', () => {
    for (const [key, text] of [...Object.entries(english), ...Object.entries(spanish)]) {
      expect(text.trim().length, key).toBeGreaterThan(0)
    }
  })

  it('has no Spanish string that is just the English text, apart from names that are not translated', () => {
    const sameOnPurpose = new Set(['nav.brand', 'nav.items.coach', 'coaching.example.coach', 'language.options.en', 'language.options.es'])

    for (const [key, text] of Object.entries(english)) {
      if (sameOnPurpose.has(key)) continue
      expect(spanish[key], `${key} is still English in Spanish`).not.toBe(text)
    }
  })

  it('uses «tú», never «usted», and no voseo', () => {
    const all = Object.values(spanish).join(' ')

    expect(all).not.toMatch(/\busted(es)?\b/i)
    expect(all).not.toMatch(/\b(vos|tenés|querés|podés|sos)\b/i)
  })

  it('counts the keys of each domain', () => {
    const counts = Object.fromEntries(
      Object.keys(en).map((domain) => [domain, Object.keys(english).filter((key) => key.startsWith(`${domain}.`)).length]),
    )

    // A change here is deliberate: new text means new keys in BOTH languages.
    expect(counts).toEqual({ language: 9, common: 5, nav: 8, auth: 20, profile: 25, coaching: 36, errors: 4, a11y: 6 })
    expect(Object.keys(english)).toHaveLength(113)
  })
})

describe('English wording is exactly what the screens always said', () => {
  const expected: Record<string, string> = {
    'nav.brand': 'Companion AI Coach',
    'nav.logout': 'Log out',
    'nav.items.weeklyPlan': 'Weekly Plan',
    'a11y.primaryNavigation': 'Primary',
    'common.loading': 'Loading…',
    'auth.login.title': 'Welcome back',
    'auth.login.subtitle': 'Log in to keep training with your coach.',
    'auth.login.submitting': 'Logging in…',
    'auth.login.noAccount': 'No account?',
    'auth.register.title': 'Create your account',
    'auth.register.subtitle': 'Start training with your adaptive AI coach.',
    'auth.register.confirmPassword': 'Confirm password',
    'auth.register.submitting': 'Creating account…',
    'auth.register.haveAccount': 'Already have an account?',
    'errors.loginFailed': "Couldn't log in — please try again.",
    'errors.registerFailed': 'Could not create your account — check the fields and try again.',
    'errors.profileLoad': "Couldn't load your profile.",
    'errors.coachingSave': "Couldn't save your coaching style. Please try again.",
    'profile.loading': 'Loading profile…',
    'profile.equipment': 'Equipment (comma separated)',
    'profile.equipmentPlaceholder': 'dumbbells, exercise mat, treadmill',
    'profile.primaryGoalPlaceholder': 'e.g. Lose body fat while maintaining muscle',
    'profile.sessionLength': 'Preferred session length (minutes)',
    'profile.schedule.title': 'Recurring schedule',
    'profile.schedule.empty': 'No recurring activities yet.',
    'profile.schedule.activityPlaceholder': 'Activity (e.g. Football)',
    'coaching.title': 'Your coach',
    'coaching.styleLegend': 'Coaching style',
    'coaching.detailLegend': 'Response detail',
    'coaching.save': 'Save coaching style',
    'coaching.example.fixed': 'A fixed example, not written by the AI.',
    'coaching.styles.supportive.description': 'Empathetic, encouraging and patient. Goes at your pace.',
    'coaching.details.concise.description': 'Short answers: the point and one next step.',
    'coaching.samplePrompt': 'I missed Tuesday’s workout and my knee feels a bit sore.',
    'coaching.samples.balanced.concise': 'Skip the catch-up. Rest the knee today; if it feels fine tomorrow, do Thursday as planned.',
  }

  it.each(Object.entries(expected))('%s', (key, text) => {
    expect(english[key]).toBe(text)
  })
})
