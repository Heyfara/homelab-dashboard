import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { GithubPullRequestsCard, type GithubPrData, type GithubPrProps } from './GithubPullRequestsCard'

const card: CardView<GithubPrProps> = {
  id: 'g',
  type: 'github-prs',
  title: 'Mises à jour en attente',
  span: 2,
  rows: 2,
  refresh: 300,
  props: { title: 'Pull requests', repos: ['exemple/infra', 'exemple/app'], url: 'https://github.com/search?q=x&type=pullrequests' },
}

const data: GithubPrData = {
  total: 3,
  items: [
    {
      repo: 'exemple/infra',
      number: 61,
      title: 'chore(deps): update immich-server to v2.4.0',
      url: 'https://github.com/exemple/infra/pull/61',
      author: 'renovate[bot]',
      draft: false,
      labels: [{ name: 'revue-requise', color: '#d93f0b' }],
      createdAt: '2026-10-17T06:12:00+00:00',
      updatedAt: '2026-10-19T08:00:00+00:00',
    },
    {
      repo: 'exemple/app',
      number: 12,
      title: 'chore(deps): update vite to v8.4.0',
      url: 'https://github.com/exemple/app/pull/12',
      author: 'renovate[bot]',
      draft: true,
      labels: [],
      createdAt: '2026-10-18T06:00:00+00:00',
      updatedAt: '2026-10-18T06:00:00+00:00',
    },
  ],
}

const result = (d: GithubPrData): CardResult<GithubPrData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-19T10:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('GithubPullRequestsCard', () => {
  it('titre, nombre total et lien vers la recherche sur GitHub', () => {
    render(<GithubPullRequestsCard card={card} result={result(data)} />)

    screen.getByRole('heading', { name: 'Mises à jour en attente' })
    expect(screen.getByLabelText('3 pull requests').textContent).toBe('3')
    expect(screen.getByRole('link', { name: /Tout voir/ }).getAttribute('href')).toBe(card.props.url)
  })

  it('chaque PR : lien vers la PR, paquet, version cible, numéro, étiquettes', () => {
    render(<GithubPullRequestsCard card={card} result={result(data)} />)

    const [first, second] = screen.getAllByRole('listitem')
    expect(screen.getByRole('link', { name: 'chore(deps): update immich-server to v2.4.0' }).getAttribute('href')).toBe('https://github.com/exemple/infra/pull/61')
    expect(text(first!)).toContain('immich-server')
    expect(text(first!)).toContain('→ v2.4.0')
    expect(text(first!)).not.toContain('chore(deps)')
    expect(text(first!)).toContain('#61')
    expect(text(first!)).toContain('revue-requise')
    expect(first!.querySelector('img')?.getAttribute('src')).toContain('/immich.webp')
    expect(text(second!)).toContain('Brouillon')
  })

  it('le dépôt n’apparaît que si la carte en suit plusieurs', () => {
    const { rerender } = render(<GithubPullRequestsCard card={card} result={result(data)} />)
    expect(text(screen.getAllByRole('listitem')[0]!)).toContain('infra')

    rerender(<GithubPullRequestsCard card={{ ...card, props: { ...card.props, repos: ['exemple/infra'] } }} result={result(data)} />)
    expect(text(screen.getAllByRole('listitem')[0]!)).not.toContain('infra')
  })

  it('une PR ouverte depuis plus de 7 jours est signalée', () => {
    const old = { ...data.items[0]!, createdAt: '2026-10-01T00:00:00+00:00' }
    render(<GithubPullRequestsCard card={card} result={result({ total: 1, items: [old] })} now={new Date('2026-10-19T10:00:00Z')} />)

    expect(screen.getByTitle(/Ouverte le/).className).toContain('text-amber')
  })

  it('sans PR en attente', () => {
    render(<GithubPullRequestsCard card={card} result={result({ total: 0, items: [] })} />)

    screen.getByText('Aucune pull request en attente')
  })

  it('annonce le chargement', () => {
    const { container } = render(<GithubPullRequestsCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
