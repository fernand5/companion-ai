import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { makeItem } from '@/components/__tests__/progressionFixtures'
import * as progressionService from '@/services/progression'
import { useProgressionStore } from '@/stores/progression'

vi.mock('@/services/progression', () => ({
  fetchRecommendations: vi.fn(),
  approveProposal: vi.fn(),
  keepCurrentTarget: vi.fn(),
}))

const list = (...data: ReturnType<typeof makeItem>[]) => ({ data, meta: { policy_version: 'p', max_exercises: 8, generated_at: 'now' } })

describe('progression store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads recommendations', async () => {
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem()))
    const store = useProgressionStore()

    await store.load()

    expect(store.items).toHaveLength(1)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('reports a load error with the server message, or a fallback', async () => {
    const store = useProgressionStore()

    vi.mocked(progressionService.fetchRecommendations).mockRejectedValueOnce({ response: { data: { message: 'Nope' } } })
    await store.load()
    expect(store.error).toBe('Nope')

    vi.mocked(progressionService.fetchRecommendations).mockRejectedValueOnce(new Error('network'))
    await store.load()
    expect(store.error).toBe("Couldn't load your progression recommendations.")
  })

  it('accept sends only the proposal id, then reloads and records success', async () => {
    vi.mocked(progressionService.approveProposal).mockResolvedValue({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: '2026-10-19', from_kg: 40, to_kg: 42.5 })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem()))
    const store = useProgressionStore()
    await store.load()

    expect(await store.accept(store.items[0])).toBe(true)

    expect(progressionService.approveProposal).toHaveBeenCalledWith('sealed-proposal-1')
    expect(progressionService.fetchRecommendations).toHaveBeenCalledTimes(2)
    expect(store.stateFor('Bench Press')).toEqual({ status: 'applied', message: 'Bench Press is now 42.5 kg for 2026-10-19.' })
  })

  it('never calls the server for a recommendation that has no proposal', async () => {
    const store = useProgressionStore()
    const item = makeItem({ actionable: false, proposal: null })

    expect(await store.accept(item)).toBe(false)
    expect(await store.keep(item)).toBe(false)
    expect(progressionService.approveProposal).not.toHaveBeenCalled()
    expect(progressionService.keepCurrentTarget).not.toHaveBeenCalled()
  })

  it('ignores a second accept while one is in flight', async () => {
    let resolve!: (v: never) => void
    vi.mocked(progressionService.approveProposal).mockReturnValue(new Promise((r) => (resolve = r as never)))
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem()))
    const store = useProgressionStore()
    const item = makeItem()

    const first = store.accept(item)
    expect(store.stateFor('Bench Press').status).toBe('working')
    expect(await store.accept(item)).toBe(false)
    expect(await store.keep(item)).toBe(false)
    expect(progressionService.approveProposal).toHaveBeenCalledTimes(1)

    resolve({ applied: true, exercise: { name: 'Bench Press', slug: 'bench-press' }, date: 'd', from_kg: 40, to_kg: 42.5 } as never)
    await first
  })

  it.each([409, 422])('treats a %s as a stale proposal and keeps the server message', async (status) => {
    vi.mocked(progressionService.approveProposal).mockRejectedValue({ response: { status, data: { message: 'That recommendation has expired.' } } })
    const store = useProgressionStore()

    expect(await store.accept(makeItem())).toBe(false)

    expect(store.stateFor('Bench Press')).toEqual({ status: 'stale', message: 'That recommendation has expired.' })
    expect(progressionService.fetchRecommendations).not.toHaveBeenCalled()
  })

  it('treats a network or server failure as retryable, not stale', async () => {
    vi.mocked(progressionService.approveProposal).mockRejectedValue(new Error('network'))
    const store = useProgressionStore()

    await store.accept(makeItem())

    expect(store.stateFor('Bench Press').status).toBe('failed')
  })

  it('a reload clears stale notes but keeps a just-applied one, and the server takes over for a kept target', async () => {
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValue(list(makeItem(), makeItem({ exercise: { name: 'Squat', slug: 'squat' } })))
    vi.mocked(progressionService.approveProposal).mockRejectedValue({ response: { status: 409, data: { message: 'stale' } } })
    const store = useProgressionStore()
    await store.load()

    await store.accept(store.items[1])
    expect(store.stateFor('Squat').status).toBe('stale')

    await store.load()
    expect(store.stateFor('Squat').status).toBe('idle')
  })

  it('keep calls the rejection endpoint with the proposal, then shows what the server now says', async () => {
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true })
    vi.mocked(progressionService.fetchRecommendations)
      .mockResolvedValueOnce(list(makeItem()))
      .mockResolvedValueOnce(list(makeItem({ actionable: false, proposal: null, not_actionable_reason: 'dismissed', dismissed: { at: '2026-10-17T12:05:00+00:00' } })))
    const store = useProgressionStore()
    await store.load()

    expect(await store.keep(store.items[0])).toBe(true)

    expect(progressionService.keepCurrentTarget).toHaveBeenCalledWith('sealed-proposal-1')
    expect(progressionService.approveProposal).not.toHaveBeenCalled()
    expect(store.items[0].dismissed).toEqual({ at: '2026-10-17T12:05:00+00:00' })
    expect(store.items[0].proposal).toBeNull()
  })

  it('a kept confirmation survives a failed reload, so the user is not left with nothing', async () => {
    vi.mocked(progressionService.keepCurrentTarget).mockResolvedValue({ kept: true })
    vi.mocked(progressionService.fetchRecommendations).mockResolvedValueOnce(list(makeItem())).mockRejectedValueOnce(new Error('network'))
    const store = useProgressionStore()
    await store.load()

    await store.keep(store.items[0])

    expect(store.stateFor('Bench Press')).toEqual({ status: 'kept', message: 'Keeping Bench Press as planned.' })
  })

  it('a failed keep can be retried', async () => {
    vi.mocked(progressionService.keepCurrentTarget).mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ kept: true })
    const store = useProgressionStore()
    const item = makeItem()

    expect(await store.keep(item)).toBe(false)
    expect(store.stateFor('Bench Press').status).toBe('failed')
    expect(await store.keep(item)).toBe(true)
  })
})
