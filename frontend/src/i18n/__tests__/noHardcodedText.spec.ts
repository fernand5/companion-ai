import { describe, expect, it } from 'vitest'

/**
 * Phase 7 / M1a: the application shell, sign-in, sign-up, profile and coaching settings keep no user-visible
 * English in their templates or code. Every word comes from the message catalog, so it follows the language.
 * The list below grows as later milestones migrate more screens; a file on it may not slip back.
 */
const MIGRATED = [
  '/src/App.vue',
  '/src/components/AppShell.vue',
  '/src/components/ErrorNotice.vue',
  '/src/components/LanguageSelector.vue',
  '/src/components/CoachingStyleSettings.vue',
  '/src/components/ScheduleEditor.vue',
  '/src/views/LoginView.vue',
  '/src/views/RegisterView.vue',
  '/src/views/ProfileView.vue',
  '/src/composables/useDocumentTitle.ts',
  '/src/constants/coaching.ts',
  '/src/stores/profile.ts',
  '/src/stores/auth.ts',
  '/src/stores/locale.ts',
  '/src/utils/errors.ts',
  '/src/views/DashboardView.vue',
  '/src/views/ActivityView.vue',
  '/src/components/TodaysPlanCard.vue',
  '/src/components/RecoveryCheckinForm.vue',
  '/src/components/StatCard.vue',
  '/src/components/ActivityQuickLogForm.vue',
  '/src/components/WeightLogForm.vue',
  '/src/components/WorkoutLogForm.vue',
  '/src/components/WorkoutSessionSummary.vue',
  '/src/components/ExercisePerformanceEditor.vue',
  '/src/components/ExerciseDemo.vue',
  '/src/utils/format.ts',
  '/src/utils/weekDay.ts',
  '/src/stores/dashboard.ts',
  '/src/stores/activity.ts',
  '/src/stores/workoutPlan.ts',
  '/src/stores/recovery.ts',
  '/src/stores/exerciseImages.ts',
  '/src/constants/activity.ts',
  '/src/constants/schedule.ts',
]

const sources = import.meta.glob(['/src/App.vue', '/src/components/*.vue', '/src/views/*.vue', '/src/composables/*.ts', '/src/constants/*.ts', '/src/stores/*.ts', '/src/utils/*.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const template = (source: string) => /<template>([\s\S]*)<\/template>/.exec(source)?.[1] ?? ''
const script = (source: string) => source.replace(/<template>[\s\S]*<\/template>/, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

/** Text between tags, outside {{ }} and outside attributes. Symbols and punctuation alone are fine (— · …). */
function textNodes(markup: string): string[] {
  // A unit symbol (kg, km) is not a translated word.
  const withoutUnits = (text: string) => text.replace(/\b(kg|km|km\/h)\b/g, '')

  return [...markup.replace(/\{\{[\s\S]*?\}\}/g, ' ').matchAll(/>([^<>]+)</g)].map((m) => m[1].trim()).filter((t) => /[A-Za-zÁ-ú]{2,}/.test(withoutUnits(t)))
}

/** Literal text in attributes a person (or a screen reader) reads. A bound one (":aria-label") is a translated expression. */
function literalAttributes(markup: string): string[] {
  return [...markup.matchAll(/\s(placeholder|aria-label|title|alt|label)="([^"{}]*[A-Za-z]{2,}[^"]*)"/g)].map((m) => `${m[1]}="${m[2]}"`)
}

/** Quoted strings in code that read as a sentence: two or more words, not a CSS class list, path, key or identifier. */
function sentences(code: string): string[] {
  return [...code.matchAll(/(['"`])((?:(?!\1)[^\\\n]|\\.)+)\1/g)]
    .map((m) => m[2])
    .filter((text) => /[A-Za-z]{3,}[ ][A-Za-z]{2,}/.test(text))
    .filter((text) => !/^[@\/.]/.test(text) && !/[-:[\]]/.test(text.split(' ')[0] + text.split(' ')[1]) && !/^[a-z0-9_.]+$/.test(text))
}

describe('no hardcoded user-visible text in the migrated files', () => {
  it('finds the files it is meant to check', () => {
    for (const file of MIGRATED) expect(sources, file).toHaveProperty([file])
  })

  it.each(MIGRATED)('%s', (file) => {
    const source = sources[file]
    const markup = file.endsWith('.vue') ? template(source) : ''
    const code = file.endsWith('.vue') ? script(source) : script(source)

    expect(textNodes(markup), 'text between tags').toEqual([])
    expect(literalAttributes(markup), 'literal accessible names and placeholders').toEqual([])
    expect(sentences(code), 'sentences in code').toEqual([])
  })
})
