import { act, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { Gw2BossesCard, type Gw2BossesData, type Gw2BossesProps } from './Gw2BossesCard'

const card: CardView<Gw2BossesProps> = {
  id: 'b',
  type: 'gw2-bosses',
  title: null,
  span: 2,
  rows: 1,
  refresh: 300,
  props: { title: 'Boss du monde', link: 'https://wiki.guildwars2.com/wiki/Event_timers', count: 2 },
}

const data: Gw2BossesData = {
  slots: [
    { id: 'admiral_taidha_covington', name: 'Amiral Taidha Covington', startsAt: '2026-11-16T20:00:00Z', done: true },
    { id: 'svanir_shaman_chief', name: 'Chef chamane de Svanir', startsAt: '2026-11-16T20:15:00Z', done: false },
    { id: 'megadestroyer', name: 'Mégadestructeur', startsAt: '2026-11-16T20:30:00Z', done: true },
    { id: 'fire_elemental', name: 'Élémentaire de feu', startsAt: '2026-11-16T20:45:00Z', done: false },
  ],
  done: 4,
  total: 10,
  resetAt: '2026-11-17T00:00:00Z',
  doneError: null,
}
const result = (d: Gw2BossesData): CardResult<Gw2BossesData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('Gw2BossesCard', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(new Date('2026-11-16T20:07:00Z'))
  })
  afterEach(() => vi.useRealTimers())

  it('titre : lien vers les horaires du wiki, et compteur du jour', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: 'Boss du monde' })
    expect(link.getAttribute('href')).toBe('https://wiki.guildwars2.com/wiki/Event_timers')
    expect(text(screen.getByTitle(/faits aujourd/))).toBe('4/10')
  })

  it('le prochain boss pas fait, avec son compte à rebours', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const next = screen.getByRole('group', { name: 'Prochain boss' })
    expect(text(next)).toContain('Chef chamane de Svanir')
    expect(text(next)).toContain('dans 8 min')
  })

  it('les passages suivants, faits grisés et cochés', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const items = screen.getByRole('list', { name: 'Passages suivants' }).querySelectorAll('li')
    expect(items).toHaveLength(2)
    expect(text(items[0]!)).toContain('Amiral Taidha Covington')
    expect(items[0]!.getAttribute('aria-label')).toContain('fait')
    expect(items[0]!.className).toContain('opacity-50')
    expect(text(items[1]!)).toContain('Mégadestructeur')
  })

  it('le compte à rebours avance sans nouvel appel', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    act(() => vi.advanceTimersByTime(9 * 60_000))

    expect(text(screen.getByRole('group', { name: 'Prochain boss' }))).toContain('en cours depuis 1 min')
  })

  it('tous faits : le dit, avec la réinitialisation', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, slots: data.slots.map((s) => ({ ...s, done: true })), done: 10 })} />)

    expect(text(screen.getByRole('group', { name: 'Prochain boss' }))).toContain('Tous les boss sont faits')
  })

  it('sans clé : l’horaire reste, la raison est dite', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, slots: data.slots.map((s) => ({ ...s, done: false })), done: null, doneError: 'Pas encore de clé pour ce profil' })} />)

    expect(text(screen.getByRole('group', { name: 'Prochain boss' }))).toContain('Amiral Taidha Covington')
    expect(screen.getByTitle('Pas encore de clé pour ce profil')).toBeTruthy()
  })

  it('chargement', () => {
    render(<Gw2BossesCard card={card} result={null} />)

    expect(document.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
