import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { WeatherCard, type WeatherData, type WeatherProps } from './WeatherCard'

const card: CardView<WeatherProps> = { id: 'w', type: 'weather', title: null, span: 2, rows: 2, refresh: 1800, props: { title: 'Maison', place: 'Maison' } }

const data: WeatherData = {
  now: { time: '19:00', temperature: 13, feelsLike: 11, code: 3, isDay: false, wind: 10 },
  hours: [
    { time: '20:00', temperature: 12, code: 3, isDay: false, rainChance: 0 },
    { time: '21:00', temperature: 12, code: 2, isDay: false, rainChance: 10 },
    { time: '22:00', temperature: 11, code: 61, isDay: false, rainChance: 70 },
    { time: '23:00', temperature: 11, code: 61, isDay: false, rainChance: 80 },
  ],
  days: [
    { date: '2026-10-20', code: 80, min: 10, max: 14, rainChance: 98 },
    { date: '2026-10-21', code: 80, min: 8, max: 15, rainChance: 83 },
    { date: '2026-10-22', code: 0, min: 9, max: 16, rainChance: 0 },
  ],
  sun: { sunrise: '08:18', sunset: '18:51' },
}
const result = (d: WeatherData): CardResult<WeatherData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('WeatherCard', () => {
  it('maintenant : température, libellé, ressenti et vent', () => {
    render(<WeatherCard card={card} result={result(data)} />)

    const now = screen.getByRole('group', { name: 'Maintenant' })
    expect(text(now)).toContain('13°')
    expect(text(now)).toContain('Couvert')
    expect(text(now)).toContain('ressenti 11° · vent 10 km/h')
    expect(within(now).getByRole('img', { name: 'Couvert' }).textContent).toBe('☁️')
  })

  it('chaque heure à venir, pluie probable signalée', () => {
    render(<WeatherCard card={card} result={result(data)} />)

    const hours = screen.getByRole('list', { name: 'Prochaines heures' }).querySelectorAll('li')
    expect([...hours].map(text)).toEqual(['20 h☁️12°', '21 h☁️12°10 %', '22 h🌧️11°70 %', '23 h🌧️11°80 %'])
  })

  it('jours : nom court, icône, max / min', () => {
    render(<WeatherCard card={card} result={result(data)} />)

    const days = screen.getByRole('list', { name: 'Prochains jours' }).querySelectorAll('li')
    expect(text(days[0]!)).toContain('Auj.')
    expect(text(days[1]!)).toContain('mer.')
    expect(text(days[2]!)).toContain('16°9°')
  })

  it('lever et coucher du soleil', () => {
    render(<WeatherCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('heading').parentElement!)).toContain('08:18 · 18:51')
  })

  it('annonce le chargement', () => {
    const { container } = render(<WeatherCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
