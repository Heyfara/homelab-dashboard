import { act, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useInView } from './useInView'

function Probe({ onChange }: { onChange: (v: boolean) => void }) {
  const [ref, inView] = useInView<HTMLDivElement>()
  onChange(inView)
  return <div ref={ref} />
}

describe('useInView', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('considère l’élément visible sans IntersectionObserver', () => {
    vi.stubGlobal('IntersectionObserver', undefined)
    const seen: boolean[] = []
    render(<Probe onChange={(v) => seen.push(v)} />)
    expect(seen.at(-1)).toBe(true)
  })

  it('suit l’entrée et la sortie de l’écran, avec une marge', () => {
    let callback: IntersectionObserverCallback = () => {}
    let options: IntersectionObserverInit | undefined
    const disconnect = vi.fn()
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        constructor(cb: IntersectionObserverCallback, opts?: IntersectionObserverInit) {
          callback = cb
          options = opts
        }
        observe() {}
        disconnect = disconnect
      },
    )
    const seen: boolean[] = []
    const { unmount } = render(<Probe onChange={(v) => seen.push(v)} />)
    expect(seen.at(-1)).toBe(false)
    expect(options?.rootMargin).toBeTruthy()

    act(() => callback([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver))
    expect(seen.at(-1)).toBe(true)
    act(() => callback([{ isIntersecting: false } as IntersectionObserverEntry], {} as IntersectionObserver))
    expect(seen.at(-1)).toBe(false)

    unmount()
    expect(disconnect).toHaveBeenCalled()
  })
})
