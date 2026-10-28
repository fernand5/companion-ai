<script lang="ts">
import { defineComponent, h, type PropType, type VNode } from 'vue'

import { parseMarkdown, type Block, type Inline } from '@/utils/markdown'

/**
 * Draws parsed Markdown with ordinary elements. Every piece of text goes in as a text node (Vue escapes it);
 * there is no v-html and no way for reply text to become an element, attribute or script.
 */
function inline(nodes: Inline[]): VNode[] {
  return nodes.map((node): VNode => {
    switch (node.type) {
      case 'text':
        return h('span', node.value)
      case 'code':
        return h('code', { class: 'rounded bg-slate-100 px-1 py-0.5 font-mono text-[0.85em] break-words' }, node.value)
      case 'strong':
        return h('strong', { class: 'font-semibold' }, inline(node.children))
      case 'em':
        return h('em', inline(node.children))
      case 'link':
        return h(
          'a',
          { href: node.href, target: '_blank', rel: 'noopener noreferrer nofollow', class: 'text-brand-700 underline break-words' },
          inline(node.children),
        )
    }
  })
}

function block(node: Block): VNode {
  switch (node.type) {
    case 'paragraph':
      return h('p', { class: 'whitespace-pre-line' }, inline(node.children))
    case 'heading':
      return h('p', { class: 'font-semibold' }, inline(node.children))
    case 'list':
      return h(
        node.ordered ? 'ol' : 'ul',
        { class: `space-y-0.5 pl-5 ${node.ordered ? 'list-decimal' : 'list-disc'}` },
        node.items.map((item) => h('li', { class: 'pl-0.5' }, inline(item))),
      )
    case 'code':
      return h('pre', { class: 'max-w-full overflow-x-auto rounded-lg bg-slate-100 p-2 font-mono text-xs' }, [h('code', node.value)])
  }
}

export default defineComponent({
  name: 'MarkdownText',
  props: { source: { type: String as PropType<string>, required: true } },
  setup(props) {
    return () => h('div', { class: 'space-y-2 break-words [overflow-wrap:anywhere]', 'data-testid': 'markdown' }, parseMarkdown(props.source).map(block))
  },
})
</script>
