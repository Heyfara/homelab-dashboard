/** Avance (+1) ou recule (-1) une carte d'une place, sans sortir de la liste. */
export function moveBy(ids: string[], id: string, delta: -1 | 1): string[] {
  const from = ids.indexOf(id)
  const to = from + delta
  if (from < 0 || to < 0 || to >= ids.length) return ids
  const next = [...ids]
  next.splice(from, 1)
  next.splice(to, 0, id)
  return next
}

/**
 * Pose `dragged` juste avant ou après `target`. Renvoie le même tableau si
 * l'ordre ne change pas : pendant un glisser, le survol se répète des dizaines
 * de fois par seconde et ne doit pas relancer de rendu pour rien.
 */
export function placeNextTo(ids: string[], dragged: string, target: string, side: 'before' | 'after'): string[] {
  if (dragged === target || !ids.includes(dragged) || !ids.includes(target)) return ids
  const next = ids.filter((id) => id !== dragged)
  next.splice(next.indexOf(target) + (side === 'after' ? 1 : 0), 0, dragged)
  return next.every((id, i) => id === ids[i]) ? ids : next
}
