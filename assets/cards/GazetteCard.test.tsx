import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { draftLabel, GazetteCard, issueLabel, type GazetteData, type GazetteProps } from './GazetteCard'

const card: CardView<GazetteProps> = {
  id: 'g',
  type: 'gazette',
  title: null,
  span: 1,
  rows: 2,
  refresh: 3600,
  props: { title: 'Gazette', service: 'Gazette', url: 'https://gazette.example', icon: 'https://gazette.example/build/favicon.svg' },
}

const data: GazetteData = {
  daysSince: 23,
  late: false,
  warnAfterDays: 35,
  sent: [
    { number: 3, title: 'La rentrée', month: '2026-10', sentAt: '2026-10-30T19:12:00+00:00', link: 'https://gazette.example/numeros/b2c3d4e5' },
    { number: 2, title: '', month: '2026-09', sentAt: '2026-09-28T17:40:00+00:00', link: 'https://gazette.example/numeros/a1b2c3d4' },
  ],
  draft: { number: 4, title: '', month: '2026-11', status: 'brouillon', notes: 5, link: 'https://gazette.example/numeros/c3d4e5f6' },
}
const result = (d: GazetteData): CardResult<GazetteData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('issueLabel', () => {
  it('le titre, sinon le mois', () => {
    expect(issueLabel(data.sent[0]!)).toBe('La rentrée')
    expect(issueLabel(data.sent[1]!)).toBe('septembre 2026')
  })
})

describe('draftLabel', () => {
  it('selon l’état et le nombre de notes', () => {
    expect(draftLabel(data.draft!)).toBe('n° 4 en préparation · 5 notes au carnet')
    expect(draftLabel({ ...data.draft!, notes: 1 })).toBe('n° 4 en préparation · 1 note au carnet')
    expect(draftLabel({ ...data.draft!, notes: 0 })).toBe('n° 4 en préparation · carnet vide')
    expect(draftLabel({ ...data.draft!, status: 'teste' })).toBe('n° 4 testé, à envoyer · 5 notes au carnet')
  })
})

describe('GazetteCard', () => {
  it('jours depuis le dernier envoi', () => {
    render(<GazetteCard card={card} result={result(data)} />)

    const since = screen.getByRole('group', { name: 'Depuis la dernière gazette' })
    expect(text(since)).toContain('J+23')
    expect(text(since)).toContain('n° 3')
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('signale une gazette en retard', () => {
    render(<GazetteCard card={card} result={result({ ...data, daysSince: 40, late: true })} />)

    expect(text(screen.getByRole('alert'))).toContain('J+40')
  })

  it('pas encore envoyée', () => {
    render(<GazetteCard card={card} result={result({ ...data, daysSince: null, sent: [] })} />)

    expect(screen.getByText('Pas encore envoyée')).toBeTruthy()
  })

  it('chaque numéro ouvre sa page', () => {
    render(<GazetteCard card={card} result={result(data)} />)

    const list = screen.getByRole('list', { name: 'Derniers numéros envoyés' })
    const links = within(list).getAllByRole('link')
    expect(links.map((a) => a.getAttribute('href'))).toEqual(['https://gazette.example/numeros/b2c3d4e5', 'https://gazette.example/numeros/a1b2c3d4'])
    expect(text(links[1]!)).toContain('septembre 2026')

    const draft = screen.getByRole('link', { name: /en préparation/ })
    expect(draft.getAttribute('href')).toBe('https://gazette.example/numeros/c3d4e5f6')
  })

  it('sans numéro en préparation', () => {
    render(<GazetteCard card={card} result={result({ ...data, draft: null })} />)

    expect(screen.queryByText(/en préparation/)).toBeNull()
  })

  it('icône et lien de la gazette dans le titre', () => {
    const { container } = render(<GazetteCard card={card} result={result(data)} />)

    expect(container.querySelector('header img')?.getAttribute('src')).toBe('https://gazette.example/build/favicon.svg')
    expect(screen.getByRole('link', { name: /Ouvrir/ }).getAttribute('href')).toBe('https://gazette.example')
  })

  it('chargement', () => {
    const { container } = render(<GazetteCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
