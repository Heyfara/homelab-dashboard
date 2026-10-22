import { useLayoutEffect, useState, type RefObject } from 'react'

/** Nombre d'éléments dont le début dépasse la largeur visible (colonnes masquées). */
export function countOverflowing(visibleWidth: number, offsets: number[]): number {
  if (visibleWidth <= 0) return 0
  return offsets.filter((left) => left >= visibleWidth).length
}

/**
 * Combien d'enfants d'une liste en colonnes (`overflow: hidden`) sont hors de
 * vue. Recalculé quand la liste change de taille ou de contenu (`deps`).
 */
export function useOverflowCount(ref: RefObject<HTMLElement | null>, deps: unknown): number {
  const [hidden, setHidden] = useState(0)

  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    const measure = () => {
      const offsets = Array.from(el.children, (child) => (child as HTMLElement).offsetLeft - el.offsetLeft)
      setHidden(countOverflowing(el.clientWidth, offsets))
    }
    measure()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    return () => observer.disconnect()
  }, [ref, deps])

  return hidden
}
