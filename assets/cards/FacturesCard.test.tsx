import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { FacturesCard, type FacturesData, type FacturesProps } from './FacturesCard'

const card: CardView<FacturesProps> = {
  id: 'f',
  type: 'factures',
  title: null,
  span: 2,
  rows: 2,
  refresh: 3600,
  props: { title: 'Factures à payer', url: 'https://papiers.example', soonDays: 7 },
}

const invoice = (id: number, over: Partial<FacturesData['invoices'][number]> = {}): FacturesData['invoices'][number] => ({
  id,
  title: `Facture ${id}`,
  correspondent: null,
  amount: 10,
  currency: 'EUR',
  dueDate: '2026-12-20',
  created: '2026-11-01',
  url: `https://papiers.example/documents/${id}/details`,
  ...over,
})

const data: FacturesData = {
  invoices: [
    invoice(42, { title: 'Facture internet', correspondent: 'Opérateur fictif', amount: 39.99, dueDate: '2026-11-12' }),
    invoice(41, { title: 'Facture électricité', amount: 246.5, dueDate: '2026-11-19' }),
    invoice(40, { title: 'Facture eau', amount: 1000, dueDate: '2026-12-20' }),
    invoice(43, { title: 'Facture sans détails', amount: null, dueDate: null }),
  ],
  total: 1286.49,
  currency: 'EUR',
  count: 4,
  missingAmounts: 1,
}

const now = new Date(2026, 10, 16, 9)
const result = (d: FacturesData): CardResult<FacturesData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-11-16T08:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('FacturesCard', () => {
  it('en-tête : titre, nombre de factures, total à payer et lien vers Paperless', () => {
    render(<FacturesCard card={card} result={result(data)} now={now} />)

    screen.getByRole('heading', { name: 'Factures à payer' })
    expect(screen.getByLabelText('4 factures en attente').textContent).toBe('4')
    expect(text(screen.getByLabelText('Total à payer'))).toBe('1 286,49 € + ?')
    expect(screen.getByRole('link', { name: /Paperless/ }).getAttribute('href')).toBe('https://papiers.example')
  })

  it('une ligne par facture : lien, montant, échéance', () => {
    render(<FacturesCard card={card} result={result(data)} now={now} />)

    const rows = screen.getAllByRole('listitem')
    expect(rows).toHaveLength(4)
    expect(screen.getByRole('link', { name: /Facture internet/ }).getAttribute('href')).toBe('https://papiers.example/documents/42/details')
    expect(text(rows[0]!)).toContain('Opérateur fictif')
    expect(text(rows[0]!)).toContain('39,99 €')
    expect(text(rows[0]!)).toContain('en retard de 4 j')
    expect(text(rows[1]!)).toContain('dans 3 j')
    expect(text(rows[2]!)).toContain('20 déc.')
  })

  it('retard en rouge, échéance proche en ambre', () => {
    render(<FacturesCard card={card} result={result(data)} now={now} />)

    expect(screen.getByText('en retard de 4 j').className).toContain('text-rose')
    expect(screen.getByText('dans 3 j').className).toContain('text-amber')
    expect(screen.getByText('20 déc.').className).not.toMatch(/text-(rose|amber)/)
  })

  it('soon_days de la carte', () => {
    render(<FacturesCard card={{ ...card, props: { ...card.props, soonDays: 2 } }} result={result(data)} now={now} />)

    expect(screen.queryByText('dans 3 j')).toBeNull()
    expect(screen.getByText('19 nov.').className).not.toContain('text-amber')
  })

  it('montant ou échéance inconnus : « ? »', () => {
    render(<FacturesCard card={card} result={result(data)} now={now} />)

    const last = screen.getAllByRole('listitem')[3]!
    expect(text(last).match(/\?/g)).toHaveLength(2)
  })

  it('total sans « + ? » quand tous les montants sont connus', () => {
    render(<FacturesCard card={card} result={result({ ...data, invoices: data.invoices.slice(0, 1), total: 39.99, count: 1, missingAmounts: 0 })} now={now} />)

    expect(text(screen.getByLabelText('Total à payer'))).toBe('39,99 €')
    screen.getByLabelText('1 facture en attente')
  })

  it('aucune facture en attente', () => {
    render(<FacturesCard card={card} result={result({ invoices: [], total: 0, currency: 'EUR', count: 0, missingAmounts: 0 })} now={now} />)

    screen.getByText('Aucune facture en attente')
    expect(screen.queryByLabelText('Total à payer')).toBeNull()
  })

  it('annonce le chargement', () => {
    const { container } = render(<FacturesCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
