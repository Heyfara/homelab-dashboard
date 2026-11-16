/** Un passage de boss du monde (Gw2BossesCard::fetch() côté PHP). */
export type BossSlot = { id: string; name: string; startsAt: string; done: boolean }

/** Un boss est « en cours » pendant 15 min après son apparition (WorldBossSchedule::ACTIVE_MINUTES). */
export const ACTIVE_MS = 15 * 60_000

/**
 * Ce que la carte montre à l'instant `now` : le prochain boss pas encore fait
 * (en cours ou à venir), puis les `count` passages suivants dans l'ordre, faits compris.
 */
export function bossesView(slots: BossSlot[], count: number, now: Date): { next: BossSlot | null; list: BossSlot[] } {
  const live = slots.filter((s) => new Date(s.startsAt).getTime() + ACTIVE_MS > now.getTime())
  const next = live.find((s) => !s.done) ?? null

  return { next, list: live.filter((s) => s !== next).slice(0, count) }
}

/** « dans 13 min », « dans 1 h 05 », « dans 45 s », « en cours depuis 3 min ». */
export function countdown(startsAt: string, now: Date): string {
  const seconds = (new Date(startsAt).getTime() - now.getTime()) / 1000
  if (seconds <= 0) {
    const since = Math.floor(-seconds / 60)

    return since < 1 ? 'en cours' : `en cours depuis ${since} min`
  }
  if (seconds < 60) return `dans ${Math.ceil(seconds)} s`
  const minutes = Math.ceil(seconds / 60)
  if (minutes < 60) return `dans ${minutes} min`

  return `dans ${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`
}
