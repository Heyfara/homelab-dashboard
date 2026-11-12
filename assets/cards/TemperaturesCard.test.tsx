import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { TemperaturesCard, formatTemperature, type TemperaturesData, type TemperaturesProps } from './TemperaturesCard'

const card: CardView<TemperaturesProps> = {
  id: 't',
  type: 'temperatures',
  title: null,
  span: 2,
  rows: 2,
  refresh: 300,
  props: { title: 'Températures', url: 'https://ha.example' },
}

const data: TemperaturesData = {
  outdoor: { name: 'Jardin', temperature: 9.1, humidity: 85 },
  at: ['2026-11-12T12:00:00+01:00', '2026-11-12T13:00:00+01:00'],
  rooms: [
    { area: 'salon', name: 'Salon', temperature: 20.4, humidity: 77, history: [20, 20.4], climate: null },
    { area: 'chambre_a', name: 'Chambre A', temperature: 17.6, humidity: 53.8, history: [null, 17.5], climate: { target: 18, action: 'heating', mode: 'heat' } },
    { area: 'chambre_b', name: 'Chambre B', temperature: null, humidity: null, history: [null, null], climate: null },
  ],
}
const result = (d: TemperaturesData): CardResult<TemperaturesData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-11-12T14:00:00+01:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('formatTemperature', () => {
  it('au dixième, à la française', () => {
    expect(formatTemperature(17.6)).toBe('17,6 °C')
    expect(formatTemperature(20)).toBe('20,0 °C')
  })
})

describe('TemperaturesCard', () => {
  it('une tuile par pièce, avec température et humidité', () => {
    render(<TemperaturesCard card={card} result={result(data)} />)

    const salon = screen.getByRole('group', { name: 'Salon' })
    expect(text(salon)).toContain('20,4 °C')
    expect(text(salon)).toContain('77 %')
    expect(text(screen.getByRole('group', { name: 'Chambre B' }))).toContain('—')
  })

  it('consigne et chauffe en cours', () => {
    render(<TemperaturesCard card={card} result={result(data)} />)

    const room = screen.getByRole('group', { name: 'Chambre A' })
    expect(text(room)).toContain('18 °C')
    expect(text(room)).toContain('chauffe')
  })

  it('consigne sans chauffe : pas de « chauffe »', () => {
    const idle = { ...data, rooms: [{ ...data.rooms[1]!, climate: { target: 18, action: 'idle', mode: 'heat' } }] }
    render(<TemperaturesCard card={card} result={result(idle)} />)

    expect(text(screen.getByRole('group', { name: 'Chambre A' }))).not.toContain('chauffe')
  })

  it('l’extérieur dans l’en-tête', () => {
    render(<TemperaturesCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('group', { name: 'Extérieur' }))).toContain('9,1 °C')
  })

  it('courbe 24 h quand il y a au moins deux mesures', () => {
    render(<TemperaturesCard card={card} result={result(data)} />)

    expect(screen.getByRole('img', { name: /Salon.*24 h/ })).toBeTruthy()
  })

  it('chargement', () => {
    const { container } = render(<TemperaturesCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
