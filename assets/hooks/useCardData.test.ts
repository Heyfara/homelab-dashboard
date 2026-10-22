import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CardResult } from '../http/types'
import { useCardData } from './useCardData'

const ok = (n: number): CardResult => ({ status: 'ok', data: { n }, error: null, fetchedAt: '2026-10-18T10:00:00+00:00' })

function setHidden(hidden: boolean) {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })
  document.dispatchEvent(new Event('visibilitychange'))
}

/** Laisse les promesses en attente se résoudre. */
const flush = () => act(async () => {})

describe('useCardData', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    setHidden(false)
  })
  afterEach(() => vi.useRealTimers())

  it('charge tout de suite puis toutes les `refresh` secondes', async () => {
    let n = 0
    const fetcher = vi.fn(async () => ok(++n))
    const { result } = renderHook(() => useCardData('c1', 30, fetcher))

    expect(result.current.loading).toBe(true)
    await flush()
    expect(result.current.result).toEqual(ok(1))
    expect(result.current.loading).toBe(false)

    await act(() => vi.advanceTimersByTimeAsync(29_000))
    expect(fetcher).toHaveBeenCalledTimes(1)

    await act(() => vi.advanceTimersByTimeAsync(1_000))
    expect(fetcher).toHaveBeenCalledTimes(2)
    expect(result.current.result).toEqual(ok(2))
  })

  it('garde les dernières données quand une requête échoue', async () => {
    const fetcher = vi.fn<(id: string, signal: AbortSignal) => Promise<CardResult>>()
      .mockResolvedValueOnce(ok(1))
      .mockRejectedValueOnce(new Error('réseau coupé'))
      .mockResolvedValueOnce(ok(3))
    const { result } = renderHook(() => useCardData('c1', 10, fetcher))
    await flush()

    await act(() => vi.advanceTimersByTimeAsync(10_000))
    expect(result.current.result).toEqual(ok(1))
    expect(result.current.error).toBe('réseau coupé')

    await act(() => vi.advanceTimersByTimeAsync(10_000))
    expect(result.current.result).toEqual(ok(3))
    expect(result.current.error).toBeNull()
  })

  it('se met en pause quand l’onglet est caché et recharge au retour', async () => {
    const fetcher = vi.fn(async () => ok(1))
    renderHook(() => useCardData('c1', 10, fetcher))
    await flush()

    act(() => setHidden(true))
    await act(() => vi.advanceTimersByTimeAsync(60_000))
    expect(fetcher).toHaveBeenCalledTimes(1)

    act(() => setHidden(false))
    await flush()
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('annule la requête et le minuteur au démontage', async () => {
    let signal: AbortSignal | undefined
    const fetcher = vi.fn((_id: string, s: AbortSignal) => {
      signal = s
      return new Promise<CardResult>(() => {})
    })
    const { unmount } = renderHook(() => useCardData('c1', 10, fetcher))

    unmount()

    expect(signal?.aborted).toBe(true)
    await act(() => vi.advanceTimersByTimeAsync(60_000))
    expect(fetcher).toHaveBeenCalledTimes(1)
  })
})
