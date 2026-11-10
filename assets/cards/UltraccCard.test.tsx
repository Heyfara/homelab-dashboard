import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { formatDecimalUsage, trafficOutlook, UltraccCard, type UltraccData, type UltraccProps } from './UltraccCard'

const card: CardView<UltraccProps> = {
  id: 'tom-ultracc-abc123',
  type: 'ultracc',
  title: null,
  span: 2,
  rows: 1,
  refresh: 900,
  props: { title: 'Seedbox', link: 'https://cp.ultra.example/', icon: 'sh-qbittorrent' },
}

const data: UltraccData = {
  storage: { used: 678604832768, total: 1000727379968, free: 322_122_547_200, percent: 67.81 },
  traffic: { percent: 24.5, used: 490, quota: 2000, lastReset: '2026-03-10T00:00:00+00:00', nextReset: '2026-04-10T00:00:00+00:00', projectedPercent: 75.95 },
}

const result = (d: UltraccData): CardResult<UltraccData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-03-20T00:00:00+00:00' })
const text = (el: HTMLElement) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('UltraccCard', () => {
  it('affiche le stockage utilisé et libre', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    const storage = screen.getByRole('group', { name: 'Stockage' })
    expect(screen.getByRole('progressbar', { name: /Stockage : 67,8 %/ })).toBeTruthy()
    // Go décimaux, comme le panneau d'Ultra.cc (932 Gio = 1 To).
    expect(text(storage)).toContain('678,6 Go / 1 To')
    expect(text(storage)).toContain('322,1 Go libres')
  })

  it('affiche le trafic du mois, le volume et la remise à zéro', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    const traffic = screen.getByRole('group', { name: 'Trafic du mois' })
    expect(screen.getByRole('progressbar', { name: /Trafic : 24,5 %/ })).toBeTruthy()
    expect(text(traffic)).toContain('490 Go / 2 To')
    expect(text(traffic)).toContain('≈ 76 % au 10 avr.')
  })

  it('sans projection, montre la date de remise à zéro', () => {
    render(<UltraccCard card={card} result={result({ ...data, traffic: { ...data.traffic, projectedPercent: null } })} />)

    expect(text(screen.getByRole('group', { name: 'Trafic du mois' }))).toContain('remise à zéro le 10 avr.')
  })

  it('sans quota, ne montre que le pourcentage', () => {
    render(<UltraccCard card={card} result={result({ ...data, traffic: { ...data.traffic, used: null, quota: null } })} />)

    expect(text(screen.getByRole('group', { name: 'Trafic du mois' }))).not.toContain('Go /')
  })

  it('propose d’ouvrir le panneau Ultra.cc', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: 'Ouvrir Ultra.cc →' }).getAttribute('href')).toBe('https://cp.ultra.example/')
  })

  it('affiche l’icône de la carte', () => {
    const { container } = render(<UltraccCard card={{ ...card, props: { ...card.props, icon: 'ultracc.svg' } }} result={result(data)} />)

    expect(container.querySelector('img')?.getAttribute('src')).toBe('/icons/ultracc.svg')
  })

  it('montre un squelette pendant le chargement', () => {
    const { container } = render(<UltraccCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})

describe('trafficOutlook', () => {
  it('reste vert sous 85 % projetés', () => {
    expect(trafficOutlook(24.5, 75.95, '10 avr.')).toEqual({ tone: 'ok', label: '≈ 76 % au 10 avr.' })
  })

  it('passe ambre à l’approche du quota', () => {
    expect(trafficOutlook(40, 90, '10 avr.')).toEqual({ tone: 'warn', label: '≈ 90 % au 10 avr.' })
  })

  it('annonce le bridage si la projection dépasse le quota', () => {
    expect(trafficOutlook(60, 130, '10 avr.')).toEqual({ tone: 'down', label: '≈ 130 % : bridage' })
  })

  it('sans projection (1er jour), se fie au pourcentage consommé', () => {
    expect(trafficOutlook(2, null, '10 avr.')).toEqual({ tone: 'ok', label: null })
    expect(trafficOutlook(100, null, '10 avr.')).toEqual({ tone: 'down', label: 'bridé jusqu’au 10 avr.' })
  })
})

describe('formatDecimalUsage', () => {
  it('écrit l’unité une seule fois si elle est commune', () => {
    expect(formatDecimalUsage(490e9, 2000e9)).toBe('490 Go / 2 To')
    expect(formatDecimalUsage(1200e9, 2000e9)).toBe('1,2 / 2 To')
  })
})
