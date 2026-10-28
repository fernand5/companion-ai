/**
 * A small, safe Markdown reader for the coach's replies. It turns text into a tree of plain nodes; the view
 * draws that tree with ordinary elements and text. Nothing is ever inserted as HTML, so anything that looks like
 * markup in a reply (a <script> tag, an <img onerror=...>, an inline style) stays visible text and cannot run.
 *
 * Supported: paragraphs, bullet and numbered lists, headings (shown as bold lines), fenced code, **bold**, *italic*,
 * `code` and [text](https://link). A link is only ever made from an http or https address; any other scheme
 * (javascript:, data:, ...) is shown as its text with no link. Raw URLs are not turned into links.
 */

export type Inline =
  | { type: 'text'; value: string }
  | { type: 'code'; value: string }
  | { type: 'strong'; children: Inline[] }
  | { type: 'em'; children: Inline[] }
  | { type: 'link'; href: string; children: Inline[] }

export type Block =
  | { type: 'paragraph'; children: Inline[] }
  | { type: 'heading'; children: Inline[] }
  | { type: 'list'; ordered: boolean; items: Inline[][] }
  | { type: 'code'; value: string }

const MAX_DEPTH = 3
const isSpace = (c: string | undefined) => c === undefined || /\s/.test(c)
const isWord = (c: string | undefined) => c !== undefined && /[\p{L}\p{N}]/u.test(c)

/** Only web addresses become links. */
export function safeHref(raw: string): string | null {
  try {
    const url = new URL(raw)

    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null
  } catch {
    return null
  }
}

export function parseInline(text: string, depth = 0): Inline[] {
  const out: Inline[] = []
  let buffer = ''
  let i = 0

  const flush = () => {
    if (buffer !== '') out.push({ type: 'text', value: buffer })
    buffer = ''
  }

  while (i < text.length) {
    const char = text[i]

    if (char === '`') {
      const end = text.indexOf('`', i + 1)

      if (end > i + 1) {
        flush()
        out.push({ type: 'code', value: text.slice(i + 1, end) })
        i = end + 1
        continue
      }
    }

    if (depth < MAX_DEPTH && (text.startsWith('**', i) || text.startsWith('__', i))) {
      const mark = text.slice(i, i + 2)
      const end = text.indexOf(mark, i + 2)

      if (end > i + 2 && !isSpace(text[i + 2]) && !isSpace(text[end - 1]) && !(mark === '__' && (isWord(text[i - 1]) || isWord(text[end + 2])))) {
        flush()
        out.push({ type: 'strong', children: parseInline(text.slice(i + 2, end), depth + 1) })
        i = end + 2
        continue
      }
    }

    if (depth < MAX_DEPTH && (char === '*' || char === '_') && text[i + 1] !== char && text[i - 1] !== char) {
      const end = text.indexOf(char, i + 1)
      // An underscore inside a word (snake_case) is not emphasis, and neither is a lone star used as a bullet or a times sign,
      // nor a marker that is part of a longer run (window.__name__): those stay exactly as written.
      const insideWord = char === '_' && (isWord(text[i - 1]) || isWord(text[end + 1]))

      if (end > i + 1 && text[end + 1] !== char && !isSpace(text[i + 1]) && !isSpace(text[end - 1]) && !insideWord) {
        flush()
        out.push({ type: 'em', children: parseInline(text.slice(i + 1, end), depth + 1) })
        i = end + 1
        continue
      }
    }

    if (char === '[' && depth < MAX_DEPTH) {
      const match = /^\[([^\]\n]{1,200})\]\(((?:[^()\s]|\([^()\s]*\)){1,2000})\)/.exec(text.slice(i))

      if (match) {
        flush()
        const href = safeHref(match[2])
        const children = parseInline(match[1], depth + 1)

        if (href) out.push({ type: 'link', href, children })
        else out.push(...children)
        i += match[0].length
        continue
      }
    }

    buffer += char
    i++
  }

  flush()

  return out
}

const BULLET = /^\s{0,6}[-*•+]\s+(.*)$/
const NUMBERED = /^\s{0,6}\d{1,3}[.)]\s+(.*)$/
const HEADING = /^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/
const FENCE = /^\s{0,3}(```|~~~)/

export function parseMarkdown(source: string): Block[] {
  const lines = source.replace(/\r\n?/g, '\n').split('\n')
  const blocks: Block[] = []
  let paragraph: string[] = []
  let list: { ordered: boolean; items: Inline[][] } | null = null

  const endParagraph = () => {
    if (paragraph.length > 0) blocks.push({ type: 'paragraph', children: parseInline(paragraph.join('\n').trim()) })
    paragraph = []
  }
  const endList = () => {
    if (list) blocks.push({ type: 'list', ordered: list.ordered, items: list.items })
    list = null
  }

  for (let n = 0; n < lines.length; n++) {
    const line = lines[n]

    if (FENCE.test(line)) {
      endParagraph()
      endList()
      const code: string[] = []

      while (n + 1 < lines.length && !FENCE.test(lines[n + 1])) code.push(lines[++n])
      n++ // the closing fence, if there is one
      blocks.push({ type: 'code', value: code.join('\n') })
      continue
    }

    if (line.trim() === '') {
      endParagraph()
      endList()
      continue
    }

    const heading = HEADING.exec(line)
    if (heading) {
      endParagraph()
      endList()
      blocks.push({ type: 'heading', children: parseInline(heading[1]) })
      continue
    }

    const bullet = BULLET.exec(line)
    const numbered = bullet ? null : NUMBERED.exec(line)
    const item = bullet ?? numbered

    if (item) {
      endParagraph()
      const ordered = numbered !== null

      if (list && list.ordered !== ordered) endList()
      list ??= { ordered, items: [] }
      list.items.push(parseInline(item[1].trim()))
      continue
    }

    // A wrapped continuation of the last list item.
    if (list && /^\s{2,}\S/.test(line)) {
      list.items[list.items.length - 1].push(...parseInline(` ${line.trim()}`))
      continue
    }

    endList()
    paragraph.push(line)
  }

  endParagraph()
  endList()

  return blocks
}
