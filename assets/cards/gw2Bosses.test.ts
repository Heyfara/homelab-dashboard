import { describe, expect, it } from 'vitest'
import { bossesView, countdown, type BossSlot } from './gw2Bosses'

const slot = (id: string, startsAt: string, done = false): BossSlot => ({ id, name: id, startsAt, done })
const slots: BossSlot[] = [
  slot('taidha', '2026-11-16T20:00:00Z', true),
  slot('svanir', '2026-11-16T20:15:00Z', true),
  slot('mega', '2026-11-16T20:30:00Z'),
  slot('feu', '2026-11-16T20:45:00Z', true),
  slot('destructeur', '2026-11-16T21:00:00Z'),
  slot('guivre', '2026-11-16T21:15:00Z'),
]
const at = (iso: string) => new Date(iso)

describe('bossesView', () => {
  it('met en avant le prochain boss pas encore fait, la liste garde les faits', () => {
    const view = bossesView(slots, 3, at('2026-11-16T20:07:00Z'))

    expect(view.next?.id).toBe('mega')
    expect(view.list.map((s) => s.id)).toEqual(['taidha', 'svanir', 'feu'])
  })

  it('un boss fini depuis 15 min disparaît, même pas fait', () => {
    const view = bossesView(slots, 2, at('2026-11-16T20:46:00Z'))

    expect(view.next?.id).toBe('destructeur')
    expect(view.list.map((s) => s.id)).toEqual(['feu', 'guivre'])
  })

  it('boss en cours : encore le prochain pendant 15 min', () => {
    expect(bossesView(slots, 1, at('2026-11-16T20:44:59Z')).next?.id).toBe('mega')
  })

  it('tous faits : pas de prochain', () => {
    const view = bossesView(slots.map((s) => ({ ...s, done: true })), 2, at('2026-11-16T20:07:00Z'))

    expect(view.next).toBeNull()
    expect(view.list.map((s) => s.id)).toEqual(['taidha', 'svanir'])
  })
})

describe('countdown', () => {
  const start = '2026-11-16T20:30:00Z'

  it('en cours', () => {
    expect(countdown(start, at('2026-11-16T20:33:10Z'))).toBe('en cours depuis 3 min')
    expect(countdown(start, at('2026-11-16T20:30:20Z'))).toBe('en cours')
  })

  it('à venir', () => {
    expect(countdown(start, at('2026-11-16T20:29:15Z'))).toBe('dans 45 s')
    expect(countdown(start, at('2026-11-16T20:17:30Z'))).toBe('dans 13 min')
    expect(countdown(start, at('2026-11-16T19:25:00Z'))).toBe('dans 1 h 05')
  })
})
