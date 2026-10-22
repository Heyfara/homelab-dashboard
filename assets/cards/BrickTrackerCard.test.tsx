import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { BrickTrackerCard, type BrickTrackerData, type BrickTrackerProps } from './BrickTrackerCard'

const card: CardView<BrickTrackerProps> = { id: 'k', type: 'bricktracker', title: null, span: 2, rows: 2, refresh: 3600, props: { title: 'BrickTracker', service: 'BrickTracker', url: 'https://lego.example', icon: 'sh-bricktracker' } }

const data: BrickTrackerData = {
  stats: { sets: 35, parts: 17480, minifigures: 51, missingParts: 0, damagedParts: 0 },
  sets: [
    { id: 'a', number: '99901-1', name: 'Château fictif', theme: 'Castle', parts: 1234, minifigures: 4, image: 'https://lego.example/data/sets/99901-1.jpg', link: 'https://lego.example/sets/a/details' },
    { id: 'b', number: '99902-1', name: 'Fusée', theme: null, parts: 87, minifigures: 0, image: 'https://lego.example/data/sets/99902-1.jpg', link: 'https://lego.example/sets/b/details' },
  ],
}
const result = (d: BrickTrackerData): CardResult<BrickTrackerData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('BrickTrackerCard', () => {
  it('totaux de la collection', () => {
    render(<BrickTrackerCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('heading').parentElement!)).toContain('35 sets · 17 480 pièces · 51 minifigs')
  })

  it('derniers sets en slider : boîte, nom, thème et pièces, numéro en badge', () => {
    render(<BrickTrackerCard card={card} result={result(data)} />)

    const list = screen.getByRole('list', { name: 'Derniers sets' })
    expect(list.querySelector('li')?.className).toContain('aspect-square')
    const first = screen.getByRole('link', { name: (n) => n.replace(/[\u00a0\u202f]/g, ' ') === 'Château fictif (Castle · 1 234 pièces)' })
    expect(first.getAttribute('href')).toBe('https://lego.example/sets/a/details')
    expect(text(first)).toContain('99901-1')
    screen.getByRole('link', { name: 'Fusée (87 pièces)' })
  })

  it('pièces manquantes ou abîmées : signalées', () => {
    render(<BrickTrackerCard card={card} result={result({ ...data, stats: { ...data.stats, missingParts: 3, damagedParts: 1 } })} />)

    expect(text(screen.getByRole('link', { name: /pièces à retrouver/ }))).toBe('4 pièces à retrouver')
  })

  it('rien de manquant : pas d’alerte', () => {
    render(<BrickTrackerCard card={card} result={result(data)} />)

    expect(screen.queryByText(/à retrouver/)).toBeNull()
  })

  it('annonce le chargement', () => {
    const { container } = render(<BrickTrackerCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
