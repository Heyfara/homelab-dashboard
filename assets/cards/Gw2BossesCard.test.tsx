import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { GW2_LOGO } from '../ui/Gw2Logo'
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

const boss = (name: string, zone: string, waypoint: string, image: string | null) => ({ name, zone, waypoint, image })
const data: Gw2BossesData = {
  bosses: {
    admiral_taidha_covington: boss('Amiral Taidha Covington', 'Côte de la marée sanglante', '[&BKgBAAA=]', 'https://wiki.example/taidha.jpg'),
    svanir_shaman_chief: boss('Chef chamane de Svanir', 'Contreforts du voyageur', '[&BMIDAAA=]', 'https://wiki.example/svanir.jpg'),
    megadestroyer: boss('Mégadestructeur', 'Mont Maelström', '[&BM0CAAA=]', null),
    fire_elemental: boss('Élémentaire de feu', 'Province de Metrica', '[&BEcAAAA=]', null),
  },
  slots: [
    { id: 'admiral_taidha_covington', startsAt: '2026-11-16T20:00:00Z', done: true },
    { id: 'svanir_shaman_chief', startsAt: '2026-11-16T20:15:00Z', done: false },
    { id: 'megadestroyer', startsAt: '2026-11-16T20:30:00Z', done: true },
    { id: 'fire_elemental', startsAt: '2026-11-16T20:45:00Z', done: false },
  ],
  done: ['admiral_taidha_covington', 'megadestroyer'],
  resetAt: '2026-11-17T00:00:00Z',
  doneError: null,
  waypointIcon: 'https://render.example/waypoint.png',
}
const result = (d: Gw2BossesData): CardResult<Gw2BossesData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('Gw2BossesCard', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    vi.setSystemTime(new Date('2026-11-16T20:07:00Z'))
  })
  afterEach(() => vi.useRealTimers())

  it('titre : lien vers les horaires du wiki', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: 'Boss du monde' }).getAttribute('href')).toBe('https://wiki.guildwars2.com/wiki/Event_timers')
    expect(screen.getByRole('heading').parentElement!.querySelector('img')?.getAttribute('src')).toBe(GW2_LOGO)
  })

  it('jauge du jour : une pastille par boss, pleine quand il est fait', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const gauge = screen.getByRole('list', { name: '2 boss sur 4 faits aujourd’hui' })
    const dots = gauge.querySelectorAll('li')
    expect(dots).toHaveLength(4)
    expect(dots[0]!.getAttribute('title')).toBe('Amiral Taidha Covington : fait')
    expect(dots[1]!.getAttribute('title')).toBe('Chef chamane de Svanir : à faire')
  })

  it('le prochain boss pas fait : portrait, zone, compte à rebours', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const next = screen.getByRole('group', { name: 'Prochain boss' })
    expect(text(next)).toContain('Chef chamane de Svanir')
    expect(text(next)).toContain('Contreforts du voyageur')
    expect(text(next)).toContain('dans 8 min')
    expect(next.querySelector('img')?.getAttribute('src')).toBe('https://wiki.example/svanir.jpg')
  })

  it('la ligne du prochain passage se remplit avant son heure, avec le temps restant', () => {
    vi.setSystemTime(new Date('2026-11-16T20:16:00Z'))
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const bar = screen.getByRole('progressbar', { name: 'Mégadestructeur : dans 14 min' })
    expect(bar.closest('li')!.textContent).toContain('dans 14 min')
    expect(bar.getAttribute('aria-valuenow')).toBe('7')
    expect(bar.style.width).toBe('7%')
    expect(bar.className).toContain('bg-amber-300')
    expect(bar.className).not.toContain('mask-image')

    act(() => vi.advanceTimersByTime(10 * 60_000))
    const soon = screen.getByRole('progressbar')
    expect(soon.getAttribute('aria-valuenow')).toBe('73')
    expect(soon.className).toContain('bg-orange-400')
  })

  it('pas de barre dans la liste quand le prochain passage est déjà à gauche', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    expect(screen.queryByRole('progressbar')).toBeNull()
  })

  it('copie le point de passage pour le chat du jeu', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const icon = screen.getByRole('button', { name: /point de passage/ }).querySelector('img')
    expect(icon?.getAttribute('src')).toBe('https://render.example/waypoint.png')
    expect(icon?.getAttribute('class')).toContain('size-8')

    await act(async () => fireEvent.click(screen.getByRole('button', { name: /point de passage/ })))

    expect(writeText).toHaveBeenCalledWith('[&BMIDAAA=]')
    expect(screen.getByRole('button', { name: /point de passage/ }).textContent).toContain('Copié')
    act(() => vi.advanceTimersByTime(2000))
    expect(screen.getByRole('button', { name: /point de passage/ }).textContent).not.toContain('Copié')
  })

  it('sans icône du jeu : le bouton reste, en texte', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, waypointIcon: null })} />)

    const button = screen.getByRole('button', { name: /point de passage/ })
    expect(button.querySelector('img')).toBeNull()
    expect(button.textContent).toBe('PP')
  })

  it('sans portrait : l’initiale du boss', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, slots: data.slots.map((s) => ({ ...s, done: s.id !== 'megadestroyer' })) })} />)

    const next = screen.getByRole('group', { name: 'Prochain boss' })
    expect(text(next)).toContain('Mégadestructeur')
    expect(next.querySelector(':scope > img')).toBeNull()
    expect(text(next)).toContain('M')
  })

  it('les passages suivants, faits grisés et cochés', () => {
    render(<Gw2BossesCard card={card} result={result(data)} />)

    const items = screen.getByRole('list', { name: 'Passages suivants' }).querySelectorAll(':scope > li')
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

  it('tous faits : le dit', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, slots: data.slots.map((s) => ({ ...s, done: true })), done: Object.keys(data.bosses) })} />)

    expect(text(screen.getByRole('group', { name: 'Prochain boss' }))).toContain('Tous les boss sont faits')
  })

  it('sans clé : l’horaire reste, la raison est dite, pas de jauge', () => {
    render(<Gw2BossesCard card={card} result={result({ ...data, slots: data.slots.map((s) => ({ ...s, done: false })), done: null, doneError: 'Pas encore de clé pour ce profil' })} />)

    expect(text(screen.getByRole('group', { name: 'Prochain boss' }))).toContain('Amiral Taidha Covington')
    expect(screen.getByTitle('Pas encore de clé pour ce profil')).toBeTruthy()
    expect(screen.queryByRole('list', { name: /faits aujourd/ })).toBeNull()
  })

  it('chargement', () => {
    render(<Gw2BossesCard card={card} result={null} />)

    expect(document.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
