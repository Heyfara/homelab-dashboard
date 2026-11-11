import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CardResult } from '../http/types'
import { snapshots } from '../http/snapshots'
import { useCardData } from './useCardData'

const ok = (n: number): Extract<CardResult, { status: 'ok' }> => ({ status: 'ok', data: { n }, error: null, fetchedAt: '2026-10-18T10:00:00+00:00' })

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
    localStorage.clear()
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

    await act(() => vi.advanceTimersByTimeAsync(5_000))
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
  it('réessaie vite après un échec réseau, de plus en plus espacé, sans dépasser `refresh`', async () => {
    const fetcher = vi.fn<(id: string, signal: AbortSignal) => Promise<CardResult>>().mockRejectedValue(new Error('réseau coupé'))
    renderHook(() => useCardData('c1', 1800, fetcher))
    await flush()
    expect(fetcher).toHaveBeenCalledTimes(1)

    await act(() => vi.advanceTimersByTimeAsync(5_000))
    expect(fetcher).toHaveBeenCalledTimes(2)
    await act(() => vi.advanceTimersByTimeAsync(9_000))
    expect(fetcher).toHaveBeenCalledTimes(2)
    await act(() => vi.advanceTimersByTimeAsync(1_000))
    expect(fetcher).toHaveBeenCalledTimes(3)
  })

  it('abandonne une requête trop longue et réessaie', async () => {
    const signals: AbortSignal[] = []
    const fetcher = vi.fn(
      (_id: string, signal: AbortSignal) =>
        new Promise<CardResult>((_resolve, reject) => {
          signals.push(signal)
          signal.addEventListener('abort', () => reject(new Error('abandonnée')))
        }),
    )
    const { result } = renderHook(() => useCardData('c1', 30, fetcher))

    await act(() => vi.advanceTimersByTimeAsync(20_000))
    expect(signals[0]!.aborted).toBe(true)
    expect(result.current.error).toMatch(/trop lent/)

    await act(() => vi.advanceTimersByTimeAsync(5_000))
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('recharge dès que le réseau revient', async () => {
    const fetcher = vi.fn<(id: string, signal: AbortSignal) => Promise<CardResult>>().mockRejectedValueOnce(new Error('réseau coupé')).mockResolvedValue(ok(2))
    const { result } = renderHook(() => useCardData('c1', 1800, fetcher))
    await flush()

    act(() => void window.dispatchEvent(new Event('online')))
    await flush()
    expect(result.current.result).toEqual(ok(2))
  })

  it('redemande bientôt une valeur périmée que le serveur relit', async () => {
    const fetcher = vi.fn<(id: string, signal: AbortSignal) => Promise<CardResult>>().mockResolvedValueOnce({ ...ok(1), stale: true }).mockResolvedValue(ok(2))
    const { result } = renderHook(() => useCardData('c1', 1800, fetcher))
    await flush()
    expect(result.current.result).toEqual({ ...ok(1), stale: true })

    await act(() => vi.advanceTimersByTimeAsync(3_000))
    expect(result.current.result).toEqual(ok(2))
  })

  it('affiche tout de suite les dernières données gardées, puis les remplace', async () => {
    snapshots.saveCard('c1', ok(1))
    const fetcher = vi.fn(async () => ok(2))
    const { result } = renderHook(() => useCardData('c1', 30, fetcher))

    expect(result.current.result).toEqual(ok(1))
    expect(result.current.loading).toBe(false)
    await flush()
    expect(result.current.result).toEqual(ok(2))
    expect(snapshots.card('c1')).toEqual(ok(2))
  })

  it('n’interroge pas une carte hors de l’écran, et la charge quand elle y entre', async () => {
    const fetcher = vi.fn(async () => ok(1))
    const { rerender } = renderHook(({ active }) => useCardData('c1', 30, fetcher, { active }), { initialProps: { active: false } })
    await act(() => vi.advanceTimersByTimeAsync(60_000))
    expect(fetcher).not.toHaveBeenCalled()

    rerender({ active: true })
    await flush()
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('au retour à l’écran, ne recharge pas une carte encore à jour', async () => {
    const fetcher = vi.fn(async () => ok(1))
    const { rerender } = renderHook(({ active }) => useCardData('c1', 30, fetcher, { active }), { initialProps: { active: true } })
    await flush()

    rerender({ active: false })
    await act(() => vi.advanceTimersByTimeAsync(10_000))
    rerender({ active: true })
    await flush()
    expect(fetcher).toHaveBeenCalledTimes(1)

    await act(() => vi.advanceTimersByTimeAsync(20_000))
    expect(fetcher).toHaveBeenCalledTimes(2)
  })
})
