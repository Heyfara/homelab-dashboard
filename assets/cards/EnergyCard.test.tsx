import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { EnergyCard, formatPower, type EnergyData, type EnergyProps } from './EnergyCard'

const card: CardView<EnergyProps> = {
  id: 'e',
  type: 'energy',
  title: null,
  span: 2,
  rows: 2,
  refresh: 60,
  props: { title: 'Énergie', url: 'https://ha.example/energy' },
}

const data: EnergyData = {
  now: { solar: 412.5, grid: 2780 },
  price: 0.2,
  today: { solar: 1.5, grid: 10, cost: 2, saved: 0.3 },
  day: { at: ['2026-11-12T12:00:00+01:00', '2026-11-12T13:00:00+01:00'], solar: [350, 400], grid: [600, 300] },
  month: { at: ['2026-11-11', '2026-11-12'], solar: [2.25, 1.5], grid: [9.5, 10] },
}
const result = (d: EnergyData): CardResult<EnergyData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-11-12T14:00:00+01:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('formatPower', () => {
  it('en W sous 1 kW, en kW au-delà', () => {
    expect(formatPower(412.5)).toBe('413 W')
    expect(formatPower(2780)).toBe('2,8 kW')
    expect(formatPower(630, 'VA')).toBe('630 VA')
    expect(formatPower(2780, 'VA')).toBe('2,8 kVA')
  })
})

describe('EnergyCard', () => {
  it('puissance du moment et énergie du jour', () => {
    render(<EnergyCard card={card} result={result(data)} />)

    const solar = screen.getByRole('group', { name: 'Solaire' })
    expect(text(solar)).toContain('413 W')
    expect(text(solar)).toContain('1,5 kWh')
    expect(text(solar)).toContain('0,30 €')

    const grid = screen.getByRole('group', { name: 'Réseau' })
    expect(text(grid)).toContain('2,8 kVA')
    expect(text(grid)).toContain('10 kWh')
    expect(text(grid)).toContain('2,00 €')

    const house = screen.getByRole('group', { name: 'Maison' })
    expect(text(house)).toContain('≈ 3,2 kW')
    expect(text(house)).toContain('11,5 kWh')
  })

  it('lien vers le tableau Énergie de Home Assistant', () => {
    render(<EnergyCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: /Ouvrir/ }).getAttribute('href')).toBe('https://ha.example/energy')
  })

  it('24 h par défaut, 30 jours au choix', () => {
    render(<EnergyCard card={card} result={result(data)} />)

    expect(screen.getByRole('img', { name: /24 dernières heures/ })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: '30 j' }))
    expect(screen.getByRole('img', { name: /30 derniers jours/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: '30 j' }).getAttribute('aria-pressed')).toBe('true')
  })

  it('sans solaire ni mesure du moment : pas de tuile solaire, tirets', () => {
    render(
      <EnergyCard
        card={card}
        result={result({ ...data, now: { solar: null, grid: null }, today: { solar: null, grid: 10, cost: null, saved: null }, price: null })}
      />,
    )

    expect(screen.queryByRole('group', { name: 'Solaire' })).toBeNull()
    expect(text(screen.getByRole('group', { name: 'Réseau' }))).toContain('—')
    expect(text(screen.getByRole('group', { name: 'Maison' }))).toContain('—')
  })

  it('chargement', () => {
    const { container } = render(<EnergyCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
