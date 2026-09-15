import { nextTick, type Ref } from 'vue'

export function useAutoScroll(container: Ref<HTMLElement | null>) {
  async function scrollToBottom() {
    await nextTick()
    const el = container.value
    if (el) {
      el.scrollTop = el.scrollHeight
    }
  }

  return { scrollToBottom }
}
