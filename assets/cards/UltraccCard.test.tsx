import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { formatTrafficUsage, trafficOutlook, UltraccCard, type UltraccData, type UltraccProps } from './UltraccCard'

const card: CardView<UltraccProps> = {
  id: 'tom-ultracc-abc123',
  type: 'ultracc',
  title: null,
  span: 1,
  rows: 2,
  refresh: 900,
  props: { title: 'Seedbox', url: 'https://tom.serveur.usbx.example/qbittorrent', icon: 'sh-qbittorrent' },
}

const data: UltraccData = {
  storage: { used: 632, total: 932, free: 300, percent: 67.81 },
  traffic: { percent: 24.5, used: 490, quota: 2000, lastReset: '2026-03-10T00:00:00+00:00', nextReset: '2026-04-10T00:00:00+00:00', projectedPercent: 75.95 },
}

const result = (d: UltraccData): CardResult<UltraccData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-03-20T00:00:00+00:00' })
const text = (el: HTMLElement) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('UltraccCard', () => {
  it('affiche le stockage utilisé et libre', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    const storage = screen.getByRole('group', { name: 'Stockage' })
    expect(screen.getByRole('progressbar', { name: /Stockage : 67,8 %/ })).toBeTruthy()
    expect(text(storage)).toContain('632 / 932 Go')
    expect(text(storage)).toContain('300 Go libres')
  })

  it('affiche le trafic du mois, le volume et la remise à zéro', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    const traffic = screen.getByRole('group', { name: 'Trafic du mois' })
    expect(screen.getByRole('progressbar', { name: /Trafic : 24,5 %/ })).toBeTruthy()
    expect(text(traffic)).toContain('490 Go / 2 To')
    expect(text(traffic)).toContain('10 avr.')
    expect(text(traffic)).toContain('≈ 76 % à ce rythme')
  })

  it('sans quota, ne montre que le pourcentage', () => {
    render(<UltraccCard card={card} result={result({ ...data, traffic: { ...data.traffic, used: null, quota: null } })} />)

    expect(text(screen.getByRole('group', { name: 'Trafic du mois' }))).not.toContain('Go /')
  })

  it('lie le titre au service', () => {
    render(<UltraccCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: /Seedbox/ }).getAttribute('href')).toBe('https://tom.serveur.usbx.example/qbittorrent')
  })

  it('montre un squelette pendant le chargement', () => {
    const { container } = render(<UltraccCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})

describe('trafficOutlook', () => {
  it('reste vert sous 85 % projetés', () => {
    expect(trafficOutlook(24.5, 75.95)).toEqual({ tone: 'ok', label: '≈ 76 % à ce rythme' })
  })

  it('passe ambre à l’approche du quota', () => {
    expect(trafficOutlook(40, 90)).toEqual({ tone: 'warn', label: '≈ 90 % à ce rythme' })
  })

  it('annonce le bridage si la projection dépasse le quota', () => {
    expect(trafficOutlook(60, 130)).toEqual({ tone: 'down', label: '≈ 130 % à ce rythme : bridage probable' })
  })

  it('sans projection (1er jour), se fie au pourcentage consommé', () => {
    expect(trafficOutlook(2, null)).toEqual({ tone: 'ok', label: null })
    expect(trafficOutlook(100, null)).toEqual({ tone: 'down', label: 'quota atteint : débit bridé' })
  })
})

describe('formatTrafficUsage', () => {
  it('écrit l’unité une seule fois si elle est commune', () => {
    expect(formatTrafficUsage(490, 2000)).toBe('490 Go / 2 To')
    expect(formatTrafficUsage(1200, 2000)).toBe('1,2 / 2 To')
  })
})
