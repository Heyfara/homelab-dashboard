import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { CalibreWebCard, type CalibreWebData, type CalibreWebProps } from './CalibreWebCard'

const card: CardView<CalibreWebProps> = { id: 'e', type: 'calibre-web', title: null, span: 2, rows: 2, refresh: 300, props: { title: 'Ebooks', service: 'Ebooks', url: 'https://livres.example', icon: 'sh-calibre-web' } }

const data: CalibreWebData = {
  books: [
    { id: 12, title: 'Le Phare des brumes', author: 'Jeanne Fictive', cover: '/api/cards/e/image?path=12&sig=x', link: 'https://livres.example/book/12', addedAt: new Date().toISOString() },
    { id: 7, title: 'Sans couverture', author: null, cover: null, link: 'https://livres.example/book/7', addedAt: '2020-01-01T00:00:00+00:00' },
  ],
}
const result = (d: CalibreWebData): CardResult<CalibreWebData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })

describe('CalibreWebCard', () => {
  it('derniers livres en slider, couvertures relayées, format livre', () => {
    render(<CalibreWebCard card={card} result={result(data)} />)

    const list = screen.getByRole('list', { name: 'Derniers ebooks' })
    expect(list.querySelector('li')?.className).toContain('aspect-[2/3]')
    const first = screen.getByRole('link', { name: 'Le Phare des brumes (Jeanne Fictive)' })
    expect(first.getAttribute('href')).toBe('https://livres.example/book/12')
    expect(first.querySelector('img')?.getAttribute('src')).toBe(data.books[0]!.cover)
  })

  it('sans couverture : vignette sans image, titre gardé', () => {
    render(<CalibreWebCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: 'Sans couverture' })
    expect(link.querySelector('img')).toBeNull()
    expect(link.textContent).toContain('Sans couverture')
  })

  it('« Nouveau » sur les livres de la semaine', () => {
    render(<CalibreWebCard card={card} result={result(data)} />)

    expect(screen.getAllByText('Nouveau')).toHaveLength(1)
  })

  it('aucun livre : le dit', () => {
    render(<CalibreWebCard card={card} result={result({ books: [] })} />)

    screen.getByText('Aucun livre pour l’instant.')
  })

  it('annonce le chargement', () => {
    const { container } = render(<CalibreWebCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
