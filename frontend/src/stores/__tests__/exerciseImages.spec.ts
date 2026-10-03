import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as exerciseImageService from '@/services/exerciseImages'
import { MAX_POLLS, POLL_INTERVAL_MS, useExerciseImageStore } from '@/stores/exerciseImages'

vi.mock('@/services/exerciseImages', () => ({
  requestExerciseImage: vi.fn(),
}))

const request = vi.mocked(exerciseImageService.requestExerciseImage)
const generating = { image_url: null, status: 'generating' as const }
const ready = { image_url: 'https://img.example/squat.jpg', status: 'ready' as const }

describe('exerciseImages store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('stores the url when the image is already ready', async () => {
    request.mockResolvedValue(ready)
    const store = useExerciseImageStore()

    await store.request(1, 10)

    expect(store.entryFor(10)).toEqual({ state: 'ready', url: ready.image_url })
    expect(request).toHaveBeenCalledTimes(1)
    expect(request).toHaveBeenCalledWith(1, 10)
  })

  it('polls while the backend is generating, then shows the image', async () => {
    request.mockResolvedValueOnce(generating).mockResolvedValueOnce(generating).mockResolvedValueOnce(ready)
    const store = useExerciseImageStore()

    const pending = store.request(1, 10)
    await vi.advanceTimersByTimeAsync(0)
    expect(store.entryFor(10)?.state).toBe('loading')
    expect(request).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(request).toHaveBeenCalledTimes(2)
    expect(store.entryFor(10)?.state).toBe('loading')

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    await pending
    expect(request).toHaveBeenCalledTimes(3)
    expect(store.entryFor(10)).toEqual({ state: 'ready', url: ready.image_url })
  })

  it('de-duplicates concurrent requests for the same exercise', async () => {
    request.mockResolvedValue(ready)
    const store = useExerciseImageStore()

    await Promise.all([store.request(1, 10), store.request(1, 10), store.request(1, 10)])

    expect(request).toHaveBeenCalledTimes(1)
  })

  it('does not ask again once an image is ready', async () => {
    request.mockResolvedValue(ready)
    const store = useExerciseImageStore()

    await store.request(1, 10)
    await store.request(1, 10)

    expect(request).toHaveBeenCalledTimes(1)
  })

  it('records a failure quietly when the request rejects (503, 422, network)', async () => {
    request.mockRejectedValue(new Error('503'))
    const store = useExerciseImageStore()

    await expect(store.request(1, 10)).resolves.toBeUndefined()

    expect(store.entryFor(10)).toEqual({ state: 'failed', url: null })
  })

  it('treats an unavailable answer as a failure', async () => {
    request.mockResolvedValue({ image_url: null, status: 'unavailable' })
    const store = useExerciseImageStore()

    await store.request(1, 10)

    expect(store.entryFor(10)?.state).toBe('failed')
  })

  it('gives up after the polling budget instead of spinning forever', async () => {
    request.mockResolvedValue(generating)
    const store = useExerciseImageStore()

    const pending = store.request(1, 10)
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * (MAX_POLLS + 1))
    await pending

    expect(request).toHaveBeenCalledTimes(MAX_POLLS)
    expect(store.entryFor(10)?.state).toBe('failed')
  })

  it('cancel stops polling and leaves no failure behind', async () => {
    request.mockResolvedValue(generating)
    const store = useExerciseImageStore()

    const pending = store.request(1, 10)
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(request).toHaveBeenCalledTimes(2)

    store.cancel(10)
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 5)
    await pending

    expect(request).toHaveBeenCalledTimes(2)
    expect(store.entryFor(10)).toBeNull()
  })

  it('keeps separate state per exercise', async () => {
    request.mockResolvedValueOnce(ready).mockRejectedValueOnce(new Error('503'))
    const store = useExerciseImageStore()

    await store.request(1, 10)
    await store.request(1, 11)

    expect(store.entryFor(10)?.state).toBe('ready')
    expect(store.entryFor(11)?.state).toBe('failed')
  })
})
