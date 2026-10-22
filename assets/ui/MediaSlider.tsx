import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { useDragScroll } from '../hooks/useDragScroll'

type Props<T> = {
  /** Nom de la liste pour les lecteurs d'écran (« Derniers ajouts ») */
  label: string
  items: T[]
  itemKey: (item: T) => string
  renderItem: (item: T) => ReactNode
  /** Format des vignettes (classe Tailwind écrite en entier) : aspect-[2/3], aspect-square… */
  aspect?: string
  previousLabel?: string
  nextLabel?: string
}

/**
 * Vignettes en ligne qui défilent : flèches (visibles s'il reste à voir de ce
 * côté), glisser à la souris (useDragScroll), doigt ou trackpad. Les vignettes
 * prennent la hauteur disponible et se calent sur le bord gauche (scroll-snap).
 */
export function MediaSlider<T>({ label, items, itemKey, renderItem, aspect = 'aspect-[2/3]', previousLabel = 'Précédents', nextLabel = 'Suivants' }: Props<T>) {
  const list = useRef<HTMLUListElement>(null)
  const drag = useDragScroll(list)
  const [edges, setEdges] = useState({ previous: false, next: false })

  const update = () => {
    const el = list.current
    if (!el) return
    setEdges({ previous: el.scrollLeft > 1, next: el.scrollLeft + el.clientWidth < el.scrollWidth - 1 })
  }

  useLayoutEffect(() => {
    update()
    if (typeof ResizeObserver === 'undefined' || !list.current) return
    const observer = new ResizeObserver(update)
    observer.observe(list.current)
    return () => observer.disconnect()
  }, [items])

  // Défile de presque une largeur : la dernière vignette visible reste en repère.
  const scroll = (direction: 1 | -1) => list.current?.scrollBy({ left: direction * list.current.clientWidth * 0.9, behavior: 'smooth' })

  return (
    <div className="group relative min-h-0 flex-1">
      <ul
        ref={list}
        aria-label={label}
        onScroll={update}
        {...drag}
        className="flex h-full cursor-grab snap-x snap-mandatory gap-3 overflow-x-auto overflow-y-hidden scroll-smooth select-none [scrollbar-width:none] data-[dragging=true]:cursor-grabbing data-[dragging=true]:snap-none data-[dragging=true]:scroll-auto [&::-webkit-scrollbar]:hidden"
      >
        {items.map((item) => (
          <li key={itemKey(item)} className={`h-full shrink-0 snap-start ${aspect}`}>
            {renderItem(item)}
          </li>
        ))}
      </ul>
      <SliderButton label={previousLabel} side="left" disabled={!edges.previous} onClick={() => scroll(-1)} />
      <SliderButton label={nextLabel} side="right" disabled={!edges.next} onClick={() => scroll(1)} />
    </div>
  )
}

function SliderButton({ label, side, disabled, onClick }: { label: string; side: 'left' | 'right'; disabled: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={`absolute top-1/2 ${side === 'left' ? 'left-1' : 'right-1'} grid size-8 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-lg leading-none text-zinc-800 shadow-md ring-1 ring-black/10 transition hover:bg-white focus-visible:outline-2 focus-visible:outline-indigo-500 disabled:pointer-events-none disabled:opacity-0 motion-reduce:transition-none dark:bg-slate-800/90 dark:text-slate-100 dark:ring-white/10 dark:hover:bg-slate-700`}
    >
      <span aria-hidden="true">{side === 'left' ? '‹' : '›'}</span>
    </button>
  )
}
