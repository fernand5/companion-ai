import { describe, expect, it } from 'vitest'

import router from '@/router'

const scroll = router.options.scrollBehavior!
const to = (hash: string) => ({ hash }) as never

describe('scrolling after a navigation', () => {
  it('lands on the section a link points to, below the header that stays on screen', async () => {
    expect(await scroll(to('#progression-heading'), {} as never, null)).toEqual({ el: '#progression-heading', top: 72, behavior: 'smooth' })
  })

  it('goes back to where the user was, or to the top of a new page', async () => {
    expect(await scroll(to(''), {} as never, { top: 300, left: 0 })).toEqual({ top: 300, left: 0 })
    expect(await scroll(to(''), {} as never, null)).toEqual({ top: 0 })
  })
})
