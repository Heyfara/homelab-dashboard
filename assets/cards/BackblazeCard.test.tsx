import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { BackblazeCard, type BackblazeData, type BackblazeProps } from './BackblazeCard'

const card: CardView<BackblazeProps> = {
  id: 'b',
  type: 'backblaze',
  title: null,
  span: 2,
  rows: 2,
  refresh: 86400,
  props: { title: 'Backblaze B2', url: 'https://secure.backblaze.com/b2_buckets.htm', staleHours: 36 },
}

const now = new Date('2026-10-20T10:00:00Z')
const data: BackblazeData = {
  buckets: [
    { name: 'sauvegardes-maison', bytes: 398_400_000_000, files: 22561, lastUpload: '2026-10-20T01:51:39+00:00' },
    { name: 'archives', bytes: 1_600_000_000, files: 12, lastUpload: '2026-10-01T00:00:00+00:00' },
  ],
  total: { bytes: 400_000_000_000, files: 22573 },
  cost: { monthly: 2.71, monthlyExclVat: 2.71, vat: 0, pricePerTb: 6.95, freeGb: 10, currency: '$' },
}
const result = (d: BackblazeData): CardResult<BackblazeData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-20T10:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('BackblazeCard', () => {
  it('coût mensuel estimé, avec le tarif utilisé', () => {
    render(<BackblazeCard card={card} result={result(data)} now={now} />)

    const cost = screen.getByRole('group', { name: 'Coût estimé' })
    expect(text(cost)).toContain('≈ 2,71 $')
    expect(text(cost)).toContain('/mois')
    expect(text(cost)).toContain('≈ 32,52 $/an · 6,95 $/To')
    expect(cost.getAttribute('title')).toContain('10 Go offerts')
  })

  it('avec TVA : montant TTC affiché, hors taxes au survol', () => {
    render(<BackblazeCard card={card} result={result({ ...data, cost: { ...data.cost, monthly: 3.25, vat: 20 } })} now={now} />)

    const cost = screen.getByRole('group', { name: 'Coût estimé' })
    expect(text(cost)).toContain('≈ 3,25 $')
    expect(text(cost)).toContain('/mois TTC')
    expect(text(cost)).toContain('≈ 39,00 $/an')
    expect((cost.getAttribute('title') ?? '').replace(/[\u00a0\u202f]/g, ' ')).toContain('2,71 $ HT + TVA 20 %')
  })

  it('sans TVA : pas de mention TTC', () => {
    render(<BackblazeCard card={card} result={result(data)} now={now} />)

    expect(text(screen.getByRole('group', { name: 'Coût estimé' }))).not.toContain('TTC')
  })

  it('volume total stocké et nombre de fichiers', () => {
    render(<BackblazeCard card={card} result={result(data)} now={now} />)

    const stored = screen.getByRole('group', { name: 'Stocké' })
    expect(text(stored)).toContain('400 Go')
    expect(text(stored)).toContain('22 573 fichiers')
  })

  it('une ligne par bucket ; un dernier envoi trop ancien est signalé', () => {
    render(<BackblazeCard card={card} result={result(data)} now={now} />)

    const [recent, old] = screen.getAllByRole('listitem')
    expect(text(recent!)).toContain('sauvegardes-maison')
    expect(text(recent!)).toContain('398,4 Go')
    expect(text(recent!)).toContain('il y a 8 h')
    expect(recent!.querySelector('[data-last-upload]')?.className).not.toContain('amber')
    expect(old!.querySelector('[data-last-upload]')?.className).toContain('amber')
  })

  it('un seul bucket : pas de liste, son nom et son dernier envoi dans la tuile ; coût annuel', () => {
    const single = { ...data, buckets: [data.buckets[0]!], total: { bytes: 398_400_000_000, files: 22561 } }
    render(<BackblazeCard card={card} result={result(single)} now={now} />)

    expect(screen.queryAllByRole('listitem')).toHaveLength(0)
    const stored = screen.getByRole('group', { name: 'Stocké' })
    expect(stored.getAttribute('title')).toContain('sauvegardes-maison')
    expect(text(stored)).toContain('22 561 fichiers')
    expect(text(stored)).toContain('il y a 8 h')
  })

  it('lien vers Backblaze', () => {
    render(<BackblazeCard card={card} result={result(data)} now={now} />)

    expect(screen.getByRole('link', { name: /Ouvrir B2/ }).getAttribute('href')).toBe(card.props.url)
  })

  it('annonce le chargement', () => {
    const { container } = render(<BackblazeCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
