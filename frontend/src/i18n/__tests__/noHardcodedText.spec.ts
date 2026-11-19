import { describe, expect, it } from 'vitest'

/**
 * Phase 7 / M1: no application-authored, user-visible English is left in the front end. Every word comes from the
 * message catalog, so it follows the language. This scans EVERY source file (components, views, stores, utilities,
 * constants) except the few that hold no wording by nature: the API services (URLs), the types, the router, the
 * message catalogs themselves, the Markdown parser, the icon table and the test setup.
 */
const NOT_WORDING = [
  /^\/src\/services\//,
  /^\/src\/types\//,
  /^\/src\/i18n\/(index|format)\.ts$/,
  /^\/src\/router\//,
  /^\/src\/main\.ts$/,
  /^\/src\/test\//,
  /^\/src\/constants\/icons\.ts$/,
  /^\/src\/utils\/(markdown|week)\.ts$/,
  /^\/src\/components\/MarkdownText\.vue$/, // draws the coach's own text; its only strings are HTML attributes
  /^\/src\/composables\/useAutoScroll\.ts$/,
]

const everything = import.meta.glob(['/src/**/*.vue', '/src/**/*.ts', '!/src/**/__tests__/**', '!/src/**/*.spec.ts', '!/src/i18n/messages/**', '!/src/**/*.d.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const sources = Object.fromEntries(Object.entries(everything).filter(([file]) => !NOT_WORDING.some((pattern) => pattern.test(file))))
const MIGRATED = Object.keys(sources).sort()

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
  it('checks every wording-bearing file, including the ones migrated in M1a, M1b and M1c', () => {
    expect(MIGRATED.length).toBeGreaterThan(45)
    for (const file of ['/src/App.vue', '/src/views/CoachView.vue', '/src/components/ChatBubble.vue', '/src/utils/format.ts', '/src/stores/chat.ts']) expect(MIGRATED).toContain(file)
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
