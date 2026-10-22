import { describe, expect, it } from 'vitest'
import { weather } from './weather'

describe('weather', () => {
  it('ciel clair : soleil le jour, lune la nuit', () => {
    expect(weather(0, true)).toEqual({ label: 'Ensoleillé', icon: '☀️' })
    expect(weather(0, false)).toEqual({ label: 'Ciel dégagé', icon: '🌙' })
  })

  it('codes WMO groupés', () => {
    expect(weather(3, true).label).toBe('Couvert')
    expect(weather(45, true).label).toBe('Brouillard')
    expect(weather(63, true)).toEqual({ label: 'Pluie', icon: '🌧️' })
    expect(weather(81, true)).toEqual({ label: 'Averses', icon: '🌦️' })
    expect(weather(73, true).label).toBe('Neige')
    expect(weather(95, true)).toEqual({ label: 'Orage', icon: '⛈️' })
  })

  it('code inconnu : neutre', () => {
    expect(weather(42, true)).toEqual({ label: 'Météo inconnue', icon: '🌡️' })
  })
})
