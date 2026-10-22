import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { usePath } from './usePath'

describe('usePath', () => {
  beforeEach(() => window.history.replaceState(null, '', '/'))

  it('lit le chemin courant', () => {
    window.history.replaceState(null, '', '/alice')

    expect(renderHook(() => usePath()).result.current[0]).toBe('/alice')
  })

  it('navigue sans recharger la page', () => {
    const { result } = renderHook(() => usePath())

    act(() => result.current[1]('/bob'))

    expect(result.current[0]).toBe('/bob')
    expect(window.location.pathname).toBe('/bob')
  })

  it('suit les boutons précédent/suivant du navigateur', () => {
    const { result } = renderHook(() => usePath())
    act(() => result.current[1]('/bob'))

    act(() => {
      window.history.replaceState(null, '', '/alice')
      window.dispatchEvent(new PopStateEvent('popstate'))
    })

    expect(result.current[0]).toBe('/alice')
  })
})
