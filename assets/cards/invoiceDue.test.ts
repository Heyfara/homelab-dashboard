import { describe, expect, it } from 'vitest'
import { formatAmount, invoiceDue } from './invoiceDue'

const now = new Date(2026, 10, 16, 21, 30) // 16 nov. 2026 au soir, heure locale
const text = (s: string) => s.replace(/[\u00a0\u202f]/g, ' ')

describe('invoiceDue', () => {
  it('en retard', () => {
    expect(invoiceDue('2026-11-12', now, 7)).toEqual({ tone: 'late', label: 'en retard de 4 j', days: -4 })
  })

  it('aujourd’hui et demain comptent comme proches', () => {
    expect(invoiceDue('2026-11-16', now, 7)).toEqual({ tone: 'soon', label: 'aujourd’hui', days: 0 })
    expect(invoiceDue('2026-11-17', now, 7)).toEqual({ tone: 'soon', label: 'demain', days: 1 })
  })

  it('proche jusqu’à soon_days compris', () => {
    expect(invoiceDue('2026-11-19', now, 7)).toEqual({ tone: 'soon', label: 'dans 3 j', days: 3 })
    expect(invoiceDue('2026-11-23', now, 7).tone).toBe('soon')
    expect(invoiceDue('2026-11-24', now, 7).tone).toBe('later')
  })

  it('plus loin : date courte, avec l’année si ce n’est pas la même', () => {
    expect(invoiceDue('2026-12-12', now, 7)).toEqual({ tone: 'later', label: '12 déc.', days: 26 })
    expect(invoiceDue('2027-01-05', now, 7).label).toBe('5 janv. 2027')
  })

  it('soon_days à 0 : seul le jour même est proche', () => {
    expect(invoiceDue('2026-11-16', now, 0).tone).toBe('soon')
    expect(invoiceDue('2026-11-17', now, 0)).toEqual({ tone: 'later', label: 'demain', days: 1 })
  })

  it('le passage à l’heure d’hiver ne décale pas le compte', () => {
    expect(invoiceDue('2026-10-26', new Date(2026, 9, 24, 12), 7).days).toBe(2)
  })

  it('sans échéance', () => {
    expect(invoiceDue(null, now, 7)).toEqual({ tone: 'unknown', label: '?', days: null })
  })
})

describe('formatAmount', () => {
  it('montant en français, dans sa devise', () => {
    expect(text(formatAmount(246.5, 'EUR'))).toBe('246,50 €')
    expect(text(formatAmount(1234.5, 'EUR'))).toBe('1 234,50 €')
    expect(text(formatAmount(10, 'USD'))).toBe('10,00 $US')
  })

  it('montant inconnu', () => {
    expect(formatAmount(null, 'EUR')).toBe('?')
  })
})
