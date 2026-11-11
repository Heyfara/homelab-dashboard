import { useEffect, useRef, useState } from 'react'

/**
 * Vrai quand l'élément est à l'écran ou en approche (marge d'un demi-écran) :
 * sur mobile, une colonne de 30 cartes n'en montre que 3 ou 4, inutile
 * d'interroger les autres. Sans IntersectionObserver : toujours vrai.
 */
export function useInView<T extends Element>(): [React.RefObject<T | null>, boolean] {
  const ref = useRef<T>(null)
  const [inView, setInView] = useState(() => typeof IntersectionObserver === 'undefined')

  useEffect(() => {
    const element = ref.current
    if (!element || typeof IntersectionObserver === 'undefined') return
    const observer = new IntersectionObserver((entries) => setInView(entries.some((e) => e.isIntersecting)), { rootMargin: '50% 0px' })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return [ref, inView]
}
