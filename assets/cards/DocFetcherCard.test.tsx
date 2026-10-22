import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { DocFetcherCard, siteStatus, type DocFetcherData } from './DocFetcherCard'

const card: CardView<{ title: string }> = { id: 'd', type: 'doc-fetcher', title: null, span: 2, rows: 2, refresh: 3600, props: { title: 'Imports Paperless' } }

const daysAgo = (d: number) => new Date(Date.now() - d * 86_400_000).toISOString()
const data: DocFetcherData = {
  schedule: 'chaque lundi à 7 h 30',
  lastRun: { startedAt: daysAgo(3), finishedAt: daysAgo(3), state: 'action', manual: false },
  stale: false,
  sites: [
    { name: 'impots', label: 'Impôts', state: 'action', checkedAt: daysAgo(2), found: null, missing: null, errors: ['appareil non reconnu, code mail requis : lancer run.sh --site impots'] },
    { name: 'paie', label: 'Paie', state: 'ok', checkedAt: daysAgo(2), found: 12, missing: 1, errors: [] },
  ],
  history: [
    { startedAt: '2026-10-05T07:30:01+02:00', state: 'ok', manual: false, imported: 0 },
    { startedAt: '2026-10-12T07:30:01+02:00', state: 'failed', manual: false, imported: 0 },
    { startedAt: '2026-10-19T07:30:01+02:00', state: 'action', manual: false, imported: 1 },
  ],
  importedTotal: 1,
  documents: [{ id: 412, title: 'Bulletin septembre 2026', created: '2026-09-30', site: 'Paie', importedAt: daysAgo(2), url: 'https://papiers.example/documents/412/details' }],
}
const result = (d: DocFetcherData): CardResult<DocFetcherData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('siteStatus', () => {
  it('résume chaque état', () => {
    expect(siteStatus(data.sites[1]!)).toBe('12 sur le site')
    expect(siteStatus({ ...data.sites[1]!, missing: 0 })).toBe('12 sur le site')
    expect(siteStatus(data.sites[0]!)).toBe('code à saisir')
    expect(siteStatus({ ...data.sites[0]!, state: 'failed' })).toBe('échec')
  })
})

describe('DocFetcherCard', () => {
  it('dernier passage et planification', () => {
    render(<DocFetcherCard card={card} result={result(data)} />)

    expect(screen.getByText(/il y a 3\sj/)).toBeTruthy()
    expect(screen.getByText(/chaque lundi à 7 h 30/)).toBeTruthy()
  })

  it('un site par ligne, avec la consigne au survol', () => {
    render(<DocFetcherCard card={card} result={result(data)} />)

    const impots = screen.getByRole('listitem', { name: 'Impôts' })
    expect(text(impots)).toContain('code à saisir')
    expect(impots.getAttribute('title')).toContain('run.sh --site impots')
    expect(text(screen.getByRole('listitem', { name: 'Paie' }))).toContain('12 sur le site')
  })

  it('site en attente d’intervention : la commande en clair', () => {
    render(<DocFetcherCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('alert'))).toContain('run.sh --site impots')
  })

  it('historique : une pastille par passage, du plus ancien au plus récent', () => {
    render(<DocFetcherCard card={card} result={result(data)} />)

    const runs = screen.getAllByRole('img', { name: /Passage du/ })
    expect(runs).toHaveLength(3)
    expect(runs[1]!.getAttribute('aria-label')).toContain('échec')
    expect(runs[2]!.getAttribute('aria-label')).toContain('1 document importé')
  })

  it('derniers documents avec lien vers Paperless', () => {
    render(<DocFetcherCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: /Bulletin septembre 2026/ })
    expect(link.getAttribute('href')).toBe('https://papiers.example/documents/412/details')
  })

  it('aucun document importé : le dit', () => {
    render(<DocFetcherCard card={card} result={result({ ...data, documents: [], importedTotal: 0 })} />)

    expect(screen.getByText('Aucun document importé récemment')).toBeTruthy()
  })

  it('cron arrêté : signalé dans l’en-tête', () => {
    render(<DocFetcherCard card={card} result={result({ ...data, stale: true, sites: [data.sites[1]!] })} />)

    expect(text(screen.getByRole('alert'))).toContain('passage automatique en retard')
  })

  it('annonce le chargement', () => {
    const { container } = render(<DocFetcherCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
