import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { formatFullIn, formatRate, StorageCard, type StorageData, type StorageProps } from './StorageCard'

const card: CardView<StorageProps> = { id: 's', type: 'storage', title: null, span: 2, rows: 1, refresh: 300, props: { title: 'NAS', system: 'Beta' } }

const data: StorageData = {
  percent: 57.57,
  used: 1055.15,
  total: 1832.68,
  free: 777.53,
  growth: { perDay: 5, fullInDays: 155.5, days: 10 },
  series: { at: ['2026-10-09T08:00:00+00:00', '2026-10-19T08:00:00+00:00'], used: [1005.15, 1055.15] },
}
const result = (d: StorageData): CardResult<StorageData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-19T08:01:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('formatRate', () => {
  it.each([
    [5, '+150 Go/mois'],
    [40, '+1,2 To/mois'],
    [-2, '−60 Go/mois'],
    [0.01, 'stable'],
  ])('%d Go/j → %s', (perDay, expected) => expect(formatRate(perDay).replace(/[  ]/g, ' ')).toBe(expected))
})

describe('formatFullIn', () => {
  it.each([
    [12, 'dans ~12 jours'],
    [155.5, 'dans ~5 mois'],
    [900, 'dans ~2 ans'],
    [5000, 'dans plus de 10 ans'],
  ])('%d j → %s', (days, expected) => expect(formatFullIn(days)).toBe(expected))
})

describe('StorageCard', () => {
  it('jauge de remplissage, utilisé, libre et total', () => {
    const { container } = render(<StorageCard card={card} result={result(data)} />)

    screen.getByRole('heading', { name: 'NAS' })
    expect(screen.getByRole('progressbar', { name: /NAS/ }).getAttribute('aria-valuenow')).toBe('58')
    expect(text(container)).toContain('1 / 1,8 To')
    expect(text(container)).toContain('777,5 Go libres')
  })

  it('rythme de croissance et date où le volume sera plein', () => {
    const { container } = render(<StorageCard card={card} result={result(data)} />)

    expect(text(container)).toContain('+150 Go/mois')
    expect(text(container)).toContain('plein dans ~5 mois')
    screen.getByRole('img', { name: /^Utilisé/ })
  })

  it('courbe à l’échelle de la capacité totale', () => {
    const { container } = render(<StorageCard card={card} result={result({ ...data, series: { at: data.series.at, used: [916.34, 916.34] } })} />)

    // 916,34 Go sur 1832,68 : la ligne à mi-hauteur du tracé (viewBox 100×40).
    const ys = (container.querySelector('polyline')?.getAttribute('points') ?? '').split(' ').map((p) => Number(p.split(',')[1]))
    expect(ys).toEqual([20, 20])
  })

  it('pas de projection quand le volume ne grossit pas', () => {
    const { container } = render(<StorageCard card={card} result={result({ ...data, growth: { perDay: -1, fullInDays: null, days: 10 } })} />)

    expect(text(container)).not.toContain('plein')
  })

  it('prévient quand le recul manque pour une tendance', () => {
    const { container } = render(<StorageCard card={card} result={result({ ...data, growth: null })} />)

    expect(text(container)).toContain('tendance après 2 jours de mesures')
  })

  it('annonce le chargement', () => {
    const { container } = render(<StorageCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
