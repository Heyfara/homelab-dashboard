import { useRef, type DragEvent, type MouseEvent, type PointerEvent, type RefObject } from 'react'

/** En deçà, c'est un clic, pas un glissement. */
const THRESHOLD = 5

/**
 * Faire défiler une liste horizontale en la glissant à la souris, comme au doigt
 * (le doigt et le trackpad gardent le défilement natif). Pendant le glissement,
 * l'élément porte `data-dragging="true"` : y suspendre le calage (snap) et le
 * défilement doux. Un glissement n'ouvre pas le lien sur lequel on relâche.
 *
 *     const drag = useDragScroll(ref)
 *     <ul ref={ref} {...drag} className="cursor-grab data-[dragging=true]:snap-none …">
 */
export function useDragScroll(ref: RefObject<HTMLElement | null>) {
  const state = useRef({ active: false, moved: false, startX: 0, startScroll: 0, suppressClick: false })

  const end = () => {
    const s = state.current
    s.active = false
    if (s.moved) {
      s.suppressClick = true
      delete ref.current?.dataset.dragging
    }
    s.moved = false
  }

  return {
    onPointerDown(e: PointerEvent) {
      const el = ref.current
      const s = state.current
      s.suppressClick = false
      if (!el || e.pointerType !== 'mouse' || e.button !== 0) return
      Object.assign(s, { active: true, moved: false, startX: e.clientX, startScroll: el.scrollLeft })
    },
    onPointerMove(e: PointerEvent) {
      const el = ref.current
      const s = state.current
      if (!el || !s.active) return
      const dx = e.clientX - s.startX
      if (!s.moved && Math.abs(dx) < THRESHOLD) return
      if (!s.moved) {
        s.moved = true
        el.dataset.dragging = 'true'
        // Garde la main même si la souris sort de la liste.
        el.setPointerCapture?.(e.pointerId)
      }
      el.scrollLeft = s.startScroll - dx
    },
    onPointerUp: end,
    onPointerCancel: end,
    onClickCapture(e: MouseEvent) {
      if (!state.current.suppressClick) return
      state.current.suppressClick = false
      e.preventDefault()
      e.stopPropagation()
    },
    // Sans quoi le navigateur glisse l'image ou le lien au lieu de la liste.
    onDragStart(e: DragEvent) {
      e.preventDefault()
    },
  }
}
