import { describe, expect, it } from 'vitest'

import { parseInline, parseMarkdown, safeHref } from '@/utils/markdown'

/** Flatten a tree to its visible text, so tests can check nothing was lost or turned into markup. */
function text(nodes: ReturnType<typeof parseInline>): string {
  return nodes.map((n) => ('value' in n ? n.value : text(n.children))).join('')
}

describe('markdown: structure', () => {
  it('reads paragraphs, bullets and numbered lists', () => {
    const blocks = parseMarkdown('Here is your plan:\n\n* Push-ups 3x12\n* Rows 3x10\n\n1. Warm up\n2. Lift')

    expect(blocks.map((b) => b.type)).toEqual(['paragraph', 'list', 'list'])
    expect(blocks[1]).toMatchObject({ type: 'list', ordered: false })
    expect((blocks[1] as { items: unknown[] }).items).toHaveLength(2)
    expect(blocks[2]).toMatchObject({ type: 'list', ordered: true })
  })

  it('reads dash, plus and bullet-character lists, and keeps a wrapped item together', () => {
    const blocks = parseMarkdown('- one\n+ two\n• three\n  continued here')

    expect(blocks).toHaveLength(1)
    expect((blocks[0] as { items: unknown[][] }).items).toHaveLength(3)
  })

  it('shows headings as a bold line, not as a giant title', () => {
    expect(parseMarkdown('## Warm-up ##')[0]).toMatchObject({ type: 'heading' })
  })

  it('keeps fenced code as one block, verbatim, and does not read markdown inside it', () => {
    const blocks = parseMarkdown('Try:\n```\n**not bold** <b>x</b>\nsecond line\n```\nDone')

    expect(blocks.map((b) => b.type)).toEqual(['paragraph', 'code', 'paragraph'])
    expect(blocks[1]).toEqual({ type: 'code', value: '**not bold** <b>x</b>\nsecond line' })
  })

  it('does not hang or lose text on an unclosed fence', () => {
    const blocks = parseMarkdown('```\nnever closed\nstill code')

    expect(blocks).toEqual([{ type: 'code', value: 'never closed\nstill code' }])
  })

  it('handles empty input and Windows line endings', () => {
    expect(parseMarkdown('')).toEqual([])
    expect(parseMarkdown('a\r\nb')).toHaveLength(1)
  })
})

describe('markdown: inline', () => {
  it('reads bold, italic and code', () => {
    expect(parseInline('a **b** *c* `d`').map((n) => n.type)).toEqual(['text', 'strong', 'text', 'em', 'text', 'code'])
  })

  it('reads underscore emphasis but not snake_case or a star used as times', () => {
    expect(parseInline('__bold__ and _it_').map((n) => n.type)).toEqual(['strong', 'text', 'em'])
    expect(parseInline('use bench_press_weight here')).toEqual([{ type: 'text', value: 'use bench_press_weight here' }])
    expect(text(parseInline('3 * 10 * 4 sets'))).toBe('3 * 10 * 4 sets')
  })

  it('leaves unmatched markers as plain text', () => {
    expect(text(parseInline('**oops and *half'))).toBe('**oops and *half')
  })

  it('nests but stops at a fixed depth, so hostile input cannot recurse forever', () => {
    const deep = '**' + '*'.repeat(1) + '_a_' + '*' + '**'
    expect(() => parseInline(deep.repeat(200))).not.toThrow()
    expect(text(parseInline('**a *b* c**'))).toBe('a b c')
  })

  it('a pathological string finishes quickly', () => {
    const started = performance.now()
    parseMarkdown(('[' + '*_`'.repeat(200) + '](').repeat(300))
    parseMarkdown('*'.repeat(20000))
    parseMarkdown('[a](' + 'x'.repeat(50000))

    expect(performance.now() - started).toBeLessThan(1000)
  })
})

describe('markdown: nothing in a reply can become markup or a dangerous link', () => {
  it('keeps raw HTML as visible text', () => {
    const blocks = parseMarkdown('<script>alert(1)</script> <img src=x onerror=alert(1)> <b>hi</b>')

    expect(blocks).toHaveLength(1)
    expect(text((blocks[0] as { children: ReturnType<typeof parseInline> }).children)).toBe('<script>alert(1)</script> <img src=x onerror=alert(1)> <b>hi</b>')
  })

  it.each([
    ['javascript:alert(1)'],
    ['JaVaScRiPt:alert(1)'],
    ['data:text/html;base64,PHNjcmlwdD4='],
    ['vbscript:msgbox(1)'],
    ['file:///etc/passwd'],
    ['//evil.example'],
    ['/relative/path'],
    ['mailto:a@example.com'],
  ])('does not make a link from %s', (href) => {
    expect(safeHref(href)).toBeNull()

    const nodes = parseInline(`[click me](${href})`)
    expect(nodes.some((n) => n.type === 'link')).toBe(false)
    expect(text(nodes)).toBe('click me')
  })

  it('links only http and https addresses, normalised', () => {
    expect(safeHref('https://example.com/a?b=1')).toBe('https://example.com/a?b=1')
    expect(safeHref('http://example.com')).toBe('http://example.com/')

    expect(parseInline('[docs](https://example.com)')[0]).toMatchObject({ type: 'link', href: 'https://example.com/' })
  })

  it('does not turn raw URLs into links', () => {
    expect(parseInline('see https://example.com now').some((n) => n.type === 'link')).toBe(false)
  })

  it('cannot smuggle an attribute through the link text or address', () => {
    const nodes = parseInline('[x" onclick="alert(1)](https://example.com/" onmouseover="alert(1))')

    for (const node of nodes) {
      if (node.type === 'link') expect(node.href).not.toContain('"')
    }
  })
})
